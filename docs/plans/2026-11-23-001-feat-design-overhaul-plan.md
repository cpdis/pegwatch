---
title: Pegwatch design and UX overhaul - Plan
type: feat
date: 2026-11-23
artifact_contract: ce-unified-plan/v1
product_contract_source: ce-plan-bootstrap
execution: code
---

# Pegwatch design and UX overhaul - Plan

## Goal Capsule

- Objective: a prospector who opens Pegwatch can see, without guessing, what each piece of ground is, who holds it, how old it is, what changed, and what may open up, and can set up an alert in one short step; the site looks finished and tells the truth about its data.
- Means: build all 50 rows of the reviewed proposal set (Appendix A) in 8 parallel workstreams after a shared foundation (KTD1, KTD2).
- Authority: Colin's answers of 2026-11-23 (Assumptions) override the proposal doc; the proposal doc overrides this plan's wording for item detail; the repo's CLAUDE.md rules override both (natural persons by name only, no addresses, `pw_*` SQL helpers for SLIP dates).
- Stop conditions: stop and ask before any production data change other than the approved full dead load, before any new paid service, and when a requirement turns out to be impossible with the SLIP data.
- Execution profile: one integrator (the thread's main session) plus 8 workstream subagents in separate clones; the integrator owns foundation, merging, verification and the PR.
- Finish and ship: one PR from `claude/project-thread-t37f2g` to `main`, commits grouped by workstream; Colin merges.

## Product Contract

### Summary

Pegwatch shows a fraction of the register data it holds, and the live site has faults that undercut trust (a blank result on every second map click, a broken TENGRAPH link, a state view that looks empty, false empty states, 4 weeks of dead history, alerts on conversions). Three reviews produced 50 rows of changes, benchmarked on boisebuilds.com. This plan builds all of them.

### Problem Frame

Users are prospectors first (Colin, 2026-11-23), then consultants and holders watching neighbours. Their questions: what is this ground, who holds it, since when, what changed, what may open, and tell me when it does. Today the map gives one line per click, pages open on bare IDs in register capitals, lists are empty while SLIP is stale, sign-up is an 8-step password form, and alert content does not match the plan.

### Requirements

Each R-ID maps to rows of the proposal list (Appendix A has the item detail).

Truth and trust
- R1. Fix B19 (every second map click shows nothing) and B1 (TENGRAPH link) (row 1).
- R2. Natural-person holder pages carry `noindex` meta and an `X-Robots-Tag` header (row 2, H6).
- R3. The dead layer is labelled from the real start of the dead history until the full load runs; the full load then runs once on production (row 3, O5).
- R4. Watch-area alerts skip conversions, amalgamations and deaths that leave under 1 ha uncovered, unless the user opts in (row 4, G15).
- R5. Empty states say why they are empty (stale extract, baseline only, filter) and offer a sign-up (row 6, O10).
- R6. The feed fills from register dates (applications, grants, deaths) and Mining Notices received after the extract; no future expiries; each item says its source (row 7, A1, G14).
- R7. One extract time per page; no "12:00am" on date-only values; the PR 7 and PR 9 map-panel conflict resolved with the digest sign-up under the empty state (rows 16, 26, G8, G18).

Map
- R8. A hover card and a pinned click card per Build spec 1 (row 5, M1, M2).
- R9. An honest state view from a low-zoom coverage layer, and readable fills at close zoom (row 8, O1, M3).
- R10. Map state in the URL (row 13, M7).
- R11. A list synced to the map, legend counts, filter chips (row 18, M4, M5, M6).
- R12. Place search and Locate me (row 19, M8).
- R13. Every scheduled release on the map with its time (row 20, M9).
- R14. A freed-ground layer worded as a register fact (row 21, O6).
- R15. Cursor coordinates with copy; a satellite toggle that appears only when a provider URL is configured (row 22, G3, Assumption A6).
- R16. A block grid and keyboard access (row 47, O13, O14); a dark map style and a loading state (row 50, V6, M11); a full "no recorded tenement" layer, worded per `docs/plan.md` (row 43, M10).

Tenement page
- R17. The top block per Build spec 3: eyebrow, headline, key facts, mineral field, open overlaps, map with context (row 9, T1, T2, O2, O3, T3).
- R18. A dead tenement page that answers "is it open?" (row 15, T8).
- R19. Restriction and pending-action callouts, "live but past expiry", special prospecting licence badge, release parcel in the map fit, release callout with .ics (rows 23 to 27, G5, O4, G17, G18, T9).
- R20. Holder context and neighbours, lifecycle timeline, ground history, location line, watch this tenement, typical wait to grant (rows 28, 29, 30, 46, T6, O7, T5, T4, T7, O11).

Holders and search
- R21. The holder map shows only that holder (row 14, H1).
- R22. Holder table with sort, phone rows, portfolio strip and true dead counts; watch this holder (one free); name-match groups; chart and summary additions (rows 31, 32, 44, 45).
- R23. Richer search rows, search fixes, places (row 34, S1, S3, with R12).

Activity and digest
- R24. Feed grouping and filters, date picker fixes, RSS (rows 33, 50, A2, A3, B11, A4).
- R25. A sample digest issue, all upcoming releases in the digest, digest layout and a digest-to-watch-area step (rows 17, 20, 37, D1, G19, E4, G12).

Accounts, alerts and email
- R26. Email-first "Watch this area" with magic-link confirm, and branded auth pages (row 11, W1, W2, Build spec 5).
- R27. Alert email essentials (row 12, E1) and release .ics in emails (row 27, E3).
- R28. Watch area page upgrades, Pro locks, pre-sale page fixes, guest redirect message, alert history (rows 35, 36, 42, 48).

Visual system and global
- R29. Pegwatch brand, near-black UI with one hi-vis accent, status palette, sentence case, freshness chip (row 10, V1, V2, V3, V5).
- R30. Type scale, a Pegwatch 404, a data status page, share previews, phone layout (rows 38 to 41, 39, V4, O16, O8, O9, PH1, PH2).

### Scope Boundaries

- In: all 50 rows; ingest changes they need; the one approved production data load (full dead history).
- Out: paid satellite imagery (the toggle ships dark until Colin picks a provider); Stripe or Pro entitlement enforcement beyond the free limits that exist; native apps.

## Planning Contract

### Assumptions

- A1. Prospectors first: the default map colours ground by status with type as the outline, freed ground and releases are prominent, and type-colour mode is one toggle away.
- A2. Sign-in drops passwords for new users (magic links). Existing password logins keep working; setting a password becomes optional in settings.
- A3. Alerts skip conversions, amalgamations and deaths with under 1 ha uncovered by default, with an opt-in per watch area.
- A4. Look: near-black UI with one hi-vis accent ("Bear black" in Colin's reply read as "near black").
- A5. Free: hover card, filters, freed-ground layer, one watch area, one holder watch. Pro (locked, linking to `/pro`): exports, more areas and holder watches, extra alert types.
- A6. Satellite: provider-agnostic XYZ raster URL and attribution from env (`PEGWATCH_SATELLITE_URL`, `PEGWATCH_SATELLITE_ATTRIBUTION`); the toggle is hidden when unset. Mapbox raster is the recommended first provider; Google needs its own session-token flow and logo rules, so it is out until chosen.
- A7. PRs 7, 8, 9 and 10 are merged into `main` before the base branch is cut; PR 9's conflict is resolved first.

### Key Technical Decisions

- KTD1. Isolation: each workstream works in its own git worktree or clone, on branch `ws/<name>` from one base SHA, with its own databases and port. The integrator merges each branch into `claude/project-thread-t37f2g`. (The build moved to a cloud session on 2026-11-23, because Flux Pro installs there; the local session had no Flux licence access.)
- KTD2. File ownership: each workstream owns the files listed in its unit; shared files (`app/Support/Register.php`, `app/Support/Format.php`, `routes/web.php`, `resources/views/pages/map.blade.php`) take additions in clearly separated methods or blocks; the integrator resolves the overlaps.
- KTD3. The map script becomes ES modules in `resources/js/map/` during foundation: `index.js` (the Alpine component and its config), `style.js` (sources, layers, paint), `card.js` (hover and click), `url.js` (state in the URL), `controls.js` (coordinates, locate, satellite, keyboard), `draw.js` (watch-area shape). `resources/js/map.js` re-exports `index.js`. Rendering and interaction workstreams then own disjoint modules.
- KTD4. Design tokens in `resources/css/app.css`: Flux's `--color-accent` becomes the hi-vis accent (`#ffd400`) with near-black foreground and near-black accent-content on light backgrounds; `--color-ink` (`#0b0b0c`) for the header and primary surfaces; status colours as tokens and in `Format::STATUS_COLOURS`: Live `#15803d`, Pending `#d97706`, Dead `#71717a`, Release due `#c026d3`, Restricted `#a16207`. Type colours stay; releases move off mining-lease red to the release magenta with a hatch.
- KTD5. Shared Blade components from foundation: `x-status-badge`, `x-eyebrow`, `x-relative-date`, `x-pro-lock`; Format helpers `sentence()`, `relative()`, `field()`, `dateOrDateTime()`; `config/mineral_fields.php` generated from SLIP layer DMIRS-005 (38 rows: number, field, district).
- KTD6. Tenement tiles gain card fields from zoom 8 only (Build spec 1 SQL); full-detail geometry cut-off moves from zoom 9 to zoom 8. A nightly `coverage_lowzoom` table (refilled with `DELETE` and `INSERT`, `ST_MakeValid`, L and G excluded) feeds a `coverage` tile layer for zoom 3 to 8.
- KTD7. Watch shapes: `watch_areas` gains `kind` (`circle`, `tenement`, `holder`), nullable centre and radius, `tenid`, `holder_slug`, `confirmed_at`, `include_covered_deaths`; the `area` polygon is the circle buffer or the tenement outline plus a buffer; holder watches match on `tenement_events.holders`. `AlertMatcher` skips unconfirmed areas.
- KTD8. Magic links: a signed, expiring URL opens a confirm page whose button POSTs (mail scanners cannot sign anyone in); `users.password` becomes nullable; Fortify keeps sessions and the existing password and two-factor paths.
- KTD9. Register feed: `Register::feed()` unions diff events, register-dated items (pending `applied_at`, `granted_at`, dead `ended_at`) and `mining_notices` after the extract, each with a `source` field, deduplicated against diff events for the same tenement and type.
- KTD10. Dead history: add `dead_tenements (ended_at)` index in `schema.sql`; the dead layer label reads `Register::deadSince()` until the history covers 12 months.
- KTD11. Proof: every workstream saves before and after screenshots (desktop 1440 px and phone 390 px) to a gitignored `review/` page, newest first.

### Sequencing

1. U0 environment and base, U1 foundation (integrator, in order).
2. U2 to U9 in parallel, one subagent each, from the U1 commit.
3. U10 integration and verification; U11 production data load (can run as soon as PR 8 is merged, independent of U2 to U9).

## Implementation Units

### Unit Index

| U-ID | Title | Key files | Depends on |
| --- | --- | --- | --- |
| U0 | Environment and base branch | local PostGIS, seed DB, `.env` | merged PRs 7 to 10 |
| U1 | Foundation | `resources/css/app.css`, `app/Support/Format.php`, `config/mineral_fields.php`, `resources/js/map/*`, components | U0 |
| U2 | Map rendering | `resources/js/map/style.js`, `app/Http/Controllers/TileController.php` | U1 |
| U3 | Map interaction | `resources/js/map/{card,url,controls,draw,index}.js`, map container in `map.blade.php` | U1 |
| U4 | Tenement page | `resources/views/pages/tenements/show.blade.php`, `Register::tenement*` | U1 |
| U5 | Holders and search | `resources/views/pages/holders/show.blade.php`, `resources/views/livewire/search.blade.php`, `Register::holder*`, `Register::search*` | U1 |
| U6 | Activity, map panel and digest | `activity.blade.php`, `components/event-list.blade.php`, map panel in `map.blade.php`, `app/Digest/*`, digest views | U1 |
| U7 | Accounts, alerts and email | `pages/auth/*`, `layouts/auth/*`, `watch-areas.blade.php`, `app/Alerts/*`, `app/Mail/*`, `pages/pro.blade.php`, settings | U1 |
| U8 | Visual system and global pages | `layouts/app/*`, `partials/head.blade.php`, logo components, `public/` icons, `errors/404.blade.php`, `/data` page | U1 |
| U9 | Data and ingest | `ingest/pegwatch_ingest/sql/*.sql`, `ingest/pegwatch_ingest/*.py`, places table | U1 |
| U10 | Integration, verification, PR | all | U2 to U9 |
| U11 | Production full dead load | GitHub workflow dispatch | PR 8 merged |

### U0. Environment and base branch

- Goal: a local stack every workstream can test and screenshot against.
- Approach: PostgreSQL with PostGIS; a seed database from a real SLIP ingest with `--full-dead`, then a second run against a perturbed snapshot so every event type exists (`docs/plans/2026-11-23-001-briefs/seed.md`); Flux Pro installed through the environment's credentials; `claude/project-thread-t37f2g` already carries `main` (PRs 7 to 10) and the foundation commit.
- Verification: `php artisan test`, `vendor/bin/pint --test`, `npm run build` and ingest `pytest` pass on the base.

### U1. Foundation

- Goal: shared pieces every workstream builds on, so they agree without talking.
- Requirements: R1 (both bug fixes), R29 tokens part, KTD3 to KTD5.
- Files: `resources/css/app.css`, `app/Support/Format.php`, `config/mineral_fields.php`, `app/Support/Ics.php`, `resources/views/components/{status-badge,eyebrow,relative-date,pro-lock}.blade.php`, `resources/js/map.js`, `resources/js/map/*.js`, `app/Support/Register.php` (`lastCheck()`, `deadSince()`), footer link.
- Approach: split `map.js` into modules with no behaviour change except B19 (`closeOnClick: false`); fix the TENGRAPH URL everywhere it appears; add tokens and helpers with tests.
- Test scenarios: `Format::relative` across past, future, months and years in `Australia/Perth`; `Format::field` for `E 24/250`, `M 27/518`, `M 261SA` (none); `Format::sentence` keeps holder names untouched; status badge renders each status.
- Verification: full suites pass; the map still loads and clicks open a popup every time.

### U2. Map rendering

- Goal: a map that is true at every zoom and readable when dense.
- Requirements: R9, R13 (markers and styling), R14 (layer), R16 (dark style, loading state, grid drawing, no-recorded-tenement layer drawing), tile part of R8.
- Files: `resources/js/map/style.js`, `app/Http/Controllers/TileController.php`, tile tests in `tests/Feature/`.
- Approach: Build spec 1 tile fields from zoom 8; coverage layer and cross-fade per Build spec 2; status-first paint (A1) with a type-colour mode; hatch patterns for pending and releases; restrictions as outline plus hatch; labels from zoom 12; dark style switch; `dataloading`/`idle` loading state; freed-ground and no-recorded-tenement layers from U9's tables.
- Contract with U9: tables `coverage_lowzoom(status text, type_group text, geom)`, `freed_ground(tenid text, fmt_tenid text, ended_at timestamp, reason text, holder text, uncovered_ha float, geom)`, `no_recorded_tenement(geom)`; until U9 lands, layers tolerate a missing table.
- Test scenarios: tile responses include the new fields at zoom 8 and omit them at zoom 7; the coverage tile is non-empty over the Goldfields; missing optional tables return empty tiles, not errors.

### U3. Map interaction

- Goal: the map answers questions on hover and leads somewhere on click.
- Requirements: R8, R10, R11 (chips and legend toggles on the client), R12 (locate and fly-to), R15, R16 (keyboard), map side of R21 (holder focus mode), R17 map context (locator inset, muted others, release hatch, fit), R26 draw tool, PH1 bottom sheet.
- Files: `resources/js/map/{card,url,controls,draw,index}.js`, the map container part of `resources/views/pages/map.blade.php`, map config props on pages that embed the map.
- Approach: Build spec 1 for the cards; `history.replaceState` for state; `focus` config (`holder`, `tenement`) used by U4 and U5 pages; `draw` emits a `watch-shape` window event `{kind, center, radiusKm}` or `{kind:'tenement', slug}` that U7 consumes; phone bottom sheet below 640 px.
- Test scenarios: browser checks with Playwright: hover shows the card within 150 ms with M 27/518's values; four clicks give four cards; Back restores the view; Escape closes.

### U4. Tenement page

- Requirements: R17 (page part), R18, R19, R20, R7 date-only part on this page.
- Files: `resources/views/pages/tenements/show.blade.php`, `app/Support/Register.php` (`tenement()`, `overlaps()`, new `neighbours()`, `restrictions()`, `history()`, `waitToGrant()`), `routes/web.php` (`.ics` route), tests.
- Approach: Build spec 3; overlaps open with lodgement order and same-holder and can-overlap tags; dead page answers "is it open?"; G5 callouts from `land_restrictions`, `amalgamation_pending`, `restoration_pending`; O4 sentence; watch-this-tenement button dispatches the U3/U7 flow.
- Test scenarios: live, pending, dead and past-expiry fixtures render their headlines; overlaps sort oldest first; a natural-person holder shows by name only.

### U5. Holders and search

- Requirements: R2, R21 (page part), R22, R23, R12 (search part).
- Files: `resources/views/pages/holders/show.blade.php`, `resources/views/livewire/search.blade.php`, `app/Support/Register.php` (`holder()`, `search*()`, `places()`), response header middleware or page hook for R2, tests.
- Test scenarios: person page has `noindex` and the header, company page does not; dead count is the true count above 200; "p 27/2688" finds P 27/2688S; "leonora" returns the place first.

### U6. Activity, map panel and digest

- Requirements: R5, R6, R7 (panel part), R11 (synced list and counts from the server), R24, R25.
- Files: `resources/views/pages/activity.blade.php`, `resources/views/components/event-list.blade.php`, the `<aside>` of `resources/views/pages/map.blade.php`, `app/Support/Register.php` (`feed()`, `countsInBounds()`), `app/Digest/WeeklyDigest.php`, digest views and mail, `routes/web.php` (RSS), tests.
- Test scenarios: stale, baseline and filtered empty states each show their sentence; register-dated items appear without diff events; bulk lodgements collapse; the digest lists releases beyond 7 days; the RSS feed validates.

### U7. Accounts, alerts and email

- Requirements: R4, R26, R27, R28.
- Files: `resources/views/pages/auth/*`, `resources/views/layouts/auth/*`, `resources/views/pages/watch-areas.blade.php`, a new watch-this-area Livewire component, `app/Models/WatchArea.php`, migrations, `app/Alerts/AlertMatcher.php`, `app/Mail/*`, `resources/views/mail/*` (alert), `resources/views/pages/pro.blade.php`, `resources/views/pages/settings/*`, `routes/web.php`, tests.
- Approach: Build spec 5 with Gemini's notes (POST confirm, existing accounts, nullable password checks); G15 filter; E1 headers and subject; .ics attachment; W3 radius edit and presets; W4 and G10 locks; B18 message; O15 alert history on the watch page.
- Test scenarios: guest submits a shape and email, confirm GET shows a button, POST signs in and confirms; a mail scanner GET confirms nothing; conversion deaths are not alerted unless opted in; one-click unsubscribe works without login.

### U8. Visual system and global pages

- Requirements: R29 (brand, header, footer, chip), R30, R1 footer link if U1 missed any.
- Files: `resources/views/layouts/app/header.blade.php`, `resources/views/layouts/*`, `resources/views/partials/head.blade.php`, `resources/views/components/app-logo*.blade.php`, `public/favicon.*`, `public/apple-touch-icon.png`, `resources/views/errors/404.blade.php`, a `/data` page, OG image route if any, `resources/css/app.css` (type scale only).
- Test scenarios: header shows the freshness chip in its stale and fresh states; 404 renders the layout; tenement pages carry `og:title` and `og:description`; person pages carry no OG image.

### U9. Data and ingest

- Requirements: R3 (index and label data), R9 data part, R14, R16 no-recorded-tenement data, G17 blocking test, places for R12.
- Files: `ingest/pegwatch_ingest/sql/schema.sql`, `snapshot.sql`, `diff.sql`, `ingest/pegwatch_ingest/layers.py`, `run.py`, ingest tests, an app migration only if a table must exist before the next ingest.
- Approach: KTD6 and KTD10; `freed_ground` from recent deaths minus current blocking tenements, rebuilt nightly; special prospecting licences excluded from blocking; places from a public SLIP or Geoscience Australia gazetteer layer (choose the source and record it).
- Test scenarios: coverage refill holds no rename lock; freed ground drops when new ground is pegged; `-S` licences do not reduce uncovered share.

### U10. Integration, verification, PR

- Approach: merge U2 to U9 in the order U9, U8, U2, U3, U4, U5, U6, U7; resolve shared-file overlaps; run every suite; screenshot every screen before and after; open one PR with the repo's attribution lines.

### U11. Production full dead load

- Approach: dispatch `nightly-ingest.yml` with `full_dead: true` once PR 8 is on `main`, outside the 7pm AWST window; confirm the earliest `ended_at` on a dead tile is about 12 months back.

## Verification Contract

- `cd ingest && pytest` with `TEST_DATABASE_URL` pointing at the workstream's own scratch database.
- `php artisan test` with `DB_DATABASE` set to the workstream's own test database.
- `vendor/bin/pint --test` and `npm run build`.
- Playwright screenshots at 1440 px and 390 px of every screen the unit touches, saved to `review/`.
- CI green on the PR.

## Definition of Done

- Every R-ID is met or has a written reason in the PR body.
- All suites and CI pass; no abandoned experimental code in the diff.
- `review/index.html` shows before and after for every touched screen, newest first.
- The proposal doc's Decision rows are marked Build and linked to the PR.

## Appendix A: item detail

The full item detail (change, why, priority, effort, source) is in the proposal doc "Pegwatch design and UX proposals" (https://claude.ai/artifact/E7YYhScDSDphzTFdJaeRTB). The workstream briefs in `docs/plans/2026-11-23-001-briefs/` carry everything an executor needs without the doc: `common.md` (rules for every workstream), `contracts.md` (events, routes, tables, tile fields between workstreams), `build-specs.md` (Specs 1 to 5 and Gemini's notes), `seed.md` (seed data recipe), and one brief per workstream (`a-render.md` to `h-data.md`).
