-- Diff tenements_prev (last run) against tenements (this run) and write
-- tenement_events. The run id placeholder is substituted by the runner. Skipped entirely
-- on the first (baseline) run, when tenements_prev is empty.

-- Types that may overlap other tenure (Mining Act: miscellaneous licences,
-- general purpose leases), so they do not count as "covering" ground.
create or replace function pw_blocks_ground(type_code text) returns boolean
language sql immutable as $$ select type_code not in ('L', 'G') $$;

create or replace function pw_sorted(a text[]) returns text[]
language sql immutable as $$ select coalesce(array_agg(distinct x order by x), '{}') from unnest(a) x $$;

-- 1. New applications, tenements that appear already live (e.g. restorations), and
-- tenements we saw die that are back (a restoration, or a gap in the layer).
insert into tenement_events (run_id, event_type, tenid, fmt_tenid, tenement_type, holders,
                             extract_at, occurred_at, details, geom)
select :run_id,
       case when exists (select 1 from tenement_events e where e.tenid = c.tenid and e.event_type = 'death')
              then 'reappeared'
            when c.status = 'PENDING' then 'application'
            else 'appeared' end,
       c.tenid, c.fmt_tenid, c.type, c.holders, c.extract_at, c.applied_at,
       jsonb_build_object('status', c.status, 'area_ha', round(c.area_ha::numeric, 2),
                          'legal_area', c.legal_area, 'area_unit', c.area_unit,
                          'restored', exists (select 1 from restoration_pending r where r.tenid = c.tenid)),
       c.geom
from tenements c
where not exists (select 1 from tenements_prev p where p.tenid = c.tenid);

-- A reappearance means an earlier death did not stick: mark it so it is not counted.
update tenement_events e
set details = e.details || jsonb_build_object('reversed_by_run', :run_id)
from tenement_events r
where r.run_id = :run_id and r.event_type = 'reappeared' and r.tenid = e.tenid
  and e.event_type = 'death' and not (e.details ? 'reversed_by_run');

-- 2. Deaths: in last snapshot, gone from this one.
with gone as (
  select p.* from tenements_prev p
  where not exists (select 1 from tenements c where c.tenid = p.tenid)
),
classified as (
  select g.*,
         d.death_reason, d.ended_at,
         -- Converted: a brand new tenement of a different type, sharing a holder,
         -- over at least 90% of the dead one (e.g. P -> M, E -> M).
         (select c.tenid from tenements c
           where c.geom && g.geom
             and c.type_code is distinct from g.type_code
             and not exists (select 1 from tenements_prev p2 where p2.tenid = c.tenid)
             and c.holders && g.holders
             and ST_Area(ST_Intersection(c.geom, g.geom)) >= 0.9 * nullif(ST_Area(g.geom), 0)
           order by ST_Area(ST_Intersection(c.geom, g.geom)) desc limit 1) as converted_to,
         -- Amalgamated: layer 20 keys a pending amalgamation by the licence that
         -- receives the ground; its parcels are the ground coming in. So a dead
         -- tenement was amalgamated when most of it lay under another licence's parcels.
         (select a.amalgid || ' into ' || coalesce(a.fmt_tenid, a.tenid)
            from amalgamation_pending_prev a
           where a.tenid <> g.tenid and a.geom && g.geom
             and ST_Area(ST_Intersection(a.geom, g.geom)) >= 0.5 * nullif(ST_Area(g.geom), 0)
           order by ST_Area(ST_Intersection(a.geom, g.geom)) desc limit 1) as amalgamation,
         -- Share of the area still under any live or pending tenement / a blocking one.
         (select ST_Union(c.geom) from tenements c
           where c.geom && g.geom and ST_Intersects(c.geom, g.geom)) as cover_any,
         (select ST_Union(c.geom) from tenements c
           where c.geom && g.geom and ST_Intersects(c.geom, g.geom)
             and pw_blocks_ground(c.type_code)) as cover_blocking
  from gone g
  left join lateral (
    -- Only a death recorded around now: an old row is an earlier death of a
    -- tenement that was later restored.
    select death_reason, ended_at from dead_tenements d
    where d.tenid = g.tenid
      and d.ended_at >= (g.extract_at at time zone 'Australia/Perth') - interval '30 days'
    order by ended_at desc limit 1
  ) d on true
),
measured as (
  select k.*,
         case when cover_blocking is null then g_area
              else ST_Area(ST_Difference(geom, cover_blocking)::geography) end as uncovered_m2,
         case when cover_any is null then 0
              else ST_Area(ST_Intersection(geom, cover_any)::geography) end as covered_any_m2
  from (select c.*, nullif(ST_Area(c.geom::geography), 0) as g_area from classified c) k
)
insert into tenement_events (run_id, event_type, tenid, fmt_tenid, tenement_type, holders,
                             extract_at, occurred_at, details, geom)
