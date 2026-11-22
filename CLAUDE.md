# Pegwatch

WA mining tenement tracker. `ingest/` is the nightly Python job that pulls the SLIP
tenement layers into PostGIS and diffs snapshots into `tenement_events`; the Laravel 13 +
Livewire 4 + Flux app at the repo root reads those tables. See README.md; the product plan and roadmap are in `docs/plan.md`.

- Pages are single-file Livewire components in `resources/views/pages/` (routed with
  `Route::livewire`); shared UI is Flux components. Use Flux before hand-rolled markup.
- The map is an Alpine component in `resources/js/map.js`, inside `wire:ignore`.

## Working here

- This repo uses pstack (enabled in `.claude/settings.json`). Work in `/pstack:poteto-mode`.
  Run `/pstack:setup-pstack` once per machine; it writes your model roles to
  `~/.claude/rules/pstack-models.md`.
- Review a branch with `/pstack:interrogate`: findings count only when a run proves them.
- Tests: `cd ingest && pytest` (needs PostGIS, `TEST_DATABASE_URL`, default `postgresql:///pegwatch_test`), `php artisan test`
  (database `pegwatch_app_test`), `vendor/bin/pint --test`, `npm run build`.
- SLIP dates are AWST wall-clock values encoded as UTC epoch ms. Use the `pw_*` SQL helpers,
  never `to_timestamp` directly.
- Natural-person holders are shown by name only. Never fetch or store holder addresses.
