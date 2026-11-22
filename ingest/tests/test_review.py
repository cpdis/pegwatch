"""Regressions for the interrogate review findings (2026-11-22)."""
import datetime as dt

from pegwatch_ingest import db
from pegwatch_ingest.layers import AMALGAMATION_PENDING
from tests.test_diff import (DAY, DSN, EXTRACT_1, EXTRACT_2, MIDNIGHT_18_NOV, conn, dead, events,  # noqa: F401
                             night1, release, runner, square, stage, ten)


def go(**kw):
    return runner.run(DSN, None, False, True, None, min_row_ratio=0, check_notices=False, **kw)


def rows(conn, sql):
    with conn.cursor() as cur:
        cur.execute(sql)
        return cur.fetchall()


def test_restored_tenement_dying_again_does_not_take_the_old_death(conn):
    old = dead("E 01/3", "Forfeited", square(120.2, -30), ended=MIDNIGHT_18_NOV - 3000 * DAY)
    stage(conn, night1(), dead_rows=[old]); go()
    stage(conn, [f for f in night1() if f["properties"]["fmt_tenid"] != "E 01/3"]); go()
    death = [e for e in events(conn) if e[0] == "death"][0][2]
    assert death["death_reason"] is None


def test_a_tenement_back_after_a_gap_is_reappeared_not_an_application(conn):
    stage(conn, night1()); go()
    stage(conn, [f for f in night1() if f["properties"]["fmt_tenid"] != "E 01/1"]); go()
    stage(conn, night1()); go()
    ev = [(e[0], e[1]) for e in events(conn)]
    assert ("reappeared", "E 01/1") in ev and ("application", "E 01/1") not in ev
    death = [e for e in events(conn) if e[0] == "death"][0][2]
    assert "reversed_by_run" in death


def test_same_type_relodgement_is_not_a_conversion(conn):
    stage(conn, night1()); go()
    n2 = [f for f in night1() if f["properties"]["fmt_tenid"] != "E 01/3"]
    n2.append(ten("E 01/99", "PENDING", ["A LTD"], square(120.2, -30), extract=EXTRACT_2))
    stage(conn, n2); go()
    death = [e for e in events(conn) if e[0] == "death" and e[1] == "E 01/3"][0][2]
    assert death["kind"] == "ended" and death["converted_to"] is None


def test_amalgamation_is_read_from_the_receiving_licence(conn):
    # Layer 20: E 01/4 receives a parcel covering E 01/3. E 01/3 dies into it;
    # E 01/4's own unrelated death would not be "amalgamated".
    parcel = {"type": "Feature", "geometry": square(120.2, -30), "properties": {
        "objectid": 1, "tenid": "E 01/4".ljust(11), "fmt_tenid": "E 01/4", "ten_type": "E", "amalgid": "AM1",
        "amalg_status": "P", "start_stamp": None, "end_stamp": None, "ge_num": None}}
    for tenements in (night1(), [f for f in night1() if f["properties"]["fmt_tenid"] not in ("E 01/3", "E 01/10")]):
        stage(conn, tenements)
        db.create_staging(conn, AMALGAMATION_PENDING)
        db.load_features(conn, AMALGAMATION_PENDING, [parcel])
        go()
    d = {e[1]: e[2] for e in events(conn) if e[0] == "death"}
    assert d["E 01/3"]["kind"] == "amalgamated" and d["E 01/3"]["amalgamation_id"] == "AM1 into E 01/4"
    assert d["E 01/10"]["kind"] == "ended"


def test_duplicate_or_reordered_holders_are_not_a_holder_change(conn):
    stage(conn, [ten("E 01/1", "LIVE", ["A LTD", "B LTD"], square(120, -30))]); go()
    stage(conn, [ten("E 01/1", "LIVE", ["B LTD", "A LTD", "A LTD"], square(120, -30))]); go()
    assert [e for e in events(conn) if e[0] == "holder_change"] == []


def test_placeholder_dates_are_null(conn):
    t = ten("E 01/1", "PENDING", ["A LTD"], square(120, -30))
    t["properties"]["enddate"] = 32503593600000  # 2999-12-31
    t["properties"]["grantdate"] = 32503593600000
    stage(conn, [t]); go()
    assert rows(conn, "select granted_at, ends_at from tenements") == [(None, None)]


def test_dead_layer_rows_per_holder_are_folded_with_real_times(conn):
    a = dead("E 01/3", "Surrendered", square(120.2, -30))
    b = dead("E 01/3", "Surrendered", square(120.2, -30))
    b["properties"]["holdername"] = "OTHER LTD"
    b["properties"]["objectid"] += 1
    stage(conn, night1(), dead_rows=[a, b]); go()
    (holders, ended), = rows(conn, "select holders, ended_at from dead_tenements")
    assert holders == ["OTHER LTD", "SOMEONE PTY LTD"]
    assert ended == dt.datetime(2026, 11, 19, 10, 0)


def test_moved_release_is_reported_and_an_empty_release_layer_aborts(conn):
    t1 = int((dt.datetime.now(dt.UTC) + dt.timedelta(days=14)).timestamp() * 1000)
    stage(conn, night1(), releases=[release("E 01/9", "R1", square(120.6, -30), t1)]); go()
    stage(conn, night1(), releases=[release("E 01/9", "R1", square(120.6, -30), t1 + 30 * DAY)]); go()
    moved = [e for e in events(conn) if e[0] == "release_scheduled"]
    assert len(moved) == 1 and "rescheduled_from" in moved[0][2]
    stage(conn, night1(), releases=[])
    try:
        go()
        raise AssertionError("expected abort")
    except runner.IngestAborted:
        pass
    assert [e for e in events(conn) if e[0] in ("released", "release_cancelled")] == []
