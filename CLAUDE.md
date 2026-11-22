# Pegwatch

WA mining tenement tracker. `ingest/` is the nightly Python job that pulls the SLIP
tenement layers into PostGIS and diffs snapshots into `tenement_events`; the Laravel 12 +
Inertia React app at the repo root reads those tables. See README.md.

## Working here

- This repo uses pstack (enabled in `.claude/settings.json`). Work in `/pstack:poteto-mode`.
  Run `/pstack:setup-pstack` once per machine; it writes your model roles to
  `~/.claude/rules/pstack-models.md`.
- Review a branch with `/pstack:interrogate`: findings count only when a run proves them.
- Tests: `cd ingest && pytest` (needs PostGIS, `TEST_DATABASE_URL`, default `postgresql:///pegwatch_test`), `php artisan test`
  (database `pegwatch_app_test`), `npm run lint && npm run format:check && vendor/bin/pint --test`.
- SLIP dates are AWST wall-clock values encoded as UTC epoch ms. Use the `pw_*` SQL helpers,
  never `to_timestamp` directly.
- Natural-person holders are shown by name only. Never fetch or store holder addresses.