select :run_id, 'death', m.tenid, m.fmt_tenid, m.type, m.holders,
       (select max(extract_at) from tenements), m.ended_at,
       jsonb_build_object(
         'previous_status', m.status,
         'death_reason', m.death_reason,                  -- null until the DEAD layer catches up
         'kind', case when m.amalgamation is not null then 'amalgamated'
                      when m.converted_to is not null then 'converted'
                      else 'ended' end,
         'converted_to', m.converted_to,
         'amalgamation_id', m.amalgamation,
         'area_ha', round((m.g_area / 10000.0)::numeric, 2),
         'uncovered_share', round(least(1, greatest(0, m.uncovered_m2 / m.g_area))::numeric, 4),
         'covered_any_share', round(least(1, greatest(0, m.covered_any_m2 / m.g_area))::numeric, 4)),
       m.geom
from measured m;

-- 3. Status changes on tenements in both snapshots
insert into tenement_events (run_id, event_type, tenid, fmt_tenid, tenement_type, holders,
                             extract_at, occurred_at, details, geom)
select :run_id,
       case when p.status = 'PENDING' and c.status = 'LIVE' then 'grant' else 'status_change' end,
       c.tenid, c.fmt_tenid, c.type, c.holders, c.extract_at,
       case when c.status = 'LIVE' then c.granted_at end,
       jsonb_build_object('from', p.status, 'to', c.status, 'ends_at', c.ends_at),
       c.geom
from tenements c join tenements_prev p using (tenid)
where p.status is distinct from c.status;

-- 4. Holder changes (transfers, additions, removals)
insert into tenement_events (run_id, event_type, tenid, fmt_tenid, tenement_type, holders,
                             extract_at, occurred_at, details, geom)
select :run_id, 'holder_change', c.tenid, c.fmt_tenid, c.type, c.holders, c.extract_at, null,
       jsonb_build_object('from', to_jsonb(p.holders), 'to', to_jsonb(c.holders),
         'added', to_jsonb(array(select unnest(c.holders) except select unnest(p.holders))),
         'removed', to_jsonb(array(select unnest(p.holders) except select unnest(c.holders)))),
       c.geom
from tenements c join tenements_prev p using (tenid)
where pw_sorted(p.holders) <> pw_sorted(c.holders)
  and (exists (select unnest(c.holders) except select unnest(p.holders))
       or exists (select unnest(p.holders) except select unnest(c.holders)));

-- 5. Geometry changes: partial surrender (shrink) or added ground (grow).
-- Ignore slivers under 0.5 ha or 0.1% of the tenement, which are re-digitising noise.
with changed as (
  select c.tenid, c.fmt_tenid, c.type, c.holders, c.extract_at, p.geom as old_geom, c.geom as new_geom,
         ST_Difference(p.geom, c.geom) as lost, ST_Difference(c.geom, p.geom) as gained,
         ST_Area(p.geom::geography) as old_m2
  from tenements c join tenements_prev p using (tenid)
  where ST_AsBinary(p.geom) is distinct from ST_AsBinary(c.geom)
    and p.geom is not null and c.geom is not null
),
sized as (
  select *, coalesce(ST_Area(lost::geography), 0) as lost_m2,
            coalesce(ST_Area(gained::geography), 0) as gained_m2
  from changed
)
insert into tenement_events (run_id, event_type, tenid, fmt_tenid, tenement_type, holders,
                             extract_at, occurred_at, details, geom)
