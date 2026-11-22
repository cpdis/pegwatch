"""End-to-end diff tests on synthetic snapshots, against a real PostGIS.

Needs TEST_DATABASE_URL pointing at a database the tests may wipe
(default postgresql:///pegwatch_test).
"""
import datetime as dt
import os

import psycopg
import pytest

from pegwatch_ingest import db, run as runner
from pegwatch_ingest.layers import NIGHTLY, TENEMENTS, DEAD, RELEASE_PENDING

DSN = os.environ.get("TEST_DATABASE_URL", "postgresql:///pegwatch_test")
EXTRACT_1 = 1795061465000  # 2026-11-19 04:11:05 UTC
EXTRACT_2 = EXTRACT_1 + 86_400_000
DAY = 86_400_000
MIDNIGHT_18_NOV = 1795046400000 - DAY  # 2026-11-18 00:00 UTC (local date encoding)


def square(x, y, w=0.01, h=0.01):
    return {"type": "Polygon", "coordinates": [[[x, y], [x + w, y], [x + w, y + h], [x, y + h], [x, y]]]}


def ten(tenid, status, holders, geom, type_="EXPLORATION LICENCE", extract=EXTRACT_1, start=MIDNIGHT_18_NOV):
    props = {"oid": abs(hash(tenid)) % 10**8, "tenid": tenid.ljust(11), "fmt_tenid": tenid,
             "type": type_, "tenstatus": status, "survstatus": "UNSURVEYED", "holdercnt": len(holders),
             "legal_area": 100, "unit_of_me": "HA.", "special_in": " ", "extract_da": extract,
             "grantdate": None, "granttime": None, "startdate": start, "starttime": "09:15:00",
             "enddate": None, "endtime": None}
    for i in range(1, 10):
        props[f"holder{i}"] = holders[i - 1] if i <= len(holders) else " "
    return {"type": "Feature", "properties": props, "geometry": geom}


def dead(tenid, reason, geom, ended=MIDNIGHT_18_NOV + DAY):
    return {"type": "Feature", "geometry": geom, "properties": {
        "objectid": abs(hash(tenid)) % 10**8, "tenid": tenid, "fmt_tenid": tenid, "type": "x",
        "survstatus": "x", "tenstatus": "Dead", "deathreason": reason, "holdercnt": 1,
        "holdername": "SOMEONE PTY LTD", "startdate": None, "enddate": ended, "endtime": "10:00:00",
        "grantdate": None, "extract_date": EXTRACT_2}}


def release(tenid, rlesid, geom, stamp):
    return {"type": "Feature", "geometry": geom, "properties": {
        "objectid": 1, "tenid": tenid.ljust(11), "fmt_tenid": tenid, "ten_type": "E  ", "rlesid": rlesid,
        "rles_order": "3", "rles_status": "P", "rles_stamp": stamp}}


def night1():
    return [
        ten("E 01/1", "PENDING", ["X PTY LTD"], square(120.00, -30)),
        ten("P 01/2", "LIVE", ["SMITH, JOHN"], square(120.10, -30), "PROSPECTING LICENCE"),
        ten("E 01/3", "LIVE", ["A LTD"], square(120.20, -30)),
        ten("E 01/4", "LIVE", ["B LTD"], square(120.20, -30)),
        ten("P 01/5", "LIVE", ["Y LTD"], square(120.30, -30), "PROSPECTING LICENCE"),
        ten("E 01/7", "PENDING", ["G LTD"], square(120.40, -30)),
        ten("E 01/8", "LIVE", ["Z PTY LTD"], square(120.50, -30)),
        ten("E 01/9", "LIVE", ["I LTD"], square(120.60, -30)),
        ten("E 01/10", "LIVE", ["J LTD"], square(120.70, -30)),
        ten("L 01/12", "LIVE", ["K LTD"], square(120.70, -30), "MISCELLANEOUS LICENCE"),
    ]


def night2():
    keep = {f["properties"]["fmt_tenid"]: f for f in night1()}
    for t in ("P 01/2", "E 01/3", "P 01/5", "E 01/10"):
        keep.pop(t)
    out = []
    for tid, f in keep.items():
        f["properties"]["extract_da"] = EXTRACT_2
        out.append(f)
    by = {f["properties"]["fmt_tenid"]: f for f in out}
    by["E 01/7"]["properties"]["tenstatus"] = "LIVE"
    by["E 01/8"]["properties"]["holder1"] = "W PTY LTD"
    by["E 01/9"]["geometry"] = square(120.60, -30, w=0.005)
    out.append(ten("M 01/6", "PENDING", ["Y LTD"], square(120.30, -30), "MINING LEASE", EXTRACT_2))
    out.append(ten("E 01/11", "PENDING", ["NEW PTY LTD"], square(121.0, -30), extract=EXTRACT_2))
    return out


@pytest.fixture()
def conn():
    with psycopg.connect(DSN, autocommit=True) as c:
        c.execute("drop schema if exists public cascade; create schema public;")
    c = db.connect(DSN)
    db.apply_schema(c)
    yield c
    c.close()


