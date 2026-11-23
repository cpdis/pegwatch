-- Build the new snapshot from staging tables. Runs in one transaction after
-- every stg_* table has been loaded and row counts verified.
-- Geometry: GeoJSON in WGS84 -> valid MultiPolygon.

create or replace function pw_geom(gj text) returns geometry
language sql immutable as $$
  select case when gj is null then null else
    ST_Multi(ST_CollectionExtract(ST_MakeValid(ST_SetSRID(ST_GeomFromGeoJSON(gj), 4326)), 3)) end
$$;

-- Tables the app reads are emptied with DELETE, not TRUNCATE: TRUNCATE takes an
-- ACCESS EXCLUSIVE lock held until this transaction commits (after diff.sql),
-- so every page and tile would hang for the whole run. DELETE lets readers keep
-- seeing last night's rows until the commit. Autovacuum reclaims the dead rows.

-- 1. Rotate tenements -> tenements_prev
delete from tenements_prev;
insert into tenements_prev select * from tenements;
delete from tenements;

insert into tenements (tenid, fmt_tenid, type, type_code, status, survstatus, holders,
                       holder_is_company, legal_area, area_unit, special_ind, extract_at,
                       applied_at, granted_at, ends_at, area_ha, geom)
select distinct on (tenid) *
from (
  select rtrim(props->>'tenid') as tenid,
         coalesce(pw_text(props->'fmt_tenid'), rtrim(props->>'tenid')) as fmt_tenid,
         pw_text(props->'type') as type,
         substring(props->>'tenid' from '^[A-Z]+') as type_code,
         upper(pw_text(props->'tenstatus')) as status,
         pw_text(props->'survstatus') as survstatus,
         h.names as holders,
         (select coalesce(array_agg(pw_is_company(n) order by ord), '{}')
            from unnest(h.names) with ordinality u(n, ord)) as holder_is_company,
         (props->>'legal_area')::numeric as legal_area,
         pw_text(props->'unit_of_me') as area_unit,
         pw_text(props->'special_in') as special_ind,
         pw_ts_local(props->'extract_da') as extract_at,
         pw_local_ts(props->'startdate', props->'starttime') as applied_at,
         pw_local_ts(props->'grantdate', props->'granttime') as granted_at,
         pw_local_ts(props->'enddate', props->'endtime') as ends_at,
         ST_Area(g::geography) / 10000.0 as area_ha,
         g as geom
  from stg_tenements s
  cross join lateral (select pw_geom(s.gj) as g) gg
  cross join lateral (
    select coalesce(array_agg(n order by i), '{}') as names
    from (select i, pw_text(s.props->('holder' || i)) as n from generate_series(1, 9) i) x
    where n is not null
  ) h
  where pw_text(props->'tenid') is not null
) t
-- A tenid split into several rows would be a schema surprise; keep the largest.
order by tenid, area_ha desc nulls last;

-- 2. Dead tenements: upsert the recent window (or the full layer on --full-dead).
-- The layer has one row per holder, so fold the holders of each death together.
insert into dead_tenements (tenid, ended_at, fmt_tenid, type, death_reason, holder_name,
                            holder_is_company, holders, holders_are_company, started_at, granted_at,
                            extract_at, first_seen_run, geom)
select tenid, ended_at, fmt_tenid, type, death_reason, holders[1], pw_is_company(holders[1]),
       holders, (select coalesce(array_agg(pw_is_company(n) order by o), '{}')
                   from unnest(holders) with ordinality u(n, o)),
       started_at, granted_at, extract_at, :run_id, geom
