"""ABS boundaries: WA land for the "no recorded tenement" layer, and places for search.

Landgate's SLIP localities, townsites, LGAs and coastline are under its
Personal Use Licence, which a paid product cannot rely on, so these come from
the ABS Australian Statistical Geography Standard, Edition 3, under CC BY 4.0:

- STE 2021 (states): WA land, table wa_land.
- UCL 2021 (urban centres and localities): towns, places of kind 'town'.
- SAL 2021 (suburbs and localities): gazetted localities, kind 'locality'.
- LGA 2025 (local government areas): each place's shire or city.

The files are zipped shapefiles (about 200 MB together), read here with the
standard library. They change once per ASGS edition, so the ingest refreshes
them weekly, or when a table is empty.
"""
from __future__ import annotations

import io
import json
import logging
import struct
import time
import zipfile
from collections.abc import Iterator

import requests

from . import db
from .slip import USER_AGENT

log = logging.getLogger(__name__)

BASE_URL = ("https://www.abs.gov.au/statistics/standards/australian-statistical-geography-standard-asgs/"
            "edition-3-july-2021-june-2026/access-and-downloads/digital-boundary-files")
SOURCES = {  # stg_boundaries.source -> zip on the ABS page
    "ste": "STE_2021_AUST_SHP_GDA2020.zip",
    "ucl": "UCL_2021_AUST_GDA2020_SHP.zip",
    "sal": "SAL_2021_AUST_GDA2020_SHP.zip",
    "lga": "LGA_2025_AUST_GDA2020.zip",
}
WA = "5"  # STE_CODE21
REFRESH_DAYS = 7


def _dbf_rows(data: bytes) -> Iterator[dict | None]:
    """Attribute rows of a dBASE file, None for a deleted row."""
    count, header_len, record_len = struct.unpack_from("<IHH", data, 4)
    fields, pos = [], 32
    while data[pos] != 0x0D:
        fields.append((data[pos:pos + 11].split(b"\0")[0].decode("ascii"), data[pos + 16]))
        pos += 32
    for i in range(count):
        pos = header_len + i * record_len
        if data[pos] == 0x2A:  # '*'
            yield None
            continue
        pos += 1
        row = {}
        for name, length in fields:
            row[name] = data[pos:pos + length].decode("utf-8", "replace").strip()
            pos += length
        yield row


def _shp_rings(data: bytes) -> Iterator[bytes | None]:
    """Each polygon record's rings as a WKB MultiLineString, None for other shapes.

    Shapefile points are little-endian x, y doubles, as in WKB, so each ring is
    copied as is. PostGIS rebuilds the polygons (ST_BuildArea), which saves
    working out here which ring is a hole.
    """
    pos = 100
    while pos < len(data):
        words = struct.unpack_from(">i", data, pos + 4)[0]
        body, pos = pos + 8, pos + 8 + 2 * words
        if struct.unpack_from("<i", data, body)[0] != 5:  # 5 = polygon
            yield None
            continue
        nparts, npoints = struct.unpack_from("<ii", data, body + 36)
        parts = (*struct.unpack_from(f"<{nparts}i", data, body + 44), npoints)
        points = body + 44 + 4 * nparts
        wkb = bytearray(struct.pack("<BII", 1, 5, nparts))
        for a, b in zip(parts, parts[1:]):
            wkb += struct.pack("<BII", 1, 2, b - a) + data[points + 16 * a:points + 16 * b]
        yield bytes(wkb)


def read_shapefile(data: bytes) -> Iterator[tuple[dict, bytes]]:
    """(attributes, rings) for each polygon in a zipped shapefile."""
    with zipfile.ZipFile(io.BytesIO(data)) as z:
        member = {n.rsplit(".", 1)[-1].lower(): n for n in z.namelist()}
        shp, dbf = z.read(member["shp"]), z.read(member["dbf"])
    for attrs, rings in zip(_dbf_rows(dbf), _shp_rings(shp), strict=True):
        if attrs is not None and rings is not None:
            yield attrs, rings


def download(name: str) -> bytes:
    r = requests.get(f"{BASE_URL}/{name}", headers={"User-Agent": USER_AGENT}, timeout=300)
    r.raise_for_status()
    return r.content


def due(conn) -> bool:
    return db.scalar(conn, """
        select not exists (select 1 from places) or not exists (select 1 from wa_land)
            or not exists (select 1 from ingest_runs
                           where notes->'boundaries' ? 'places' and started_at > now() - make_interval(days => %s))
        """, (REFRESH_DAYS,))


def refresh(conn, run_id: int, fetch=None) -> dict:
    """Stage WA's boundaries from each ABS zip, then rebuild wa_land and places in one transaction."""
    fetch = fetch or download
    start = time.monotonic()
    with conn.cursor() as cur:
        cur.execute("drop table if exists stg_boundaries")
        cur.execute("create unlogged table stg_boundaries (source text, props jsonb, wkb bytea)")
        for source, name in SOURCES.items():
            with cur.copy("copy stg_boundaries (source, props, wkb) from stdin") as copy:
                for attrs, rings in read_shapefile(fetch(name)):
                    if attrs.get("STE_CODE21") == WA:
                        copy.write_row((source, json.dumps(attrs), rings))
    db.run_script(conn, "boundaries.sql", run_id)
    summary = {"places": db.scalar(conn, "select count(*) from places"),
               "land_pieces": db.scalar(conn, "select count(*) from wa_land"),
               "seconds": round(time.monotonic() - start, 1)}
    conn.commit()
    log.info("ABS boundaries: %(places)d places, %(land_pieces)d land pieces in %(seconds).1fs", summary)
    return summary
