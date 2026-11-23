# Seed data for local development and screenshots

The integrator builds one seed database and copies it per workstream (`createdb -T pegwatch_seed pegwatch_<key>`). Recipe used on 2026-11-23 (about 10 minutes, mostly the dead layer download):

1. Create the database with PostGIS and pg_trgm:
   `createdb pegwatch_seed && psql pegwatch_seed -c 'create extension postgis; create extension pg_trgm'`
2. Run a real ingest with the full dead history (public SLIP data):
   `python -m pegwatch_ingest --dsn postgresql:///pegwatch_seed --full-dead`
   Result then: 30,610 tenements, 377,404 dead rows going back to 1883, 138 Mining Notices.
3. Perturb the previous snapshot so the next diff produces every common event type, then rerun without fetching:

```sql
begin;
delete from tenements where tenid in (select tenid from tenements where status='PENDING' order by applied_at desc nulls last limit 120);
insert into tenements (tenid, fmt_tenid, type, type_code, status, survstatus, holders, holder_is_company, legal_area, area_unit, extract_at, applied_at, granted_at, ends_at, area_ha, geom)
select distinct on (d.tenid) d.tenid, d.fmt_tenid, d.type, upper(substr(d.fmt_tenid,1,1)), 'LIVE', 'SURVEYED',
       array[coalesce(d.holder_name,'UNKNOWN')], array[coalesce(d.holder_is_company,true)], 100, 'HA.', now(), d.started_at, d.granted_at, null,
       ST_Area(d.geom::geography)/10000, d.geom
from dead_tenements d
where d.ended_at > now() - interval '45 days' and d.geom is not null
  and not exists (select 1 from tenements t where t.tenid = d.tenid)
order by d.tenid, d.ended_at desc limit 60;
update tenements set status='PENDING' where tenid in (select tenid from tenements where status='LIVE' and granted_at > now() - interval '120 days' order by granted_at desc limit 40);
update tenements set holders = array['PREVIOUS HOLDER PTY LTD'], holder_is_company = array[true] where tenid in (select tenid from tenements where status='LIVE' and cardinality(holders) = 1 order by tenid limit 40 offset 500);
update tenements set geom = ST_Multi(ST_Buffer(geom::geography, 300)::geometry) where tenid in (select tenid from tenements where status='LIVE' and type_code='E' order by tenid limit 15 offset 2000);
delete from release_pending;
commit;
```

   `python -m pegwatch_ingest --dsn postgresql:///pegwatch_seed --skip-fetch --no-notices`
   Result then: 283 events (120 applications, 60 deaths, 40 grants, 40 holder changes, 12 shrinks, 11 releases scheduled); the run is marked stale because the extract did not change, which is also what production shows while SLIP is stale.
4. Per workstream: `createdb -T pegwatch_seed pegwatch_<key>`, then `php artisan migrate --force` against it for the app tables.

Measured on the seed (h-data, before it was stopped): a nightly `coverage_lowzoom` rebuild took about 5.8 s (union 5.1 s, simplify and subdivide 0.7 s) and produced 7,188 rows.
