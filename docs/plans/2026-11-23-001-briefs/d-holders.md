# Workstream d-holders: holders and search

Goal: holder pages that show a portfolio at a glance, and a search that finds tenements, holders and places.

Read `common.md`, `contracts.md` and `build-specs.md` in this folder first. Your files: `resources/views/pages/holders/show.blade.php`, `resources/views/livewire/search.blade.php`, the holder, search and place methods of `app/Support/Register.php` (and new ones at the end under a "d-holders" comment), response-header handling for holder pages, `tests/Feature/HolderPageTest.php` (new), `tests/Feature/SearchTest.php`.

## Items

- **H6 noindex for people (P0).** Natural-person holder pages get `<meta name="robots" content="noindex, nofollow">` and an `X-Robots-Tag: noindex, nofollow` header; company pages do not. (g-visual owns the head partial: add your meta through a small, separate `@push`/stack or ask in your report; the header can come from a route middleware or the component.)
- **H1 Holder map (P0, page part).** Return a GeoJSON FeatureCollection of the holder's tenements with `fmt_tenid`, `status`, `type_code` per feature, and pass it as `focus: { features }` to `tenementMap()`. b-interact colours them by status and mutes the rest.
- **H4, G7, G16 Table and summary (P1).** A portfolio strip (counts and hectares by type, expiries in the next 90 days); a table with sort and filter by status and type, status badges, "Expiring in 12 months" and "Past expiry, still live" groups first; two-line rows below 640 px instead of a clipped table; true dead counts (not the length of a 200-row list); for holders with no live tenements, map their dead tenements and hide the empty table. CSV export shown with `<x-pro-lock ref="export">`, never for a natural person.
- **H7 Watch this holder (P1).** A button linking to `route('watch.start', ['holder' => slug])` when it exists; one holder watch is free (the Pro page says "1 holder (coming soon)").
- **H2, H5 (P2).** Add "<n> applications in the last 12 months" to the summary; add deaths per month beside applications on the existing chart.
- **H3, S2 Name-match groups (P2).** "Entities matching "Northern Star"": companies only, labelled as a name match (SLIP has no parent company), with a distinct tenement count; one row in search.
- **S1 Richer search rows (P1).** Tenements: type, status badge, area, field. Holders: live and pending counts, a person icon for natural persons, a building icon for companies. "Nothing matches" with examples. A keyboard-hint footer (move, open, close). No wrapped counts on phones.
- **S3 Search fixes (P1).** "p 27/2688" finds the dead P 27/2688S; "m 26/1" lists M 26/1xx before "M 261SA".
- **M8 Places (P1, search part).** When `places` exists, place rows come first for place-like queries ("leonora"); choosing one dispatches `map-fly` on the map page, else goes to `/#map=12/<lat>/<lng>`.

## Tests

Person page has the meta and header, company page has neither; dead count above 200 is true; H1 FeatureCollection shape; search ranking cases above; phone rows render. Screenshots of a company holder (Yandal Resources Limited), a large one (a Northern Star entity), a person, and search states, desktop and phone, before and after.
