# Pegwatch

A WA map of every live, pending and dead mining tenement, with free email
alerts when ground near you is applied for, dies, shrinks or is scheduled
for release.

The repo has two parts:

- `ingest/`: a Python job, run nightly by GitHub Actions, that pulls the public
  SLIP layers into PostGIS and records every change as a `tenement_events` row.
- The Laravel app (repo root): Laravel 13, Livewire 4 and Flux (Livewire starter kit), MapLibre, PostGIS.
  Pages are single-file Livewire components in `resources/views/pages/`; the map is an Alpine
  component (`resources/js/map.js`) that loads MapLibre only on pages with a map.
  Public map, tenement and holder pages, an activity feed, and free watch-area
  alerts. It reads the ingest's tables and owns only users and watch areas.

## Running locally

```sh
createdb pegwatch && psql pegwatch -c 'create extension postgis'
pip install -e "ingest[test]"
python -m pegwatch_ingest --dsn postgresql:///pegwatch   # ~1 minute, first run is the baseline

composer install && npm install                     # Flux Pro needs auth.json, see below
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

## Deploying (Laravel Cloud)

1. In Laravel Cloud, choose **+ New application**, then **Connect to GitHub**,
   pick `cpdis/pegwatch`, name it, pick the region nearest Perth (Sydney if the
   Region list offers it) and **Create Application**.
2. In the environment's settings, set the branch to `main`. Add `FLUX_USERNAME` and
   `FLUX_LICENSE_KEY` environment variables (see Flux Pro below) so the build can install Flux Pro.
   - Build command: `composer config http-basic.composer.fluxui.dev "$FLUX_USERNAME" "$FLUX_LICENSE_KEY" && composer install --no-dev && rm -f auth.json && npm ci --audit false && npm run build && php artisan optimize`
   - Deploy command: `php artisan migrate --force`
3. On the environment canvas, **Add database**, then create a **Laravel Serverless
   Postgres** cluster in the same region with a `pegwatch` database. The first
   migration runs `create extension if not exists postgis` itself.
4. Environment variables: `APP_KEY` (from `php artisan key:generate --show`),
   `PEGWATCH_INGEST_TOKEN` (any long random string), and mail settings
   (`MAIL_MAILER`, `MAIL_FROM_ADDRESS`, plus your provider's keys). Cache and
   sessions use the database by default, which suits Cloud's ephemeral disk.
5. **Deploy** from the **Review** button on the pending-changes bar. Settings changes stay pending
   until a deploy started there; a deploy triggered by a push does not apply them. The app gets a free `*.laravel.cloud` domain; add `pegwatch.au`
   later under **Network settings**, then **Add domain**.
6. Point the nightly ingest at the app's database. In Cloud, open
   **Resources**, then **Databases**, then the cluster, then **View
   credentials**. In GitHub (**Settings**, then **Secrets and variables**,
   then **Actions**) add:
   - `PEGWATCH_DATABASE_URL`: `postgresql://USER:PASSWORD@HOST:5432/DATABASE?sslmode=require`, using
     the direct host (no `-pooler`): the ingest relies on temp tables and long transactions
   - `PEGWATCH_HOOK_URL`: `https://<your app>.laravel.cloud/hooks/ingest-complete`
   - `PEGWATCH_INGEST_TOKEN`: the same value as in Cloud
   The next nightly run takes a fresh baseline in that database (about a
   minute); changes and alerts start the night after.
7. Before real users arrive, edit the cluster (**Resources**, then **Databases**,
   the cluster's **...**, then **Edit settings**): point-in-time retention 7 days or more,
   since `tenement_events` cannot be rebuilt from SLIP. Changing the compute range restarts
   the cluster and drops connections for a moment, so do it outside the 7pm AWST ingest.

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

When it writes to the app's database it also saves `pegwatch-history`, a 14-day
artifact holding `tenement_events`, `ingest_runs`, `layer_extracts` and
`mining_notices` (no user data). Restore one with
`pg_restore --no-owner --data-only --dbname "$DATABASE_URL" history.dump` into a database
whose schema already exists.

The snapshot empties the tables the app reads with `DELETE`, not `TRUNCATE`, so pages
keep serving last night's data until the run commits instead of waiting on its lock.

Timestamps: SLIP encodes AWST wall-clock times as if they were UTC. The live
layer's extract is stamped about 4am AWST and holds lodgements to the end of the
previous day. Release pending rows show 8:30am AWST.

Ingest tests need a scratch PostGIS database: `TEST_DATABASE_URL=postgresql:///pegwatch_test pytest ingest/tests`.

Data © State of Western Australia (Department of Mines, Petroleum and
Exploration), CC BY 4.0, changes made.

## Flux Pro

Flux Pro components come from a private Composer repository (`composer.fluxui.dev`), so every
install needs the licence: the username is the email on the Flux account and the password is the
"Pegwatch" licence key.

- Locally: `composer config http-basic.composer.fluxui.dev EMAIL KEY` writes `auth.json`, which is
  gitignored.
- GitHub Actions: a repository secret named `COMPOSER_AUTH` holding
  `{"http-basic":{"composer.fluxui.dev":{"username":"EMAIL","password":"KEY"}}}`.
- Laravel Cloud: environment variables `FLUX_USERNAME` (the email) and `FLUX_LICENSE_KEY`, which
  the build command turns into a throwaway `auth.json`. A raw `COMPOSER_AUTH` JSON value in Cloud's
  environment editor did not authenticate (the first build failed with HTTP 401).
