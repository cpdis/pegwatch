# Pegwatch

A WA map of every live, pending and dead mining tenement, with free email
alerts when ground near you is applied for, dies, shrinks or is scheduled
for release.

## Data spike (`ingest/`)

A nightly job that pulls the public SLIP tenement layers into PostGIS and
diffs each snapshot against the last one by tenement ID and by geometry.

```sh
pip install -e "ingest[test]"
createdb pegwatch && psql pegwatch -c 'create extension postgis'
python -m pegwatch_ingest --dsn postgresql:///pegwatch --report-dir report
```

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

Tests need a scratch PostGIS database: `TEST_DATABASE_URL=postgresql:///pegwatch_test pytest ingest/tests`.

Data © State of Western Australia (Department of Mines, Petroleum and
Exploration), CC BY 4.0, changes made.
