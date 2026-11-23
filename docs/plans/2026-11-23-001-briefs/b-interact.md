# Workstream b-interact: map interaction

Goal: the map answers questions on hover and leads somewhere on click, on desktop and phone.

Read `common.md`, `contracts.md` and `build-specs.md` in this folder first. Your files: `resources/js/map/index.js`, `card.js`, `controls.js`, `url.js`, `draw.js`, `util.js`, a new `filters.js`, and CSS for the map UI (put it in a new `resources/css/map.css` imported from `app.css` with one line). You may add one line to `resources/views/partials/head.blade.php` for the satellite meta tag and one key to `config/pegwatch.php`. Do not edit `style.js` (a-render owns it) or any page Blade file.

## Items

- **M1 Hover card (P0).** Build spec 1 exactly (in the plan's Build specs section): desktop only (`(hover: hover) and (pointer: fine)`), rAF-throttled `mousemove`, 120 ms delay, updates without delay between features, hides 80 ms after leaving, on drag, zoom and Escape. Rows: ID and status badge; type in sentence case and mineral field (port `Format::field` to JS from `config/mineral_fields.php`, passed in through the page or a small JSON endpoint; pick the simplest); first holder and "+2"; hectares and the one date that matters (rules in the spec, "today" in Perth); flags (release time, recent change); "+2 more here: …"; "Click for actions". Below zoom 8: "Zoom in to see tenements here". Feature-state `hover` on the tenements source. No network request per hover. Mock-up in the proposal doc: M 27/518 shows "Pending", "Mining lease application", "YANDAL RESOURCES LIMITED", "198 ha · unsurveyed", "Applied 21 Apr 2023, 3 years 7 months ago".
- **M2 Click card (P0).** Click pins the card: close button, actions Open, Zoom to, Copy ID, TENGRAPH (`Format::TENGRAPH_URL`), Watch around here (dispatches `watch-start` with the click point). Several features: rows smallest area first, Up and Down move, Enter opens. Empty click unpins. Keep `closeOnClick: false` (B19). Phones: the card is a bottom sheet.
- **O14 Keyboard (P2).** Canvas focused: a centre crosshair, Enter runs the click at the centre, Escape closes.
- **M7 URL state (P0).** `#map=<zoom>/<lat>/<lng>&l=<layers>&sel=<slug>` with `history.replaceState`; restore on load and on Back.
- **Panel events.** Dispatch `map-moved` on `moveend`; handle `map-filter` (filters.js, combining a-render's `BASE_FILTERS`), `map-layers`, `map-fly`, `map-select`, `watch-start`. Holder filtering means focus mode, not a tile filter.
- **M8 Locate me (P1).** MapLibre `GeolocateControl`; `map-fly` handles place results from search.
- **G3 Coordinates and satellite (P1).** Cursor coordinates (crosshair centre on phones) with one-tap copy, as decimal degrees (GDA2020 is close enough to WGS84; say WGS84). Satellite toggle only when `config('pegwatch.satellite.url')` is set (env `PEGWATCH_SATELLITE_URL`, `PEGWATCH_SATELLITE_ATTRIBUTION`), as a raster layer under the tenement layers; when on, show its attribution.
- **O13 Block counter (P2).** With the grid on, drag a box to count one-minute blocks inside it.
- **Focus and context modes.** `focus: { features }` (holder page H1): colour those features by status and mute the vector layers. `context: { locator: true, releases }` (tenement page T3, G18): padded fit so towns show, a small WA locator inset with a dot, other tenements muted, release parcel hatched in magenta.
- **W1 Draw tool (P0).** `watch-start` or `draw: true` shows a circle at the map centre (radius the smaller of 25 km and half the view) with a drag handle on the edge (1 to 50 km), dispatching `watch-shape` as it changes; a tenement entry dispatches `{ kind: 'tenement' }`. f-accounts builds the form that listens.
- **PH2 (map part, P1).** Collapse the compact attribution after load (MapLibre opens it expanded) and move the scale bar to the top left; touch targets at least 44 px.

## Tests

Playwright checks saved under `review/b-interact/checks/`: hover over M 27/518 at Kalgoorlie zoom shows the card within 150 ms with the right values and no network request; four clicks on one point show four cards; Back restores the view; Escape closes; the phone bottom sheet opens on tap; `watch-shape` fires with sensible values while dragging. Run `npm run build`.
