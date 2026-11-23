-- Freed ground: for each tenement that died in the last 90 days and closed its
-- ground while it stood (not an L, G or special prospecting licence), the part
-- no current blocking tenement covers, from 0.5 ha. Rebuilt nightly, so ground
-- that is pegged again drops out.
delete from freed_ground;
insert into freed_ground (tenid, fmt_tenid, ended_at, reason, holder, uncovered_ha, geom)
select tenid, fmt_tenid, ended_at, reason, holder, ST_Area(freed::geography) / 10000, ST_Multi(freed)
from (
  select d.*,
         ST_CollectionExtract(case when c.cover is null then d.geom else ST_Difference(d.geom, c.cover) end, 3) as freed
  from (
    select distinct on (tenid) *
    from (
      select tenid, fmt_tenid, ended_at, death_reason as reason, holder_name as holder, geom
      from dead_tenements
      where ended_at >= (now() at time zone 'Australia/Perth') - interval '90 days'
      union all
      -- Deaths the diff saw that the DEAD layer has not published yet.
      select e.tenid, e.fmt_tenid, e.extract_at at time zone 'Australia/Perth', null, e.holders[1], e.geom
      from tenement_events e
      where e.event_type = 'death' and not (e.details ? 'reversed_by_run')
        and e.created_at >= now() - interval '90 days'
        and not exists (select 1 from dead_tenements d
                         where d.tenid = e.tenid
                           and d.ended_at >= (e.extract_at at time zone 'Australia/Perth') - interval '30 days')
    ) deaths
    where geom is not null and pw_blocks_ground(substring(tenid from '^[A-Z]+'), null, fmt_tenid)
    order by tenid, ended_at desc
  ) d
  left join lateral (
    select ST_Union(t.geom) as cover
    from tenements t
    where t.geom && d.geom and ST_Intersects(t.geom, d.geom)
      and pw_blocks_ground(t.type_code, t.special_ind, t.fmt_tenid)
  ) c on true
) f
where ST_Area(freed::geography) >= 5000;
