-- Low-zoom state view: the union of current blocking tenements by status and
-- type group (L, G and special prospecting licences left out). Snapped to about
-- 10 m so neighbours merge, simplified to about 50 m (a tenth of a pixel at
-- zoom 8), and cut to 256 vertices a row so tiles clip cheaply.
delete from coverage_lowzoom;
insert into coverage_lowzoom (status, type_group, geom)
select status, type_group, ST_Multi(part)
from (
  select status, type_group,
         ST_Subdivide(ST_CollectionExtract(ST_MakeValid(ST_SimplifyPreserveTopology(ground, 0.0005)), 3), 256) as part
  from (
    select status, case when type_code in ('E', 'P', 'M') then type_code else 'other' end as type_group,
           ST_Union(ST_CollectionExtract(ST_MakeValid(ST_SnapToGrid(geom, 0.0001)), 3)) as ground
    from tenements
    where geom is not null and pw_blocks_ground(type_code, special_ind, fmt_tenid)
    group by 1, 2
  ) u
) s;
