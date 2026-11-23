<?php

namespace App\Http\Controllers;

use App\Support\Register;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Mapbox Vector Tiles straight from PostGIS. Tiles are cached per ingest
 * run, so each tile is computed at most once a night. The plan's PMTiles
 * build (tippecanoe in the nightly job) can replace this without changing
 * the map, since both serve the same layers and fields.
 *
 * The route runs without the web middleware, so a tile response carries no
 * cookies and the CDN can cache it. Tiles are stored and sent gzipped.
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

    public function show(Request $request, string $layer, int $z, int $x, int $y): Response
    {
        abort_unless(isset(self::LAYERS[$layer]), 404);
        abort_if($z > 16 || $x < 0 || $y < 0 || $x >= 2 ** $z || $y >= 2 ** $z, 404);

        $def = self::LAYERS[$layer];
        $runId = Register::latestRun()?->id ?? 0;

        $tile = '';
        if ($z >= $def['min_zoom']) {
            $tile = Cache::remember("tile:gz:$runId:$layer:$z:$x:$y", now()->addDay(), function () use ($def, $layer, $z, $x, $y) {
                $inner = str_replace(':z', (string) $z, $def['sql']);
                $row = DB::selectOne(
                    "with b as (select ST_TileEnvelope(?, ?, ?) as env,
                                       ST_Transform(ST_TileEnvelope(?, ?, ?, margin => 0.02), 4326) as env4326,
                                       40075016.68 / (256 * power(2, ?)) as px),
                          mvt as ($inner)
                     select encode(ST_AsMVT(mvt.*, ?, 4096, 'geom'), 'base64') as tile from mvt where geom is not null",
                    [$z, $x, $y, $z, $x, $y, $z, $layer]
                );

                $mvt = base64_decode($row->tile ?? '');

                return $mvt === '' ? '' : base64_encode(gzencode($mvt, 6));
            });
        }

        $headers = [
            'Content-Type' => 'application/vnd.mapbox-vector-tile',
            'Cache-Control' => 'public, max-age=3600',
            'Vary' => 'Accept-Encoding',
        ];
        $body = base64_decode($tile);
        if ($body !== '' && in_array('gzip', $request->getEncodings(), true)) {
            $headers['Content-Encoding'] = 'gzip';
        } elseif ($body !== '') {
            $body = gzdecode($body);
        }

        return response($body, 200, $headers);
    }

    /**
     * The database cache store never deletes a row nobody reads again, and tile
     * keys change with every ingest run. Called after each run so last night's
     * tiles do not pile up in the cache table.
     */
    public static function pruneExpired(): int
    {
        $store = Cache::getStore();
        if (! $store instanceof DatabaseStore) {
            return 0;
        }

        return $store->getConnection()->table(config('cache.stores.database.table', 'cache'))
            ->where('expiration', '<', now()->getTimestamp())->delete();
    }
}
