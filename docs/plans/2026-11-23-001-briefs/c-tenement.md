# Workstream c-tenement: tenement page

Goal: a tenement page that opens with the answer: what it is, who holds it, how old it is, where it is, what overlaps it, and what may happen next.

Read `common.md`, `contracts.md` and `build-specs.md` in this folder first. Your files: `resources/views/pages/tenements/show.blade.php`, the tenement methods of `app/Support/Register.php` (`tenement()`, `overlaps()`, and new ones at the end of the class under a "c-tenement" comment), your route block in `routes/web.php`, `tests/Feature/TenementPageTest.php` (new). Follow Build spec 3 in the plan.

## Items

- **T1 Headline (P0).** Eyebrow (`<x-eyebrow>`, "MINING LEASE · PENDING APPLICATION", status dot) over the ID with a status badge, then one sentence: pending "Pending mining lease application by Yandal Resources Limited, lodged 21 Apr 2023 (3 years 7 months ago). 198 ha in the Kanowna district, unsurveyed."; live "Exploration licence held by <holder> since <grant date>. Expires <date> (in 4 years). 31 blocks, 9,072 ha."; past expiry adds "The expiry date passed on <date>, but the register still lists it as live."; dead "<Type> applied for by <holder> on <date> and <reason> on <date>, after <span>." Use the legal area in both headline and facts. Holder names exactly as registered, linked.
- **T2 Key facts (P0).** Type, Status badge, Field (`Format::field`, with the number), Area ("4 blocks (1,184 ha mapped)"; mapped hectares only for blocks or a difference over 2%), Survey, Applied (every status says "Applied"; today live pages say "Started"), Granted (with months after application), Expires (amber under 6 months), Holders, Extract (the run's extract time from `Register::latestRun()`, the same one the footer shows; G8). Copy ID button. TENGRAPH link button. `Format::when` so date-only values print no "12:00am" (G18).
- **O3 Overlaps (P0).** An open block, not a tab: tenement, type and status, holder with a "Same holder" tag, share of this one, lodged date and "3 years 6 months before this one" or "after", and "Can overlap" for L, G and special prospecting licences (`special_ind`). Oldest first. Footnote: "Lodgement order is from the register. Priority under the Mining Act can depend on marking out, which no dataset shows. Confirm with the Mining Registrar."
- **T3 Map with context (P0, page part).** Pass `context: { locator: true, releases }` to `tenementMap()` and a bbox that includes any release parcel (G18). b-interact draws it.
- **T8 Dead tenement page (P0).** Reason and end date in the headline; "<share> of this ground has no live or pending tenement over it on the register today" (compute with PostGIS against current blocking tenements: not L, G or special); the tenements that now cover it, as rows like O3; a link to the freed-ground map view (`/#map=…&l=freed`).
- **G5 Callouts (P1).** Every intersecting `land_restrictions` row (kind, name, purpose, overlap %), and any `amalgamation_pending` or `restoration_pending` row for this tenement.
- **O4 Past expiry (P1).** The sentence above, plus "A <type> application by the same holder, <ID>, lodged <date>, covers <share> of it" when one exists.
- **G17 badge (P1).** "Special prospecting licence (can sit inside a live exploration licence)".
- **T9 Release (P1).** Keep the callout; add released hectares, a countdown ("in 9 days"), "Add to calendar" linking to `tenements.release-ics` (use `App\Support\Ics::release`).
- **T6 and O7 (P1).** Holder context ("Yandal Resources Limited · 105 tenements · 37 pending") and "Their other tenements within 20 km"; neighbours within 100 m grouped by holder, newest application first, with `<x-pro-lock ref="neighbours">` on a "Watch my neighbours" button.
- **T5 and T4 (P1).** A lifecycle timeline (applied, granted, events seen, expiry, today) and "Held before" rows from `dead_tenements` with at least 10% overlap; a location line "<distance> <bearing> of <town>" from `places` (h-data) when it exists, else the field.
- **T7 (P1).** "Email me when this changes" / "Watch this tenement" linking to `route('watch.start', ['tenement' => slug])` when that route exists.
- **O11 (P2).** For pending: "Mining leases in this field granted since 2020 took a median of <n> months (<n> grants). This one has waited <n>." Require at least 10 grants per field, else the WA-wide median for the type; say it describes past grants, not a forecast.
- **History.** Use `<x-event-list :events context="tenement" :subject="$t['fmt_tenid']" />` (e-activity writes the empty states).

Phone: everything stacks; the map follows the facts.

## Tests

Feature tests with fixtures for pending, live, live-past-expiry and dead tenements: headline text, "Applied" label, no "12:00am", overlaps order and tags, restriction callout, .ics route content type and body, natural-person holder shows name only. Screenshots of M 27/518, a live E licence, a past-expiry P licence, a dead tenement and a tenement with a release, desktop and phone, before and after.
