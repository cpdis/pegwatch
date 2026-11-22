"""Mining Notices RSS: the department's same-day feed of advertised applications.

Used as an independent cross-check of the SLIP live layer (does every
application lodged before the extract time show up?) and to measure lag.
Feed found linked from local-government pages; it is not advertised on
wa.gov.au. Items carry received time (AWST), area, shire and applicants.
"""
from __future__ import annotations

import datetime as dt
import html
import logging
import re
import xml.etree.ElementTree as ET

import requests

from .slip import USER_AGENT

log = logging.getLogger(__name__)

RSS_URL = "https://emits.dmp.wa.gov.au/emits/advert/rss.xml"
AWST = dt.timezone(dt.timedelta(hours=8))

SCHEMA = """
create table if not exists mining_notices (
  fmt_tenid    text primary key,
  received_at  timestamptz,
  area_text    text,
  shire        text,
  applicants   text[],
  link         text,
  first_seen_run bigint,
  first_seen_at timestamptz not null default now()
);
"""


def _cell(desc: str, label: str) -> str | None:
    m = re.search(rf"{label}:\s*</td>\s*<td[^>]*>(.*?)</td>", desc, re.S | re.I)
    return m.group(1) if m else None


def parse(xml_text: str) -> list[dict]:
    root = ET.fromstring(xml_text)
    out = []
    for item in root.iter("item"):
        title = (item.findtext("title") or "").strip()
        desc = html.unescape(item.findtext("description") or "")
        received = _cell(desc, "Application received on")
        received_at = None
        if received:
            try:
                received_at = dt.datetime.strptime(received.strip(), "%d/%m/%Y %H:%M:%S").replace(tzinfo=AWST)
            except ValueError:
                log.warning("unparsed received date %r for %s", received, title)
        applicants_raw = _cell(desc, "Applicants") or ""
        applicants = [a.strip() for a in re.split(r"<br\s*/?>", applicants_raw) if a.strip()]
        out.append({
            "fmt_tenid": re.sub(r"\s+", " ", title),
            "received_at": received_at,
            "area_text": (_cell(desc, "Area") or "").strip() or None,
            "shire": (_cell(desc, "Shire") or "").strip() or None,
            "applicants": applicants,
            "link": (item.findtext("link") or "").strip() or None,
        })
    return out


def fetch(session: requests.Session | None = None) -> list[dict]:
    s = session or requests.Session()
    r = s.get(RSS_URL, headers={"User-Agent": USER_AGENT}, timeout=60)
    r.raise_for_status()
    return parse(r.text)


def store(conn, run_id: int, items: list[dict]) -> int:
    with conn.cursor() as cur:
        cur.execute(SCHEMA)
        n = 0
        for it in items:
            cur.execute(
                "insert into mining_notices (fmt_tenid, received_at, area_text, shire, applicants, link, first_seen_run)"
                " values (%(fmt_tenid)s, %(received_at)s, %(area_text)s, %(shire)s, %(applicants)s, %(link)s, %(run)s)"
                " on conflict (fmt_tenid) do nothing",
                {**it, "run": run_id})
            n += cur.rowcount
    conn.commit()
    return n


CROSSCHECK_SQL = """
-- The live layer's extract (stamped about 4am AWST) holds lodgements up to the
-- end of the previous day, so the cutoff is local midnight on the extract date.
with x as (select (date_trunc('day', max(extract_at) at time zone 'Australia/Perth')
                   at time zone 'Australia/Perth') as extract_at from tenements),
n as (
  select m.*, (m.received_at <= x.extract_at) as before_extract,
         exists (select 1 from tenements t where t.fmt_tenid = m.fmt_tenid) as in_live,
         exists (select 1 from dead_tenements d where d.fmt_tenid = m.fmt_tenid) as in_dead
  from mining_notices m, x
)
select count(*) filter (where before_extract) as lodged_before_extract,
       count(*) filter (where before_extract and (in_live or in_dead)) as matched,
       count(*) filter (where not before_extract) as lodged_after_extract,
       count(*) filter (where not before_extract and in_live) as after_extract_but_present,
       coalesce(array_agg(fmt_tenid order by received_at) filter (
         where before_extract and not in_live and not in_dead), '{}') as missing
from n
"""


def crosscheck(conn) -> dict:
    with conn.cursor() as cur:
        cur.execute(SCHEMA)
        cur.execute(CROSSCHECK_SQL)
        cols = [c.name for c in cur.description]
        row = dict(zip(cols, cur.fetchone()))
    conn.commit()
    return row
