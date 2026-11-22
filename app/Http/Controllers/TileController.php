<?php

namespace App\Http\Controllers;

use App\Support\Register;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Mapbox Vector Tiles straight from PostGIS. Tiles are cached per ingest
 * run, so each tile is computed at most once a night. The plan's PMTiles
 * build (tippecanoe in the nightly job) can replace this without changing
 * the map, since both serve the same layers and fields.
 */
class TileController extends Controller
{
    private const LAYERS = [
        'tenements' => [
            'min_zoom' => 3,
            // Below z9, simplify to about a pixel and drop tenements smaller than a few pixels.
            'sql' => 'select t.fmt_tenid, t.type_code, t.status, t.holders[1] as holder,
                             ST_AsMVTGeom(case when :z >= 9 then ST_Transform(t.geom, 3857)
                                               else ST_Simplify(ST_Transform(t.geom, 3857), b.px) end,
                                          b.env, 4096, 16, true) as geom
                      from tenements t, b
                      where t.geom && b.env4326
                        and (:z >= 9 or t.area_ha * 10000 >= 0.05 * b.px * b.px)',
        ],
        'dead' => [
            'min_zoom' => 6,
            'sql' => "select d.fmt_tenid, d.death_reason as reason, to_char(d.ended_at, 'YYYY-MM-DD') as ended,
                             ST_AsMVTGeom(ST_Transform(d.geom, 3857), b.env, 4096, 16, true) as geom
                      from dead_tenements d, b
                      where d.geom && b.env4326 and d.ended_at >= now() - interval '12 months'",
        ],
        'releases' => [
            'min_zoom' => 0,
            'sql' => "select r.fmt_tenid, r.rlesid, to_char(r.release_at at time zone 'Australia/Perth', 'YYYY-MM-DD HH24:MI') as release_at,
                             ST_AsMVTGeom(ST_Transform(r.geom, 3857), b.env, 4096, 16, true) as geom
                      from release_pending r, b where r.geom && b.env4326",
        ],
        'restrictions' => [
            'min_zoom' => 5,
            'sql' => 'select l.kind, l.name, l.purpose,
                             ST_AsMVTGeom(ST_Transform(l.geom, 3857), b.env, 4096, 16, true) as geom
                      from land_restrictions l, b where l.geom && b.env4326',
        ],
    ];

    public function show(string $layer, int $z, int $x, int $y): Response
    {
        abort_unless(isset(self::LAYERS[$layer]), 404);
        abort_if($z > 16 || $x < 0 || $y < 0 || $x >= 2 ** $z || $y >= 2 ** $z, 404);

        $def = self::LAYERS[$layer];
        $runId = Register::latestRun()?->id ?? 0;

        $tile = '';
        if ($z >= $def['min_zoom']) {
            $tile = Cache::remember("tile:$runId:$layer:$z:$x:$y", now()->addDay(), function () use ($def, $layer, $z, $x, $y) {
                $inner = str_replace(':z', (string) $z, $def['sql']);
                $row = DB::selectOne(
                    "with b as (select ST_TileEnvelope(?, ?, ?) as env,
                                       ST_Transform(ST_TileEnvelope(?, ?, ?, margin => 0.02), 4326) as env4326,
                                       40075016.68 / (256 * power(2, ?)) as px),
                          mvt as ($inner)
                     select encode(ST_AsMVT(mvt.*, ?, 4096, 'geom'), 'base64') as tile from mvt where geom is not null",
                    [$z, $x, $y, $z, $x, $y, $z, $layer]
                );

                return $row->tile ?? '';
            });
        }

        return response(base64_decode($tile), 200, [
            'Content-Type' => 'application/vnd.mapbox-vector-tile',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
