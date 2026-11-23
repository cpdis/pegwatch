# Workstream a-render: map rendering

Goal: the map is true at every zoom and readable where it is dense. Prospectors first: colour ground by status, outline by type.

Read `common.md`, `contracts.md` and `build-specs.md` in this folder first. Your files: `resources/js/map/style.js`, `app/Http/Controllers/TileController.php`, tile tests (`tests/Feature/TileTest.php`, new). Do not edit `index.js`, `card.js`, `controls.js`, `url.js`, `draw.js`; you hook in through `addLayers()` and `attachRendering()`, and the exports in contracts.md.

## Items

- **O1 Honest state view (P0).** A `coverage` tile layer from `coverage_lowzoom` (h-data builds it) for zoom 3 to 8: live in the status colour at 0.45 opacity with no outline, pending as a light tint (not a hatch at low zoom), draw order E, P, other, M. Cross-fade: coverage from full at zoom 7.5 to none at 8.5, single tenements the reverse. Low-zoom line width 0.8 px. A chip at the top centre below zoom 8: "Zoomed out: shaded ground is held or applied for. Zoom in to see each tenement." (render it from `attachRendering`). Until the table exists, the tile route returns an empty tile, not an error (`to_regclass`). Done when screenshots of one box at zoom 5 and zoom 7 show the same covered share by eye, and the zoom 4 Goldfields tile stays under 200 KB gzipped.
- **M3 Readable fills (P0).** Status-first default: fill by status (live faint, pending hatched, release magenta hatch, dead grey dotted), outline by type colour. Keep a type-colour mode the component can switch to (export a function `setColourMode(map, 'status'|'type')`). Mining and prospecting leases draw above exploration licences. Restrictions as outline plus hatch (not a brown wash). Releases leave mining-lease red for the release magenta with a hatch. IDs as labels from zoom 12 (guard against a style without glyphs). Paint reads feature-state `hover` and `selected`.
- **Tile fields for the hover card (part of M1, P0).** Build spec 1 SQL (Build specs tab, also in the plan): from zoom 8 add `tenid`, `holders_n`, `ha`, `applied`, `granted`, `ends`, `rel_at`, `survstatus`, `special`, `changed_type`, `changed_at` (join `recent_changes` if it exists). Move the full-detail geometry cut-off from zoom 9 to zoom 8. Dead layer adds `holder`, `ha`; releases add `holder`, `ha`. Add an invisible `dead-fill` layer so dead tenements can be hovered and clicked (G2). Export `HOVERABLE`, `CLICKABLE`, `BASE_FILTERS`. `promoteId: 'tenid'`.
- **M9 Release markers (P1).** Every scheduled release (not only the next 7 days) as a point marker with its time label ("Wed 2 Dec, 8:30am"), visible at every zoom, part of the `releases` group.
- **O6 Freed-ground layer drawing (P1).** `freed` group from `freed_ground`: green hatch with a thin green outline, default on. Labels say a register fact, never "open".
- **M10 No-recorded-tenement layer drawing (P2).** `norecord` group from `no_recorded_tenement`, default off, faint fill. Its legend wording is e-activity's job; your layer id is `norecord-fill`.
- **O13 Block grid (P2, drawing part).** `grid` group: one-minute graticular lines for the viewport from zoom 11, faint, generated client-side.
- **V6 Dark map (P2).** When the page is in dark mode, use OpenFreeMap's dark style if it exists (check), re-adding Pegwatch layers after the style loads.
- **M11 Loading state (P2).** A small "Loading tenements" chip while tiles load (`dataloading` / `idle`).
- **B6 note.** Make tile responses send `Cache-Control: public, max-age=…` suitable for a CDN; Cloudflare needs a cache rule too, which Colin sets (say so in your report).

## Tests

Tile responses: new fields at zoom 8, absent at zoom 7; coverage tile non-empty over a fixture; missing optional tables give an empty tile with 200; dead and release fields present. Playwright: screenshots at zoom 5, 7, 9 and 12 over the Goldfields (Kalgoorlie: `[121.35, -30.85, 121.6, -30.65]`) before and after.
