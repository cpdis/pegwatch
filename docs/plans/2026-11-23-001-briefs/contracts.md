# Contracts between workstreams

Code against these names. If another workstream's piece is not in your clone yet, guard it (`Route::has()`, `Schema::hasTable()` / `to_regclass()`, optional chaining in JS) so your page still works without it.

## Workstreams

| Key | Name | Owns |
| --- | --- | --- |
| a-render | Map rendering | `resources/js/map/style.js`, `app/Http/Controllers/TileController.php` |
| b-interact | Map interaction | `resources/js/map/{index,card,controls,url,draw,util}.js`, new `resources/js/map/filters.js` |
| c-tenement | Tenement page | `resources/views/pages/tenements/show.blade.php`, Register tenement methods |
| d-holders | Holders and search | `resources/views/pages/holders/show.blade.php`, `resources/views/livewire/search.blade.php`, Register holder, search and place methods |
| e-activity | Activity, map panel, digest | `resources/views/pages/activity.blade.php`, `resources/views/components/event-list.blade.php`, `resources/views/pages/map.blade.php`, `app/Digest/*`, digest views and mail, Register event and feed methods |
| f-accounts | Accounts, alerts, email, Pro | auth pages and layouts, `watch-areas.blade.php`, new watch flow, `app/Models/WatchArea.php`, `app/Models/User.php`, migrations, `app/Alerts/*`, `app/Mail/*`, alert mail views, `pages/pro.blade.php`, settings pages |
| g-visual | Visual system and global | `resources/views/layouts/app/*`, `resources/views/layouts/app.blade.php`, `resources/views/partials/head.blade.php`, logo components, `public/` icons, `resources/views/errors/*`, `/data` page, OG images, `resources/css/app.css` (type scale) |
| h-data | Data and ingest | `ingest/**` |

`app/Support/Register.php` is shared by c, d and e: edit only the methods you own and add new ones at the end of the class under a comment naming your workstream. `routes/web.php` is shared: add your routes in one block under a comment naming your workstream. `app/Support/Format.php`: add new helpers at the end under a comment naming your workstream.

## Browser events (window CustomEvents, Alpine `$dispatch` or `window.dispatchEvent`)

| Event | Sent by | Detail | Effect |
| --- | --- | --- | --- |
| `map-moved` | b-interact, on `moveend` | `{ bounds: [w, s, e, n], zoom, center: [lng, lat] }` | e-activity updates the panel list and counts |
| `map-filter` | e-activity (chip bar) | `{ types: ['E', …] or null, statuses: ['LIVE', 'PENDING'] or null, appliedFrom, appliedTo ('YYYY-MM-DD' or null), expiresWithinMonths (int or null), minHa, maxHa }` | b-interact applies MapLibre filters (zoom 8 and up has the fields) |
| `map-layers` | e-activity (layer toggles, legend) | `{ layers: { tenements: true, …, freed: false } }` | b-interact sets the component's `layers` |
| `map-fly` | anyone | `{ center: [lng, lat], zoom }` or `{ bounds: [w, s, e, n] }` | b-interact moves the map |
| `map-select` | anyone (list hover) | `{ tenid }` or `{ tenid: null }` | b-interact sets feature-state `selected` |
| `watch-start` | anyone | `{ center?: [lng, lat], radiusKm? }` | b-interact enters the draw tool |
| `watch-shape` | b-interact | `{ kind: 'circle', center: [lng, lat], radiusKm }` or `{ kind: 'tenement', slug, fmt_tenid }` | f-accounts' watch panel fills its form |

URL state (b-interact): `#map=<zoom>/<lat>/<lng>`, optional `&l=<comma-separated layer keys>` and `&sel=<tenement slug>`. Other pages link to a place on the map as `/#map=12/-30.75/121.47`.

## Map layers and tile fields (a-render)

- Tenements tile, zoom 8 and up, adds: `tenid`, `holders_n`, `ha`, `applied`, `granted`, `ends` ('YYYY-MM-DD' strings), `rel_at` ('YYYY-MM-DD HH24:MI' AWST), `survstatus`, `special` (special prospecting licence, boolean), `changed_type`, `changed_at` (from `recent_changes`). Below zoom 8: `fmt_tenid`, `type_code`, `status`, `holder` as today. The tenements source uses `promoteId: 'tenid'` (zoom 8 and up), so feature-state `hover` and `selected` work.
- Dead tile adds `holder`, `ha`. Releases tile adds `holder`, `ha`, and a `release-points` layer with the release time.
- `style.js` exports `HOVERABLE` (layer ids the card queries, smallest-first ordering is the card's job), `CLICKABLE`, `LAYER_GROUPS`, `DEFAULT_LAYERS`, `BASE_FILTERS` (the status filter per layer id, so b-interact can combine filters), and `attachRendering(map, component)`.
- New layer groups: `freed` (freed ground, default on), `coverage` (internal, zoom 3 to 8, not a toggle), `norecord` ("No recorded tenement", default off), `grid` (one-minute blocks, zoom 11 and up, default off).
- Paint reads feature-state `hover` and `selected` (line width + 1.5 px, fill opacity 0.35).

## Tables from h-data (created by `ingest/pegwatch_ingest/sql/schema.sql`, so the app test database gets them through the existing migration)

- `coverage_lowzoom(status text, type_group text, geom geometry(MultiPolygon, 4326))`
- `freed_ground(tenid text, fmt_tenid text, ended_at timestamp, reason text, holder text, uncovered_ha double precision, geom geometry(MultiPolygon, 4326))`
- `no_recorded_tenement(geom geometry(MultiPolygon, 4326))`
- `recent_changes(tenid text primary key, event_type text, detected_at timestamptz)`: the latest diff event per tenement in the last 30 days
- `places(id serial primary key, name text, kind text, lga text, geom geometry(Point, 4326))`: WA towns and localities
- index `dead_tenements (ended_at)`
- `diff.sql`: special prospecting licences no longer count as blocking ground

## Routes

| Name | Path | Owner |
| --- | --- | --- |
| `watch.start` | `GET /watch?tenement=<slug>` or `?holder=<slug>` or none | f-accounts |
| `watch.confirm` | signed `GET /watch/confirm/{token}` shows a button; `POST` confirms | f-accounts |
| `login.link` | magic-link request and signed confirm (GET shows a button, POST signs in) | f-accounts |
| `tenements.release-ics` | `GET /tenements/{slug}/release.ics` | c-tenement |
| `activity.rss` | `GET /activity.rss` with the same filters as `/activity` | e-activity |
| `data` | `GET /data` | g-visual |
| `og.tenement` | `GET /og/tenements/{slug}.png` | g-visual |
| `og.area` | signed `GET /og/areas/{watchArea}.png` | g-visual |

## Components

- `<x-event-list :events="$events" context="map|activity|tenement|holder" :subject="$label" />` (e-activity): `context` picks the empty-state sentence and sign-up; the stale and baseline sentences come from `Register::latestRun()`.
- `<livewire:watch-this-area />` (f-accounts): the email-first watch panel. f-accounts places it on `/watch` and once at the top of the home page's panel in `map.blade.php` (one line, in its own block).
- Map page configs (b-interact reads them from `tenementMap({...})`): `focus: { features }` (a GeoJSON FeatureCollection whose features carry `fmt_tenid`, `status`, `type_code`; the map colours these by status and mutes the rest), `context: { locator: true, releases: <GeoJSON or null> }` (tenement page: padded fit, WA locator inset, release parcel hatched), `draw: true` (watch page).
