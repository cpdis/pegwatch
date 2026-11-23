"""WA land and places from the ABS boundaries.

The fixtures are the real zip names holding a few records each: UCL, SAL and
LGA rows cut from the ABS files with ogr2ogr (simplified), a made-up rural
balance square, and a made-up state with a hole and an island next to New
South Wales.
"""
from pathlib import Path

import pytest

from pegwatch_ingest import boundaries, report
from test_diff import DSN, conn, night1, runner, stage  # noqa: F401

FIXTURES = Path(__file__).parent / "fixtures" / "abs"


def fixture(name):
    return (FIXTURES / name).read_bytes()


def test_places_load_from_the_abs_files(conn):
    assert boundaries.due(conn)
    got = boundaries.refresh(conn, 0, fixture)
    places = {(r[0], r[1]): r[2] for r in conn.execute("select name, kind, lga from places")}
    # Suffixes go; the Leonora locality around the town of Leonora adds nothing; the
    # rural balance and the Victorian locality are not WA towns.
    assert places == {("Leonora", "town"): "Leonora",
                      ("Kalgoorlie - Boulder", "town"): "Kalgoorlie-Boulder",
                      ("Kalgoorlie", "locality"): "Kalgoorlie-Boulder",
                      ("Boulder", "locality"): "Kalgoorlie-Boulder"}
    assert got["places"] == 4

    land = conn.execute("""
        select sum(ST_Area(geom)), bool_or(ST_Intersects(geom, ST_Point(121.15, -28.85, 4326))),
               bool_or(ST_Intersects(geom, ST_Point(121.75, -28.85, 4326))), max(ST_XMax(geom))
        from wa_land""").fetchone()
    assert land[0] == pytest.approx(0.6 * 0.4 - 0.01 + 0.01)
    assert land[1:] == (False, True, pytest.approx(121.8))  # the hole stays out, the island is in, no NSW


def test_the_run_refreshes_boundaries_weekly_and_survives_a_failure(conn, monkeypatch):
    monkeypatch.setattr(boundaries, "download", fixture)
    stage(conn, night1())
    s = runner.run(DSN, None, False, True, None, min_row_ratio=0, check_notices=False, force_boundaries=True)
    assert s["run"]["notes"]["boundaries"]["places"] == 4
    assert "- ABS boundaries refreshed: 4 places" in report.markdown(s)
    assert not boundaries.due(conn)

    conn.execute("update ingest_runs set started_at = started_at - interval '8 days'")
    conn.commit()
    assert boundaries.due(conn)

    def offline(name):
        raise OSError("ABS unreachable")
    monkeypatch.setattr(boundaries, "download", offline)
    stage(conn, night1())
    s = runner.run(DSN, None, False, True, None, min_row_ratio=0, check_notices=False, force_boundaries=True)
    assert s["run"]["status"] == "ok" and "ABS unreachable" in s["run"]["notes"]["boundaries"]["error"]
    assert conn.execute("select count(*) from places").fetchone()[0] == 4  # the old rows stay
