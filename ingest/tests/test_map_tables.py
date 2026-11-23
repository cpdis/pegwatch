"""The map and card tables (coverage, freed ground, no recorded tenement,
recent changes) and the blocking rule they share with the diff."""
from test_diff import EXTRACT_2, conn, events, night1, runner, square, stage, ten  # noqa: F401
from test_diff import DSN


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
