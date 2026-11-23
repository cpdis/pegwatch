# Workstream e-activity: activity, map panel and digest

Goal: every list tells the truth and is never pointlessly empty; the map panel becomes a live view of what is in sight; the digest reads well.

Read `common.md`, `contracts.md` and `build-specs.md` in this folder first. Your files: `resources/views/pages/activity.blade.php`, `resources/views/components/event-list.blade.php`, `resources/views/pages/map.blade.php` (all of it except one `<livewire:watch-this-area />` line f-accounts adds at the top of the panel), the event and feed methods of `app/Support/Register.php` (new ones at the end under an "e-activity" comment), `app/Digest/*`, digest pages and mail views, your route block in `routes/web.php`, tests.

## Items

- **O10 Truthful empty states (P0).** `x-event-list` takes `context` and `subject` and reads `Register::latestRun()`: baseline only "Pegwatch has its first copy of the register. Changes show here from the next extract."; stale "No changes since <extract date>: the department has not published a new extract. We check every night."; fresh but empty, by context ("No changes to <ID> since Pegwatch started watching on <date>."); a filtered empty `/activity` blames the filter. Under it, for guests: the digest sign-up ("Get the week's changes every Monday") with `source` `empty-<context>`; on tenement pages "Email me when this changes" linking to `watch.start`. The string "second nightly run" disappears from the code.
- **A1 and G14 Feed from the register (P0).** `Register::feed()` unions diff events with register-dated items: applications by `applied_at` (pending), grants by `granted_at`, deaths from `dead_tenements.ended_at`, and Mining Notices received after the extract ("Lodged since the last extract", with the notice link and shire). No future expiries. Each row says its source (from the nightly change, from the register's dates, from a Mining Notice). Deduplicate against diff events for the same tenement and type. Use it on `/activity` and the map panel.
- **A2, A3, B11 (P1).** Group by day with counts at the top; collapse bulk lodgements ("Yandal Resources Limited applied for 11 prospecting licences on 3 and 4 Oct, <total> ha"); filters: type chips (several), mineral field (`Format::field` numbers), holder, date range; a map view of the feed (a small map with markers, or a link that opens the home map filtered); date picker weeks start Monday, no future dates, sentence-case presets ("Last 7 days").
- **A4 RSS (P2).** `activity.rss` with the same filters.
- **Map panel (rows 6, 11, 16, 18, PH1).** Rebuild the `<aside>`:
  - The guest digest sign-up goes under the empty state, not above Layers (G8).
  - M4: a list synced to the map from `map-moved` (server counts by bounds, `Register::countsInBounds()`): "<n> tenements in view · <n> changed this week", tabs Changes / Tenements / Releases; hovering a row dispatches `map-select`; "Update as I move the map" checkbox.
  - M6: legend with counts by status and type (from SQL) and a toggle per type (dispatch `map-filter`).
  - M5: a chip bar (Type, Status, Applied, Expires within, Size) dispatching `map-filter`.
  - Layer toggles dispatch `map-layers`; add "Freed ground" (default on), "No recorded tenement" (off), "One-minute blocks" (off); the dead layer label reads "Died since <date>" from `Register::deadHistoryStart()` until the history covers 12 months.
  - A colour-mode switch: "Colour by status" (default) or "by type".
  - PH1: on phones the map is full screen with a floating search bar and the panel as a bottom sheet that peeks (counts and filters) and drags up.
  - Touch targets at least 44 px.
- **D1 Sample digest (P0).** Before the first issue exists, `/digest` shows a sample built from the feed's register data for the last 7 days, clearly labelled as a sample.
- **G19 (P1).** The digest lists every upcoming compulsory release (up to 15): "Upcoming compulsory releases".
- **E4 and G12 (P1).** Digest: lead with the biggest item, right-align hectares, link holders, add mineral fields; after digest confirmation, offer "Set up a free watch area" (`watch.start`); a stale week sends a short notice instead of empty tables.

## Tests

Empty-state sentences for baseline, stale, fresh and filtered; feed includes register-dated items without diff events and no future expiries; bulk collapse; RSS validates; digest sample and releases beyond 7 days; panel counts by bounds. Screenshots of the home page panel (desktop and phone sheet), `/activity` (populated and empty), `/digest`, a digest issue, before and after.