from (
  select rtrim(props->>'tenid') as tenid,
         pw_local_ts(props->'enddate', props->'endtime') as ended_at,
         min(pw_text(props->'fmt_tenid')) as fmt_tenid,
         min(pw_text(props->'type')) as type,
         min(pw_text(props->'deathreason')) as death_reason,
         coalesce(array_agg(distinct pw_text(props->'holdername'))
                    filter (where pw_text(props->'holdername') is not null), '{}') as holders,
         min(pw_local_ts(props->'startdate', props->'starttime')) as started_at,
         min(pw_local_ts(props->'grantdate', props->'granttime')) as granted_at,
         max(pw_ts_local(props->'extract_date')) as extract_at,
         (array_agg(pw_geom(gj) order by oid) filter (where gj is not null))[1] as geom
  from stg_dead
  -- pw_local_ts, not pw_date: some long-void rows carry the 2999-12-31
  -- placeholder end date, which is null here and has no death to record.
  where pw_text(props->'tenid') is not null
    and pw_local_ts(props->'enddate', props->'endtime') is not null
  group by 1, 2
) d
on conflict (tenid, ended_at) do update
  set death_reason = excluded.death_reason,
      extract_at = excluded.extract_at,
      holders = (select coalesce(array_agg(distinct h), '{}')
                   from unnest(dead_tenements.holders || excluded.holders) h),
      holders_are_company = (select coalesce(array_agg(pw_is_company(h) order by h), '{}')
                               from (select distinct unnest(dead_tenements.holders || excluded.holders) h) x),
      holder_name = coalesce(dead_tenements.holder_name, excluded.holder_name),
      holder_is_company = coalesce(dead_tenements.holder_is_company, excluded.holder_is_company),
      geom = coalesce(excluded.geom, dead_tenements.geom);

-- 3. Release pending
delete from release_pending_prev;
insert into release_pending_prev select * from release_pending;
delete from release_pending;
insert into release_pending
select distinct on (rtrim(props->>'tenid'), rtrim(props->>'rlesid'))
       rtrim(props->>'tenid'), rtrim(props->>'rlesid'), pw_text(props->'fmt_tenid'),
       pw_text(props->'ten_type'), pw_text(props->'rles_order'), pw_text(props->'rles_status'),
       pw_ts_local(props->'rles_stamp'), pw_geom(gj)
from stg_release_pending
where pw_text(props->'tenid') is not null and pw_text(props->'rlesid') is not null;

-- 4. Amalgamation and restoration pending
delete from amalgamation_pending_prev;
insert into amalgamation_pending_prev select * from amalgamation_pending;
delete from amalgamation_pending;
insert into amalgamation_pending
select distinct on (rtrim(props->>'tenid'), rtrim(props->>'amalgid'))
       rtrim(props->>'tenid'), rtrim(props->>'amalgid'), pw_text(props->'fmt_tenid'),
       pw_text(props->'ten_type'), pw_text(props->'amalg_status'),
       pw_ts_local(props->'start_stamp'), pw_ts_local(props->'end_stamp'), pw_geom(gj)
from stg_amalgamation_pending
where pw_text(props->'tenid') is not null and pw_text(props->'amalgid') is not null;

delete from restoration_pending;
insert into restoration_pending
select distinct on (rtrim(props->>'tenid'), rtrim(props->>'restnid'))
       rtrim(props->>'tenid'), rtrim(props->>'restnid'), pw_text(props->'fmt_tenid'),
       pw_text(props->'ten_type'), pw_text(props->'restn_status'),
       pw_ts_local(props->'start_stamp'), pw_ts_local(props->'end_stamp'), pw_geom(gj)
from stg_restoration_pending
where pw_text(props->'tenid') is not null and pw_text(props->'restnid') is not null;

-- 5. Land restrictions: replaced wholesale
delete from land_restrictions;
insert into land_restrictions (kind, land_id, name, purpose, status, props, geom)
select 'file_notation', pw_text(props->'fmt_land_i'), pw_text(props->'official_n'),
       pw_text(props->'purpose'), pw_text(props->'status'), props, pw_geom(gj) from stg_file_notation
union all
select 's19', pw_text(props->'land_id'), pw_text(props->'official_name'),
       pw_text(props->'purpose'), pw_text(props->'status'), props, pw_geom(gj) from stg_s19
union all
select 's57_4', pw_text(props->'land_id'), pw_text(props->'official_name'),
       pw_text(props->'purpose'), pw_text(props->'status'), props, pw_geom(gj) from stg_s57_4
union all
select 's57_2aa', pw_text(props->'land_id'), pw_text(props->'official_name'),
       pw_text(props->'purpose'), pw_text(props->'status'), props, pw_geom(gj) from stg_s57_2aa
union all
select 'clearance', null, pw_text(props->'name'),
       pw_text(props->'category'), null, props, pw_geom(gj) from stg_clearance;
