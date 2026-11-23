# Workstream g-visual: visual system and global pages

Goal: Pegwatch looks like one finished product: its own mark, a near-black frame with one hi-vis accent, honest freshness, short legal line, good 404 and share previews.

Read `common.md`, `contracts.md` and `build-specs.md` in this folder first. Your files: `resources/views/layouts/app/*`, `resources/views/layouts/app.blade.php`, `resources/views/partials/head.blade.php`, `resources/views/components/app-logo*.blade.php`, `public/favicon.*`, `public/apple-touch-icon.png`, `resources/views/errors/*`, a new `/data` page, OG image code, the type-scale part of `resources/css/app.css`, your route block in `routes/web.php`, tests.

## Items

- **V1 Brand (P0).** Draw a Pegwatch mark as SVG: a survey peg (a short post with a flag of hi-vis tape) that reads at 16 px. Use it in the header, sidebar, auth pages (f-accounts uses `x-app-logo`), favicon (SVG and PNG; make `favicon.ico` too if you can without new system packages, else drop it and say so), and apple-touch-icon. `APP_NAME` is Colin's to set in Laravel Cloud: make the code show "Pegwatch" even if `APP_NAME` is lower case (a config default `Pegwatch` and the brand component using a constant), and note it in your report.
- **V2 Frame (P0).** Near-black header (`--color-ink`) with white text, the hi-vis accent only for the primary action ("Free alerts") and the current nav item underline; content stays white (dark mode: zinc-900).
- **V5 Freshness chip and footer (P0).** In the header (hidden under 640 px, shown in the phone menu): fresh, a grey dot and "Register 22 Nov, 4:11am"; stale (run stale or extract over 72 hours old), an amber dot and "Register 19 Nov · 4 days old". Clicking opens a popover: "The department publishes a new extract most business days. Pegwatch checks every night. Last check: <time> AWST." with a link to `/data`. Footer: one line, "A screening aid, not advice. Confirm in TENGRAPH and with the Mining Registrar. Data © State of WA (DMPE), CC BY 4.0, changes made." and a "Data and disclaimer" link to `/data`. One extract time across the site: the run's (`Register::latestRun()->tenements_extract`).
- **V4 Type scale (P1).** Heavier, tighter headlines (Flux heading sizes), tabular numbers by default in tables, a consistent eyebrow style. Do not restyle page bodies other workstreams own; set the scale globally.
- **O8 Data status page (P1).** `/data`: the last 30 nightly checks (time, extract date per layer, rows, events found, stale flag) from `ingest_runs` and `layer_extracts`; what Pegwatch cannot know (pegging on the ground, private land, same-day lodgements); the full disclaimer; TENGRAPH and Mining Registrar links; the data licence.
- **O9 Share previews (P1).** Per-page description, canonical, Open Graph and Twitter tags for tenement, holder, digest issue and home pages, derived in the head from the route (`App\Support\PageMeta::for(request())` or similar) so page owners need not change. Tenement description is the T1 headline sentence. No OG image and `noindex` stays for natural-person holder pages (d-holders adds the robots meta).
- **O9 image and E2 renderer (P1/P2).** A server-side PNG of a tenement outline on a plain light background with the ID and type (1200 x 630), cached per ingest run: `og.tenement`. A signed `og.area` image of a watch area with the changed tenements outlined (f-accounts may embed it in alert emails). Use GD or Imagick, whichever PHP has; no basemap.
- **O16 404 (P1).** A Pegwatch 404 in the app layout with search and, for `/tenements/<slug>`, "No tenement <ID> on the register or in the dead history. Check TENGRAPH." with the link.
- **G13 Phone header (P1).** On phones, a wide tap-to-search pill in the header instead of the bare search icon; "Free alerts" stays visible.

## Tests

Freshness chip fresh and stale; footer text and links; `/data` lists runs; OG tags on a tenement page; no OG image on a person page; 404 page for a missing tenement; `og.tenement` returns a PNG; `og.area` rejects unsigned URLs. Screenshots of header and footer (desktop, phone, dark), `/data`, 404, the OG image, before and after.
