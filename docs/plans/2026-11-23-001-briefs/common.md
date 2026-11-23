# Common brief for every Pegwatch workstream

You are one of 8 parallel workstreams building Pegwatch's design and UX overhaul. Colin owns the product. Build your workstream's items completely, test them, screenshot them, and commit them to your branch. Do not push.

## Read first

1. The plan: `docs/plans/2026-11-23-001-feat-design-overhaul-plan.md` in your clone (Goal Capsule, Assumptions, KTDs, your unit).
2. `CLAUDE.md` in your clone.
3. `contracts.md`, `build-specs.md` and your workstream brief, all in `docs/plans/2026-11-23-001-briefs/`.

## Product

Pegwatch maps every WA mining tenement (live, pending, dead) from the state's SLIP register, diffs it nightly into change events, and emails free watch-area alerts. Users are prospectors first, then consultants and juniors, then holders watching neighbours. The benchmark is boisebuilds.com: simple, very functional, well designed. Every place a user looks should answer "what is this, where does it stand, and what can I do about it" without a click.

Colin's decisions (2026-11-23): prospectors first (status colours, freed ground and releases up front); sign-in by magic link, no password needed; alerts skip conversions, amalgamations and covered deaths by default; near-black UI with one hi-vis accent; free = hover card, filters, freed-ground layer, one watch area, one holder watch; Pro (shown with a lock, linking to `/pro`) = exports, more areas and holder watches, extra alert types; satellite only when a provider URL is configured.

## Hard rules

- Natural-person holders are shown by name only. Never fetch, store or show an address.
- SLIP dates are AWST wall-clock values. Use the `pw_*` SQL helpers, never `to_timestamp` directly. Show times in AWST.
- Pegwatch is a screening aid, not advice. Keep "confirm in TENGRAPH and with the Mining Registrar" wherever a user might act. Use `App\Support\Format::TENGRAPH_URL` for the link. Never say ground "is open" or "can be applied for"; say what the register shows ("no live or pending tenement over it on the register").
- Stay inside the files your brief gives you. If you must touch a file another workstream owns, keep the change small and separate (a new method, a new block), and say so in your report.
- Do not push, open PRs, change GitHub settings, touch production, deploy, or run the nightly workflow. Do not create git worktrees. Do not install system packages.
- Do not read or search for credentials. Your clone already has `vendor/`, `node_modules/` and a `.env`.
- Keep code idiomatic Laravel 13, Livewire 4 single-file pages, Flux components first, Tailwind 4. Comment new code concisely. Delete code you make dead. Files under about 500 lines where natural.

## Shared foundation (already in your base commit; use it, do not fork it)

- `App\Support\Format`: `STATUS_COLOURS`, `STATUS_LABELS`, `statusKey()`, `sentence()` (register words to sentence case; never holder names), `relative()` ("3 years 7 months ago", "in 4 years"), `field()` (mineral district or field from an ID, "Kanowna"), `fieldNumber()`, `when()` (date with time only when the register has a time), `legalArea()`, `TENGRAPH_URL`.
- `config/mineral_fields.php` (38 fields from SLIP DMIRS-005).
- `App\Support\Register::lastCheck()`, `deadHistoryStart()`, `latestRun()`.
- `App\Support\Ics::release()` and `Ics::event()`.
- Blade components: `<x-status-badge status="LIVE" />`, `<x-eyebrow dot="#hex">…</x-eyebrow>`, `<x-pro-lock ref="export" />`.
- CSS tokens in `resources/css/app.css`: `--color-ink` near-black, `--color-accent` hi-vis yellow `#ffd400` (Flux primary buttons), `--color-status-*`.
- Map script modules in `resources/js/map/`: `index.js` (Alpine component), `style.js` (sources, layers, paint), `card.js` (hover and click), `controls.js`, `url.js`, `draw.js`, `util.js`. `resources/js/map.js` re-exports `index.js`.
- Design language: near-black header and primary surfaces, white content, one hi-vis accent used sparingly (primary action, selection, highlight), status dots, small-caps eyebrows, heavy tight headlines, tabular numbers, sentence case for register words.

## Your environment

The integrator (the session that started you) prepares a shared stack before you start and tells you your names:

- A git worktree or clone of the repo on branch `ws/<your key>`, cut from the plan branch. Never push; the integrator merges your branch.
- PostgreSQL with PostGIS, reachable as the integrator says. Your own dev database `pegwatch_<key>` (a copy of a real SLIP ingest with the full dead history, plus a second run that produced change events: see `seed.md`), your app test database `pegwatch_<key>_test`, your ingest test database `pegwatch_<key>_itest`. Use only yours.
- `vendor/` (with Flux Pro), `node_modules/` and a `.env` pointing at your dev database, with your own port for `php artisan serve`.
- App tests: `DB_DATABASE=pegwatch_<key>_test php artisan test`. Ingest tests: `PYTHONPATH=$PWD/ingest TEST_DATABASE_URL=postgresql://<host>/pegwatch_<key>_itest pytest -q ingest/tests`. Also `vendor/bin/pint --test` and `npm run build`.
- Screenshots: Playwright with Chromium (in the cloud image, `require('playwright')` works and `chromium.launch()` needs no options). Move a map with `window.Alpine.$data(document.querySelector('[x-data^="tenementMap"]')).fit([w, s, e, n])`. Avoid `fullPage` screenshots of map pages (they squash the canvas); take viewport shots and scroll instead. "Before" shots can come from production, https://pegwatch-production-riyi0y.laravel.cloud, which runs `main` (read-only: never sign up, subscribe, reserve or submit there).

## Proof

- Before you change a screen, screenshot it (desktop 1440x900 at deviceScaleFactor 2, phone 390x844). After, screenshot it again.
- Save them under `review/<workstream>/` in your clone and write `review/<workstream>/index.html`: one section per screen, newest first, before beside after, each with a one-line caption. `review/` is gitignored.
- Every item in your brief needs a test where behaviour can be tested (feature or unit test, or an ingest test). Browser-only behaviour (map hover) gets a Playwright check script saved under `review/<workstream>/checks/` and its output in your report.

## Commits

Commit in small, reviewable steps on your branch, one item or a tight group per commit. Conventional Commits; summary 50 characters or fewer; blank line; 2 to 20 lines on what and why; end with the line `Co-authored-by: Claude Code`. Run `vendor/bin/pint` before each commit. All suites must pass at your last commit.

## Report back

When done, reply with: the commit list (`git log --oneline base..HEAD`), test results (counts and pass/fail for each suite), each item ID with done / partial / not done and why, any change outside your files, any contract you changed or need from another workstream, and the path to your `review/<workstream>/index.html`. Write in plain, short sentences.

## pstack

This repo works in pstack's poteto-mode (`CLAUDE.md`). If the pstack plugin is loaded, follow `/pstack:poteto-mode`'s worker rules. If it is not loaded but its files exist (look under `~/.claude/plugins/cache/cursor-plugins/pstack/*/skills/poteto-mode/`), read `worker.md` and `SKILL.md` there first. If neither exists, say "pstack unavailable" in your report and follow the rest of this brief.

- Apply poteto-mode's principles; read a principle's leaf `SKILL.md` before you cite it, and name each principle that shaped a decision in your report.
- The bar in this brief is the whole bar. Where pstack's worker rules and this brief differ, this brief wins where it says so: do not push, do not open PRs, stay in your own checkout.