select :run_id, e.kind, s.tenid, s.fmt_tenid, s.type, s.holders, s.extract_at, null,
       jsonb_build_object('area_ha_before', round((s.old_m2 / 10000)::numeric, 2),
                          'area_ha_after', round((ST_Area(s.new_geom::geography) / 10000)::numeric, 2),
                          'changed_ha', round((e.m2 / 10000)::numeric, 2),
                          'released', exists (select 1 from release_pending_prev r
                                               where r.tenid = s.tenid
                                                 and not exists (select 1 from release_pending r2
                                                                  where r2.tenid = r.tenid and r2.rlesid = r.rlesid))),
       ST_Multi(ST_CollectionExtract(e.g, 3))
from sized s
cross join lateral (values ('shrink', s.lost, s.lost_m2), ('grow', s.gained, s.gained_m2)) e(kind, g, m2)
where e.m2 >= 5000 and e.m2 >= 0.001 * s.old_m2;

-- 6. Release pending: newly scheduled, released (time passed), or withdrawn
insert into tenement_events (run_id, event_type, tenid, fmt_tenid, tenement_type, holders,
                             extract_at, occurred_at, details, geom)
select :run_id, 'release_scheduled', r.tenid, r.fmt_tenid, r.ten_type,
       (select holders from tenements t where t.tenid = r.tenid), null,
       (r.release_at at time zone 'Australia/Perth'),
       jsonb_build_object('release_id', r.rlesid, 'release_at', r.release_at,
                          'area_ha', round((ST_Area(r.geom::geography) / 10000)::numeric, 2)),
       r.geom
from release_pending r
where not exists (select 1 from release_pending_prev p where p.tenid = r.tenid and p.rlesid = r.rlesid);

-- A scheduled release moved to another time
insert into tenement_events (run_id, event_type, tenid, fmt_tenid, tenement_type, holders,
                             extract_at, occurred_at, details, geom)
select :run_id, 'release_scheduled', r.tenid, r.fmt_tenid, r.ten_type,
       (select holders from tenements t where t.tenid = r.tenid), null,
       (r.release_at at time zone 'Australia/Perth'),
       jsonb_build_object('release_id', r.rlesid, 'release_at', r.release_at,
                          'rescheduled_from', p.release_at,
                          'area_ha', round((ST_Area(r.geom::geography) / 10000)::numeric, 2)),
       r.geom
from release_pending r
join release_pending_prev p on p.tenid = r.tenid and p.rlesid = r.rlesid
where p.release_at is distinct from r.release_at;

insert into tenement_events (run_id, event_type, tenid, fmt_tenid, tenement_type, holders,
                             extract_at, occurred_at, details, geom)
select :run_id,
       case when p.release_at <= now() then 'released' else 'release_cancelled' end,
       p.tenid, p.fmt_tenid, p.ten_type,
       (select holders from tenements t where t.tenid = p.tenid), null,
       (p.release_at at time zone 'Australia/Perth'),
       jsonb_build_object('release_id', p.rlesid, 'release_at', p.release_at),
       p.geom
from release_pending_prev p
where not exists (select 1 from release_pending r where r.tenid = p.tenid and r.rlesid = p.rlesid);

-- 7. Back-fill death reasons that the DEAD layer published after we saw the death
update tenement_events e
set details = e.details || jsonb_build_object('death_reason', d.death_reason),
    occurred_at = coalesce(e.occurred_at, d.ended_at)
from dead_tenements d
where e.event_type = 'death'
  and e.details->>'death_reason' is null
  and d.tenid = e.tenid
  and d.ended_at >= (e.extract_at at time zone 'Australia/Perth') - interval '30 days';