def stage(conn, tenements, dead_rows=(), releases=()):
    for layer in NIGHTLY:
        db.create_staging(conn, layer)
    db.load_features(conn, TENEMENTS, tenements)
    db.load_features(conn, DEAD, dead_rows)
    db.load_features(conn, RELEASE_PENDING, releases)


def events(conn):
    with conn.cursor() as cur:
        cur.execute("select event_type, fmt_tenid, details from tenement_events order by id")
        return cur.fetchall()


def test_baseline_then_diff(conn):
    stage(conn, night1())
    s1 = runner.run(DSN, None, False, True, None, min_row_ratio=0, check_notices=False)
    assert s1["run"]["status"] == "baseline"
    assert events(conn) == []

    future = int((dt.datetime.now(dt.UTC) + dt.timedelta(days=14)).timestamp() * 1000)
    stage(conn, night2(),
          dead_rows=[dead("P 01/2", "Surrendered", square(120.10, -30)),
                     dead("E 01/3", "Withdrawn", square(120.20, -30)),
                     dead("E 01/10", "Expired", square(120.70, -30))],
          releases=[release("E 01/9", "RN26/200", square(120.605, -30, w=0.005), future)])
    s2 = runner.run(DSN, None, False, True, None, min_row_ratio=0, check_notices=False)
    assert s2["run"]["status"] == "ok"

    ev = {}
    for etype, tid, details in events(conn):
        ev.setdefault((etype, tid), details)

    # New applications, including the conversion target
    assert ("application", "E 01/11") in ev
    assert ("application", "M 01/6") in ev

    # Deaths
    b = ev[("death", "P 01/2")]
    assert b["death_reason"] == "Surrendered" and b["kind"] == "ended"
    assert float(b["uncovered_share"]) == pytest.approx(1.0, abs=1e-3)

    c = ev[("death", "E 01/3")]
    assert c["death_reason"] == "Withdrawn"
    assert float(c["uncovered_share"]) == pytest.approx(0.0, abs=1e-3)
    assert float(c["covered_any_share"]) == pytest.approx(1.0, abs=1e-3)

    f = ev[("death", "P 01/5")]
    assert f["kind"] == "converted" and f["converted_to"] == "M 01/6"
    assert f["death_reason"] is None  # not in the DEAD layer yet

    j = ev[("death", "E 01/10")]
    # Under a miscellaneous licence only: covered by something, but not blocked
    assert float(j["covered_any_share"]) == pytest.approx(1.0, abs=1e-3)
    assert float(j["uncovered_share"]) == pytest.approx(1.0, abs=1e-3)

    assert ev[("grant", "E 01/7")] == {"from": "PENDING", "to": "LIVE", "ends_at": None}
    h = ev[("holder_change", "E 01/8")]
    assert h["added"] == ["W PTY LTD"] and h["removed"] == ["Z PTY LTD"]

    shrink = ev[("shrink", "E 01/9")]
    assert float(shrink["area_ha_after"]) == pytest.approx(float(shrink["area_ha_before"]) / 2, rel=0.01)
    assert ("release_scheduled", "E 01/9") in ev

    # Nothing spurious on untouched tenements
    touched = {tid for _, tid in ev}
    assert "E 01/1" not in touched and "E 01/4" not in touched and "L 01/12" not in touched


def test_short_fetch_aborts(conn):
    stage(conn, night1())
    runner.run(DSN, None, False, True, None, check_notices=False)
    stage(conn, night1()[:5])
    with pytest.raises(runner.IngestAborted):
        runner.run(DSN, None, False, True, None, check_notices=False)
    with conn.cursor() as cur:
        cur.execute("select count(*) from tenements")
        assert cur.fetchone()[0] == 10  # snapshot untouched
        cur.execute("select status from ingest_runs order by id desc limit 1")
        assert cur.fetchone()[0] == "failed"


def test_death_reason_backfill(conn):
    stage(conn, night1())
    runner.run(DSN, None, False, True, None, min_row_ratio=0, check_notices=False)
    stage(conn, night2())  # deaths, but DEAD layer has not caught up
    runner.run(DSN, None, False, True, None, min_row_ratio=0, check_notices=False)
    stage(conn, night2(), dead_rows=[dead("P 01/2", "Surrendered", square(120.10, -30))])
    runner.run(DSN, None, False, True, None, min_row_ratio=0, check_notices=False)
    with conn.cursor() as cur:
        cur.execute("select details->>'death_reason' from tenement_events"
                    " where event_type = 'death' and fmt_tenid = 'P 01/2'")
        assert cur.fetchall() == [("Surrendered",)]


def test_company_detection(conn):
    with conn.cursor() as cur:
        cur.execute("select pw_is_company('SMITH, JOHN'), pw_is_company('NORTHERN STAR RESOURCES LIMITED'),"
                    " pw_is_company('ACME PTY LTD'), pw_is_company('BROWN, MARY ANNE')")
        assert cur.fetchone() == (False, True, True, False)
