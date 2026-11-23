# Workstream h-data: data and ingest

Goal: the tables the new map layers, cards and pages need, built nightly and safely, plus two correctness fixes in the diff.

Read `common.md`, `contracts.md` and `build-specs.md` in this folder first. Your files: everything under `ingest/` (Python, SQL, tests), and `README.md` sections about the ingest. The app test database gets ingest tables through `database/migrations/2026_11_22_000001_create_ingest_schema.php`, which runs `schema.sql`, so create new tables there with `create table if not exists`.

Run ingest tests with `PYTHONPATH=$PWD/ingest` so your clone's package wins over the shared venv's editable install. Ingest test database: yours, named in your brief. For a real end-to-end check, run the ingest against a scratch copy of your dev database with `--skip-fetch` (the `stg_*` tables are already there) and time each new step.

## Items

- **O5 support (P0).** `create index if not exists dead_tenements_ended_idx on dead_tenements (ended_at)`. Check `ALTER`/`CREATE INDEX` statements in `schema.sql` cannot take long locks on a re-run (PR 8 already guards one `ALTER`).
- **O1 data (P0).** `coverage_lowzoom(status, type_group, geom)`: union of current tenements by status and type group (E, P, M, other; L and G excluded), snapped, `ST_MakeValid`, simplified, subdivided (256 vertices). Refill with `DELETE` and `INSERT` in the snapshot transaction (no rename: a rename holds an exclusive lock across the diff). GiST index.
- **O6 data (P1).** `freed_ground`: for deaths in the last 90 days, the part of each dead tenement with no current blocking tenement over it (blocking = live or pending, not L, G or special prospecting licences), at least 0.5 ha; columns per contracts.md; rebuilt nightly so newly pegged ground drops out.
- **M10 data (P2).** `no_recorded_tenement`: WA land minus current blocking tenements minus land restrictions (s19, s57, FNAs), subdivided. You need a WA land polygon: use a public source (for example the SLIP or ABS state boundary) fetched by the ingest, or a simplified polygon committed to the repo; record the source and licence. Time it; if it takes more than a minute nightly, build it only when the inputs changed.
- **Precomputed recent changes (P0, for the hover card).** `recent_changes(tenid, event_type, detected_at)`: the latest non-reversed event per tenement in the last 30 days, refreshed at the end of each run.
- **G17 (P1).** Special prospecting licences (`special_ind`, or an ID ending in `S` such as `P 27/2688S`) no longer count as blocking ground in `diff.sql`'s uncovered share or in the tables above. Check `docs/plan.md` for the rule if it is in your clone.
- **M8 places (P1).** `places(id, name, kind, lga, geom)` with WA towns and localities from a public, licensed source (look for a SLIP "Localities" layer or Geoscience Australia's Gazetteer; prefer SLIP since the ingest already talks to it). Refresh weekly or when empty. Indexes for prefix and trigram search on `name`.
- **Report.** Add the new steps' timings and row counts to the run report.

## Tests

Coverage refill takes no `ACCESS EXCLUSIVE` lock on the tables the app reads (extend PR 8's reader test); freed ground drops when a new tenement covers it; special prospecting licences do not reduce uncovered share; `recent_changes` keeps only the latest non-reversed event; places load from a fixture. Full ingest suite passes. Report the nightly time added, measured on your dev database copy.
