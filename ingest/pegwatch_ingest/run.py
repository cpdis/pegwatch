"""Nightly ingest: fetch SLIP layers, build a snapshot, diff it, report."""
from __future__ import annotations

import argparse
import datetime as dt
import json
import logging
import os
import sys
import time
from pathlib import Path

import psycopg

from . import boundaries, db, notices, report
from .layers import DEAD, NIGHTLY, TENEMENTS, Layer
from .slip import SlipClient

log = logging.getLogger("pegwatch_ingest")

# Abort rather than record mass "deaths" if the live layer comes back short.
MIN_ROW_RATIO = 0.95

# Tables the map and its cards read, refilled from tonight's snapshot in this
# order, each by sql/<table>.sql inside the snapshot transaction.
MAP_TABLES = ("coverage_lowzoom", "freed_ground", "no_recorded_tenement", "recent_changes")


class IngestAborted(RuntimeError):
    pass


def where_for(layer: Layer, dead_since: dt.date | None) -> str:
    if layer is DEAD:
        return "1=1" if dead_since is None else layer.where.format(since=dead_since.isoformat())
    return layer.where


def fetch_layer(conn, client: SlipClient, run_id: int, layer: Layer, where: str) -> int:
    expected = client.count(layer, where)
    for attempt in (1, 2):
        db.create_staging(conn, layer)
        db.load_features(conn, layer, client.features(layer, where))
        # Count distinct object ids, so a page served twice cannot hide a missing one.
        fetched = db.scalar(conn, f"select count(distinct oid) from stg_{layer.key}")
        conn.commit()
        if fetched == expected:
            break
        log.warning("%s: expected %d rows, got %d (attempt %d)", layer.code, expected, fetched, attempt)
        expected = client.count(layer, where)
    else:
        raise IngestAborted(f"{layer.code}: expected {expected} rows, fetched {fetched}")
    extract = db.max_extract(conn, layer)
    with conn.cursor() as cur:
        cur.execute(
            "insert into layer_extracts (run_id, layer_code, layer_key, expected_rows, fetched_rows, max_extract)"
            " values (%s, %s, %s, %s, %s, %s)",
            (run_id, layer.code, layer.key, expected, fetched, extract))
    conn.commit()
    log.info("%s: %d rows, extract %s", layer.code, fetched, extract)
    return fetched


def build_map_tables(conn, run_id: int) -> dict:
    """Refill each map table under a savepoint, so one that fails keeps last
    night's rows and the snapshot, diff and alerts still go ahead."""
    out = {}
    for table in MAP_TABLES:
        start = time.monotonic()
        conn.execute("savepoint map_table")
        try:
            db.run_script(conn, f"{table}.sql", run_id)
        except psycopg.Error as exc:
            conn.execute("rollback to savepoint map_table")
            log.warning("%s not rebuilt, keeping the previous rows: %s", table, exc)
            out[table] = {"error": str(exc).strip()}
            continue
        conn.execute("release savepoint map_table")
        out[table] = {"rows": db.scalar(conn, f"select count(*) from {table}"),
                      "seconds": round(time.monotonic() - start, 1)}
        log.info("%s: %d rows in %.1fs", table, out[table]["rows"], out[table]["seconds"])
    return out


def refresh_boundaries(conn, run_id: int) -> None:
    """Reload WA land and places from the ABS. A failure keeps the old rows."""
    try:
        got = boundaries.refresh(conn, run_id)
    except Exception as exc:  # reference data must never fail the ingest
        conn.rollback()
        log.warning("ABS boundaries not refreshed: %s", exc)
        got = {"error": str(exc)}
    with conn.cursor() as cur:
        cur.execute("update ingest_runs set notes = notes || jsonb_build_object('boundaries', %s::jsonb)"
                    " where id = %s", (json.dumps(got), run_id))
    conn.commit()


