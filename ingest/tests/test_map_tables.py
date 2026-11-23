"""The map and card tables (coverage, freed ground, no recorded tenement,
recent changes) and the blocking rule they share with the diff."""
import json

import pytest

from pegwatch_ingest import db
from test_diff import DSN, conn, events, night1, runner, square, stage, ten  # noqa: F401


def go():
    return runner.run(DSN, None, False, True, None, min_row_ratio=0, check_notices=False)


def special(tenid, geom, special_in="S", extract=None):
    t = ten(tenid, "LIVE", ["DIGGER, JOE"], geom, "PROSPECTING LICENCE", **({"extract": extract} if extract else {}))
    t["properties"]["special_in"] = special_in
    return t


def test_special_prospecting_licences_do_not_reduce_uncovered_share(conn):
    # Both licences sit on E 01/3's ground: one flagged by special_in, one only by its ID.
    over = [special("P 01/20-S", square(120.2, -30, w=0.005)),
            special("P 01/21S", square(120.205, -30, w=0.005), special_in=" ")]
    stage(conn, night1() + over); go()
    stage(conn, [f for f in night1() if f["properties"]["fmt_tenid"] not in ("E 01/3", "E 01/4")] + over); go()
    death = {e[1]: e[2] for e in events(conn) if e[0] == "death"}["E 01/3"]
    assert float(death["covered_any_share"]) == 1.0
    assert float(death["uncovered_share"]) == 1.0


def test_coverage_is_blocking_ground_by_status_and_type(conn):
    spl = special("P 01/20-S", square(121.5, -30))
    stage(conn, night1() + [spl]); go()
    rows = conn.execute("select status, type_group, sum(ST_Area(geom::geography)) / 1e4,"
                        " bool_or(ST_Intersects(geom, ST_MakeEnvelope(121.5, -30, 121.51, -29.99, 4326)))"
                        " from coverage_lowzoom group by 1, 2").fetchall()
    cover = {(r[0], r[1]): r[2] for r in rows}
    assert set(cover) == {("LIVE", "E"), ("PENDING", "E"), ("LIVE", "P")}  # no L, no special P
    one = conn.execute("select ST_Area(ST_GeomFromGeoJSON(%s)::geography) / 1e4",
                       (json.dumps(square(120.1, -30)),)).fetchone()[0]
    assert cover[("LIVE", "P")] == pytest.approx(2 * one, rel=0.01)  # P 01/2 and P 01/5
    assert not any(r[3] for r in rows)


def test_a_failing_map_table_keeps_last_nights_rows(conn, monkeypatch):
    stage(conn, night1()); go()
    before = conn.execute("select count(*) from coverage_lowzoom").fetchone()[0]
    conn.commit()
    real = db.sql_file
    monkeypatch.setattr(db, "sql_file", lambda name: "delete from coverage_lowzoom; select 1/0"
                        if name == "coverage_lowzoom.sql" else real(name))
    stage(conn, night1()); s = go()
    assert s["run"]["status"] == "ok"
    assert "division by zero" in s["run"]["notes"]["map_tables"]["coverage_lowzoom"]["error"]
    assert conn.execute("select count(*) from coverage_lowzoom").fetchone()[0] == before > 0
