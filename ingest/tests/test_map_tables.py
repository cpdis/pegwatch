"""The map and card tables (coverage, freed ground, no recorded tenement,
recent changes) and the blocking rule they share with the diff."""
import datetime as dt
import json
from zoneinfo import ZoneInfo

import pytest

from pegwatch_ingest import db
from pegwatch_ingest.layers import FILE_NOTATION, SECTION_19
from test_diff import DSN, EXTRACT_2, conn, dead, events, night1, runner, square, stage, ten  # noqa: F401

AWST = ZoneInfo("Australia/Perth")


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


def test_recent_changes_keep_each_tenements_latest_unreversed_event(conn):
    def night(*, without=(), changed=False):
        out = [f for f in night1() if f["properties"]["fmt_tenid"] not in without]
        for f in out:
            p = f["properties"]
            if changed and p["fmt_tenid"] == "E 01/7":
                p["tenstatus"], p["holder1"] = "LIVE", "NEW LTD"  # a grant and a transfer in one run
            if changed and p["fmt_tenid"] == "E 01/8":
                p["holder1"] = "W PTY LTD"
        return out

    stage(conn, night()); go()
    stage(conn, night(without=("E 01/1",), changed=True)); go()  # E 01/1 dies...
    stage(conn, night(changed=True)); go()                        # ...and is back: the death is reversed
    latest = dict(conn.execute("select tenid, event_type from recent_changes").fetchall())
    assert latest == {"E 01/1": "reappeared", "E 01/7": "holder_change", "E 01/8": "holder_change"}

    conn.execute("update tenement_events set created_at = created_at - interval '31 days'"
                 " where tenid in ('E 01/1', 'E 01/8') and event_type <> 'death'")
    conn.commit()
    stage(conn, night(changed=True)); go()
    assert dict(conn.execute("select tenid, event_type from recent_changes").fetchall()) == {
        "E 01/7": "holder_change"}  # E 01/1's reversed death is not its latest change


def days_ago(n):
    """A SLIP date n days back: the local date encoded as UTC midnight."""
    day = dt.datetime.now(AWST).date() - dt.timedelta(days=n)
    return int(dt.datetime.combine(day, dt.time(), dt.UTC).timestamp() * 1000)


def freed(conn):
    return {r[0]: r[1:] for r in conn.execute(
        "select fmt_tenid, reason, holder, round(uncovered_ha::numeric) from freed_ground")}


def test_freed_ground_is_recent_dead_ground_nothing_blocks(conn):
    one = round(conn.execute("select ST_Area(ST_GeomFromGeoJSON(%s)::geography) / 1e4",
                             (json.dumps(square(120.1, -30)),)).fetchone()[0])
    old_l = ten("L 01/13", "LIVE", ["K LTD"], square(121.3, -30), "MISCELLANEOUS LICENCE")
    stage(conn, night1() + [old_l]); go()
    gone = ("P 01/2", "E 01/3", "E 01/10", "L 01/13")
    spl = special("P 01/20-S", square(120.10, -30), extract=EXTRACT_2)
    stage(conn, [f for f in night1() if f["properties"]["fmt_tenid"] not in gone] + [spl],
          dead_rows=[dead("P 01/2", "Surrendered", square(120.10, -30), ended=days_ago(3)),
                     dead("E 01/3", "Withdrawn", square(120.20, -30), ended=days_ago(3)),
                     dead("L 01/13", "Expired", square(121.3, -30), ended=days_ago(3)),
                     dead("E 99/1", "Expired", square(122, -30), ended=days_ago(120))])
    go()
    # P 01/2: only a special prospecting licence over it. E 01/10: only a miscellaneous
    # licence, and not in the DEAD layer yet. E 01/3 stays under E 01/4; a dead L freed
    # nothing; E 99/1 died too long ago.
    assert freed(conn) == {"P 01/2": ("Surrendered", "SOMEONE PTY LTD", one),
                           "E 01/10": (None, "J LTD", one)}

    pegged = ten("E 01/30", "PENDING", ["NEW PTY LTD"], square(120.10, -30, w=0.007), extract=EXTRACT_2)
    stage(conn, [f for f in night1() if f["properties"]["fmt_tenid"] not in gone] + [spl, pegged]); go()
    assert freed(conn)["P 01/2"][2] == round(0.3 * one)  # the new application took 70%
    pegged["geometry"] = square(120.10, -30)
    stage(conn, [f for f in night1() if f["properties"]["fmt_tenid"] not in gone] + [spl, pegged]); go()
    assert "P 01/2" not in freed(conn)


def test_no_recorded_tenement_is_land_nothing_blocks_or_closes(conn):
    def land(kind_id, geom):
        return {"type": "Feature", "geometry": geom, "properties": {
            "objectid": 1, "land_id": kind_id, "fmt_land_i": kind_id, "type": "x", "status": "ACTIVE",
            "purpose": "x", "official_name": "x", "official_n": "x", "startdate": None, "enddate": None,
            "extract_date": EXTRACT_2, "extract_da": EXTRACT_2}}

    def covered(conn, x, y):
        return conn.execute("select exists (select 1 from no_recorded_tenement where ST_Intersects("
                            "geom, ST_Point(%s, %s, 4326)))", (x + 0.005, y + 0.005)).fetchone()[0]

    conn.execute("insert into wa_land select ST_Multi(ST_MakeEnvelope(119.9, -30.1, 121.6, -29.9, 4326))")
    conn.commit()
    extra = [special("P 01/20-S", square(121.2, -30)),
             ten("L 01/14", "LIVE", ["K LTD"], square(121.3, -30), "MISCELLANEOUS LICENCE")]
    stage(conn, night1() + extra)
    db.load_features(conn, SECTION_19, [land("S19 1", square(121.4, -30))])
    db.load_features(conn, FILE_NOTATION, [land("FNA 1", square(121.5, -30))])
    go()
    assert not covered(conn, 120.0, -30)       # a pending exploration licence
    assert not covered(conn, 120.1, -30)       # a live prospecting licence
    assert covered(conn, 121.2, -30)           # a special prospecting licence does not block
    assert covered(conn, 121.3, -30)           # nor does a miscellaneous licence
    assert not covered(conn, 121.4, -30)       # s19 closes land to mining
    assert covered(conn, 121.5, -30)           # a file notation is a notation
    assert covered(conn, 121.0, -30)           # nothing there
    assert not covered(conn, 122.0, -30)       # off the land
    area = conn.execute("select sum(ST_Area(geom)) from no_recorded_tenement").fetchone()[0]
    # Eight squares of night 1 hold blocking tenements (two hold two each); one more is s19.
    assert area == pytest.approx(1.7 * 0.2 - 9 * 0.0001, rel=1e-9)
