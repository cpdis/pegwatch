-- No recorded tenement: WA land (wa_land) with no live or pending blocking
-- tenement over it, less land that s19 closes to mining. Left in, and drawn by
-- the map as restrictions: s57(4) areas (closed to exploration licences only),
-- s57(2aa) mineralisation zones (a limit on licence size) and File Notation
-- Areas (notations, not closures; one, potential Night Parrot habitat, covers
-- 1.1M km2). Exact, not simplified, so its edges meet the tenements'.
create temp table closed_ground on commit drop as
select geom from tenements
where geom is not null and pw_blocks_ground(type_code, special_ind, fmt_tenid)
union all
select ST_Subdivide(geom, 256) from land_restrictions where kind = 's19' and geom is not null;
create index on closed_ground using gist (geom);
analyze closed_ground;

delete from no_recorded_tenement;
insert into no_recorded_tenement (geom)
select ST_Multi(part)
from (
  select ST_Subdivide(uncovered, 256) as part
  from (
    select ST_CollectionExtract(case when c.ground is null then l.geom
                                     else ST_Difference(l.geom, c.ground) end, 3) as uncovered
    from wa_land l
    left join lateral (
      select ST_Union(g.geom) as ground from closed_ground g
      where g.geom && l.geom and ST_Intersects(g.geom, l.geom)
    ) c on true
  ) x
  where not ST_IsEmpty(uncovered)
) y;