def run(dsn: str, dead_days: int | None, full_dead: bool, skip_fetch: bool,
        report_dir: Path | None, client: SlipClient | None = None,
        min_row_ratio: float = MIN_ROW_RATIO, check_notices: bool = True,
        force_boundaries: bool = False) -> dict:
    client = client or SlipClient()
    conn = db.connect(dsn)
    db.apply_schema(conn)

    prev_extract = db.scalar(conn, "select tenements_extract from ingest_runs "
                                   "where status in ('ok', 'baseline') order by id desc limit 1")
    run_id = db.scalar(conn, "insert into ingest_runs default values returning id")
    conn.commit()
    # Before the snapshot, so tonight's "no recorded tenement" layer has the land.
    if force_boundaries or (not skip_fetch and boundaries.due(conn)):
        refresh_boundaries(conn, run_id)

    try:
        existing = db.scalar(conn, "select count(*) from tenements")
        if not skip_fetch:
            if full_dead:
                dead_since = None
            else:
                days = dead_days if dead_days is not None else 30
                dead_since = dt.date.today() - dt.timedelta(days=days)
            for layer in NIGHTLY:
                fetch_layer(conn, client, run_id, layer, where_for(layer, dead_since))

        fetched_live = db.scalar(conn, "select count(*) from stg_tenements")
        if existing and fetched_live < min_row_ratio * existing:
            raise IngestAborted(
                f"live layer returned {fetched_live} rows against {existing} last run; refusing to diff")
        # The pending layers drive release and amalgamation events, so an empty or
        # truncated fetch would read as every release happening at once.
        for table in ("release_pending", "amalgamation_pending"):
            had = db.scalar(conn, f"select count(*) from {table}")
            got = db.scalar(conn, f"select count(*) from stg_{table}")
            if (had >= 20 and got < 0.5 * had) or (had > 0 and got == 0):
                raise IngestAborted(
                    f"{table} returned {got} rows against {had} last run; refusing to diff")

        extract = db.max_extract(conn, TENEMENTS)
        stale = prev_extract is not None and extract == prev_extract

        db.run_script(conn, "snapshot.sql", run_id)
        baseline = not existing
        if not baseline:
            db.run_script(conn, "diff.sql", run_id)
        map_tables = build_map_tables(conn, run_id)
        status = "baseline" if baseline else "ok"
        with conn.cursor() as cur:
            cur.execute(
                "update ingest_runs set finished_at = now(), status = %s, tenements_extract = %s,"
                " previous_extract = %s, stale = %s,"
                " notes = notes || jsonb_build_object('map_tables', %s::jsonb) where id = %s",
                (status, extract, prev_extract, stale, json.dumps(map_tables), run_id))
        conn.commit()
    except Exception as exc:
        conn.rollback()
        with conn.cursor() as cur:
            cur.execute("update ingest_runs set finished_at = now(), status = 'failed',"
                        " notes = jsonb_build_object('error', %s::text) where id = %s", (str(exc), run_id))
        conn.commit()
        raise

    if check_notices:
        try:
            items = notices.fetch()
            new = notices.store(conn, run_id, items)
            check = notices.crosscheck(conn) | {"feed_items": len(items), "new_items": new}
        except Exception as exc:  # the cross-check must never fail the ingest
            conn.rollback()
            log.warning("Mining Notices cross-check failed: %s", exc)
            check = {"error": str(exc)}
        with conn.cursor() as cur:
            cur.execute("update ingest_runs set notes = notes || jsonb_build_object('notices', %s::jsonb)"
                        " where id = %s", (json.dumps(check), run_id))
        conn.commit()

    summary = report.summarise(conn, run_id)
    if report_dir:
        report_dir.mkdir(parents=True, exist_ok=True)
        (report_dir / "summary.json").write_text(json.dumps(summary, indent=2, default=str))
        (report_dir / "summary.md").write_text(report.markdown(summary))
    conn.close()
    return summary


def main(argv: list[str] | None = None) -> int:
    p = argparse.ArgumentParser(prog="pegwatch-ingest", description=__doc__)
    p.add_argument("--dsn", default=os.environ.get("DATABASE_URL", "postgresql:///pegwatch"))
    p.add_argument("--dead-days", type=int, default=None,
                   help="pull DEAD rows with enddate in the last N days (default 30)")
    p.add_argument("--full-dead", action="store_true", help="pull the whole DEAD layer (one-off, ~423k rows)")
    p.add_argument("--skip-fetch", action="store_true", help="reuse existing stg_* tables")
    p.add_argument("--report-dir", type=Path, default=None)
    p.add_argument("--min-row-ratio", type=float, default=MIN_ROW_RATIO,
                   help="abort if the live layer returns fewer rows than this share of last run")
    p.add_argument("--no-notices", action="store_true", help="skip the Mining Notices RSS cross-check")
    p.add_argument("--refresh-boundaries", action="store_true",
                   help="reload WA land and places from the ABS now (otherwise weekly; works with --skip-fetch)")
    p.add_argument("-v", "--verbose", action="store_true")
    args = p.parse_args(argv)
    logging.basicConfig(level=logging.DEBUG if args.verbose else logging.INFO,
                        format="%(asctime)s %(levelname)s %(name)s: %(message)s")
    try:
        summary = run(args.dsn, args.dead_days, args.full_dead, args.skip_fetch, args.report_dir,
                      min_row_ratio=args.min_row_ratio, check_notices=not args.no_notices,
                      force_boundaries=args.refresh_boundaries)
    except IngestAborted as exc:
        log.error("ingest aborted: %s", exc)
        return 2
    print(report.markdown(summary))
    return 0


if __name__ == "__main__":
    sys.exit(main())
