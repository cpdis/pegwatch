# Pegwatch

A WA map of every live, pending and dead mining tenement, with free email
alerts when ground near you is applied for, dies, shrinks or is scheduled
for release.

The repo has two parts:

- `ingest/`: a Python job, run nightly by GitHub Actions, that pulls the public
  SLIP layers into PostGIS and records every change as a `tenement_events` row.
- The Laravel app (repo root): Laravel 12 + Inertia + React, MapLibre, PostGIS.
  Public map, tenement and holder pages, an activity feed, and free watch-area
  alerts. It reads the ingest's tables and owns only users and watch areas.

## Running locally

```sh
createdb pegwatch && psql pegwatch -c 'create extension postgis'
pip install -e "ingest[test]"
python -m pegwatch_ingest --dsn postgresql:///pegwatch   # ~1 minute, first run is the baseline

composer install && npm install
cp .env.example .env && php artisan key:generate   # set DB_USERNAME / DB_PASSWORD
php artisan migrate
composer run dev                                     # http://localhost:8000
```

App tests need PostGIS too: `createdb pegwatch_app_test && psql pegwatch_app_test -c 'create extension postgis' && php artisan test`.

## Map tiles

`/tiles/{tenements|dead|releases|restrictions}/{z}/{x}/{y}.mvt` serves Mapbox
Vector Tiles straight from PostGIS (`ST_AsMVT`), cached per ingest run. The
plan's PMTiles build (tippecanoe in the nightly job, uploaded to R2) can
replace this later without changing the map, since it serves the same layers.
The basemap is OpenFreeMap's Positron style (no key).

## Alerts

After each ingest, `POST /hooks/ingest-complete` (bearer token
`PEGWATCH_INGEST_TOKEN`) or `php artisan pegwatch:send-alerts` matches the
run's events to watch areas and sends one digest email per area. The free plan
has one watch area (a circle up to 50 km) and the death and release alerts.

## Deploying (Laravel Cloud, Sydney)

1. Create the app on Laravel Cloud from this repo, add a Postgres database and
   run `create extension postgis` on it.
2. Set `PEGWATCH_INGEST_TOKEN` (any long random string) and mail settings in
   the app's environment.
3. In GitHub repo secrets, set `PEGWATCH_DATABASE_URL` (the Cloud database
   URL), `PEGWATCH_HOOK_URL` (`https://<app>/hooks/ingest-complete`) and
   `PEGWATCH_INGEST_TOKEN`. The nightly job then writes to the app's database
   and triggers alerts; without them it keeps its own state as an artifact.

## Ingest (`ingest/`)

A nightly job that pulls the public SLIP tenement layers into PostGIS and
diffs each snapshot against the last one by tenement ID and by geometry.

The first run takes a baseline. Each later run writes one `tenement_events`
row per change:

| Event | Trigger |
| --- | --- |
| `application` | new tenid with status PENDING |
| `appeared` | new tenid already LIVE (e.g. a restoration) |
| `death` | tenid gone from the live layer; reason from the DEAD layer (back-filled if it lags), `kind` = ended / converted / amalgamated, `uncovered_share` = share of the area with no other blocking live or pending tenement over it, `covered_any_share` counting every tenement |
| `grant` / `status_change` | PENDING → LIVE, or any other status move |
| `holder_change` | holder set changed (transfer, addition, removal) |
| `shrink` / `grow` | geometry lost or gained at least 0.5 ha and 0.1% (partial surrender) |
| `release_scheduled` / `released` / `release_cancelled` | rows appearing or leaving Release pending (DMIRS-030) |

Safeguards: every layer's row count is checked against the service's own
count, and the run aborts without touching the snapshot if the live layer
comes back more than 5% short. Holder postal addresses are never fetched.

Each run also reads the department's Mining Notices RSS
(`emits.dmp.wa.gov.au/emits/advert/rss.xml`, same-day advertised
applications) and checks that every application lodged before the extract
date is in the layer.

`.github/workflows/nightly-ingest.yml` runs this at 7pm AWST, keeping the
database between runs as a workflow artifact, and posts the report as the
job summary.

Timestamps: SLIP encodes AWST wall-clock times as if they were UTC. The live
layer's extract is stamped about 4am AWST and holds lodgements to the end of the
previous day. Release pending rows show 8:30am AWST.

Ingest tests need a scratch PostGIS database: `TEST_DATABASE_URL=postgresql:///pegwatch_test pytest ingest/tests`.

Data © State of Western Australia (Department of Mines, Petroleum and
Exploration), CC BY 4.0, changes made.
