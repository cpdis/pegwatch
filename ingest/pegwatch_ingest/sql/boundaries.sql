-- WA land and places from the ABS boundaries in stg_boundaries (rings as WKB
-- lines, WA rows only). Coordinates are GDA2020, within 2 m of WGS84.
create temp table boundary_areas on commit drop as
select source, props, ST_BuildArea(ST_SetSRID(ST_GeomFromWKB(wkb), 4326)) as geom
from stg_boundaries;

-- Land in 0.25 degree cells, and at most 256 vertices along the coast, so the
-- nightly difference against tenements only meets the tenements nearby.
delete from wa_land;
insert into wa_land (geom)
select ST_Multi(piece)
from (
  select ST_CollectionExtract(case when ST_Within(p.part, c.geom) then p.part
                                   else ST_Intersection(p.part, c.geom) end, 3) as piece
  from (select ST_Subdivide(geom, 256) as part from boundary_areas where source = 'ste') p
  join lateral ST_SquareGrid(0.25, p.part) c on ST_Intersects(c.geom, p.part)
) x
where not ST_IsEmpty(piece);

-- Towns are urban centres and bounded localities (not the rural balance). The
-- ABS adds " (L)" to bounded localities and " (WA)" to names other states share.
delete from places;
insert into places (name, kind, lga, geom)
with towns as (
  select regexp_replace(props->>'UCL_NAME21', '(\s*\((L|WA)\))+$', '') as name, geom
  from boundary_areas
  where source = 'ucl' and props->>'SOS_CODE21' in ('50', '51', '52')
),
localities as (
  select regexp_replace(props->>'SAL_NAME21', '\s*\(WA\)$', '') as name, geom, ST_PointOnSurface(geom) as point
  from boundary_areas
  where source = 'sal'
),
named as (
  -- A town takes the point of its namesake locality when that point is in town:
  -- Perth's is then in the city, not somewhere in the suburbs.
  select t.name, 'town' as kind, coalesce(l.point, ST_PointOnSurface(t.geom)) as geom
  from towns t
  left join lateral (select l.point from localities l
                     where l.name = t.name and ST_Intersects(t.geom, l.point) limit 1) l on true
  union all
  -- A locality over the town of the same name adds nothing to a search.
  select l.name, 'locality', l.point
  from localities l
  where not exists (select 1 from towns t where t.name = l.name and ST_Intersects(l.geom, t.geom))
)
select n.name, n.kind,
       (select a.props->>'LGA_NAME25' from boundary_areas a
         where a.source = 'lga' and ST_Intersects(a.geom, n.geom) limit 1),
       n.geom
from named n;

drop table stg_boundaries;
