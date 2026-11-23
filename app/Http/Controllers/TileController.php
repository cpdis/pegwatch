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
    /**
     * Tables the nightly job builds that may not exist yet. Until one does, SQL
     * that names it as `{table}` reads an empty relation with the same columns,
     * so its layer serves an empty tile instead of an error.
     */
    private const OPTIONAL_TABLES = [
        'coverage_lowzoom' => 'null::text as status, null::text as type_group, null::geometry as geom',
        'freed_ground' => 'null::text as tenid, null::text as fmt_tenid, null::timestamp as ended_at, null::text as reason,
                           null::text as holder, null::float8 as uncovered_ha, null::geometry as geom',
        'no_recorded_tenement' => 'null::geometry as geom',
        'recent_changes' => 'null::text as tenid, null::text as event_type, null::timestamptz as detected_at',
    ];

    /**
     * Each tile URL serves one or more MVT layers. Layer SQL reads the CTE `b`
     * (the tile envelope `env`, its 4326 bounds with a margin `env4326`, and
     * metres per pixel `px`); `{z}` is the zoom. Areas are hectares, one
     * decimal under 10 ha. Register dates are wall-clock timestamps, so
     * `to_char` gives the AWST date as the register shows it.
     */
    private const TILES = [
        'tenements' => [
            'min_zoom' => 3,
            'layers' => [
                // From z8 the hover card's fields join in and every tenement keeps its shape,
                // simplified only within half a tile unit (px / 32), which the 4096 grid rounds
                // away anyway. Below z8, simplify to about a pixel and drop tenements smaller
                // than a few pixels: the coverage layer carries the true extent there.
                'tenements' => "select t.fmt_tenid, t.type_code, t.status, t.holders[1] as holder, d.*,
                                       ST_AsMVTGeom(ST_Simplify(ST_Transform(t.geom, 3857), case when {z} >= 8 then b.px / 32 else b.px end, {z} >= 8),
                                                    b.env, 4096, 16, true) as geom
                                from tenements t
                                cross join b
                                left join lateral (
                                    select t.tenid, cardinality(t.holders) as holders_n,
                                           round(t.area_ha::numeric, (t.area_ha < 10)::int)::float8 as ha,
                                           to_char(t.applied_at, 'YYYY-MM-DD') as applied,
                                           -- The card reads the grant date only when there is no expiry to show.
                                           case when t.ends_at is null or t.ends_at >= timestamp '2999-01-01' then to_char(t.granted_at, 'YYYY-MM-DD') end as granted,
                                           case when t.ends_at < timestamp '2999-01-01' then to_char(t.ends_at, 'YYYY-MM-DD') end as ends,
                                           (select to_char(min(r.release_at at time zone 'Australia/Perth'), 'YYYY-MM-DD HH24:MI')
                                              from release_pending r where r.tenid = t.tenid) as rel_at,
                                           t.survstatus,
                                           nullif(t.special_ind = 'S' or t.fmt_tenid like '%S', false) as special,
                                           c.event_type as changed_type,
                                           to_char(c.detected_at at time zone 'Australia/Perth', 'YYYY-MM-DD') as changed_at
                                    from (select) one left join {recent_changes} c on c.tenid = t.tenid
                                ) d on {z} >= 8
                                where t.geom && b.env4326
                                  and ({z} >= 8 or t.area_ha * 10000 >= 0.05 * b.px * b.px)",
                // One label point per tenement from z12, so a tenement cut by tile edges is
                // labelled once. It carries the fields map filters read, so labels filter too.
                'labels' => "select t.tenid, t.fmt_tenid, t.type_code, t.status,
                                    round(t.area_ha::numeric, (t.area_ha < 10)::int)::float8 as ha,
                                    to_char(t.applied_at, 'YYYY-MM-DD') as applied,
                                    case when t.ends_at < timestamp '2999-01-01' then to_char(t.ends_at, 'YYYY-MM-DD') end as ends,
                                    ST_AsMVTGeom(ST_Transform(ST_PointOnSurface(t.geom), 3857), b.env, 4096, 0, true) as geom
                             from tenements t, b where {z} >= 12 and t.geom && b.env4326",
            ],
        ],
        // The state view: held and applied-for ground unioned nightly, so nothing is dropped.
        'coverage' => [
            'min_zoom' => 3,
            'max_zoom' => 8,
            'layers' => [
                'coverage' => 'select c.status, c.type_group,
                                      ST_AsMVTGeom(ST_Simplify(ST_Transform(c.geom, 3857), b.px / 2), b.env, 4096, 64, true) as geom
                               from {coverage_lowzoom} c, b where c.geom && b.env4326',
            ],
        ],
        'dead' => [
            'min_zoom' => 6,
            'layers' => [
                'dead' => "select d.tenid, d.fmt_tenid, split_part(d.fmt_tenid, ' ', 1) as type_code,
                                  d.death_reason as reason, to_char(d.ended_at, 'YYYY-MM-DD') as ended,
                                  coalesce(d.holders[1], d.holder_name) as holder,
                                  round(a.ha::numeric, (a.ha < 10)::int)::float8 as ha,
                                  ST_AsMVTGeom(ST_Transform(d.geom, 3857), b.env, 4096, 16, true) as geom
                           from dead_tenements d
                           cross join b
                           cross join lateral (select ST_Area(d.geom::geography) / 10000 as ha) a
                           where d.geom && b.env4326 and d.ended_at >= now() - interval '12 months'",
            ],
        ],
        // Release parcels, plus a point per parcel so every scheduled release shows at any zoom.
        'releases' => [
            'min_zoom' => 0,
            'layers' => [
                'releases' => "select r.tenid, r.fmt_tenid, r.rlesid,
                                      to_char(r.release_at at time zone 'Australia/Perth', 'YYYY-MM-DD HH24:MI') as release_at,
                                      t.holders[1] as holder,
                                      round(a.ha::numeric, (a.ha < 10)::int)::float8 as ha,
                                      ST_AsMVTGeom(ST_Transform(r.geom, 3857), b.env, 4096, 16, true) as geom
                               from release_pending r
                               cross join b
                               cross join lateral (select ST_Area(r.geom::geography) / 10000 as ha) a
                               left join tenements t on t.tenid = r.tenid
                               where r.geom && b.env4326",
                'release-points' => "select r.tenid, r.fmt_tenid, r.rlesid,
                                            to_char(r.release_at at time zone 'Australia/Perth', 'YYYY-MM-DD HH24:MI') as release_at,
                                            to_char(r.release_at at time zone 'Australia/Perth', 'Dy FMDD Mon, FMHH12:MIam') as label,
                                            ST_AsMVTGeom(ST_Transform(ST_PointOnSurface(r.geom), 3857), b.env, 4096, 0, true) as geom
                                     from release_pending r, b where r.geom && b.env4326",
            ],
        ],
        // Ground a recent death left with no blocking tenement over it. Shapes from z8,
        // where they are big enough to see; a point marks each one when zoomed out.
        'freed' => [
            'min_zoom' => 3,
            'layers' => [
                'freed' => "select f.tenid, f.fmt_tenid, to_char(f.ended_at, 'YYYY-MM-DD') as ended, f.reason, f.holder,
                                   round(f.uncovered_ha::numeric, (f.uncovered_ha < 10)::int)::float8 as uncovered_ha,
                                   ST_AsMVTGeom(ST_Transform(f.geom, 3857), b.env, 4096, 16, true) as geom
                            from {freed_ground} f, b where {z} >= 8 and f.geom && b.env4326",
                'freed-points' => 'select f.tenid, f.fmt_tenid,
                                          ST_AsMVTGeom(ST_Transform(ST_PointOnSurface(f.geom), 3857), b.env, 4096, 0, true) as geom
                                   from {freed_ground} f, b where {z} < 10 and f.geom && b.env4326',
            ],
        ],
        'norecord' => [
            'min_zoom' => 6,
            'layers' => [
                'norecord' => 'select ST_AsMVTGeom(case when {z} >= 10 then ST_Transform(n.geom, 3857)
                                                        else ST_Simplify(ST_Transform(n.geom, 3857), b.px / 2) end,
                                                   b.env, 4096, 16, true) as geom
                               from {no_recorded_tenement} n, b where n.geom && b.env4326',
            ],
        ],
        'restrictions' => [
            'min_zoom' => 5,
            'layers' => [
                'restrictions' => 'select l.kind, l.name, l.purpose,
                                          ST_AsMVTGeom(ST_Transform(l.geom, 3857), b.env, 4096, 16, true) as geom
                                   from land_restrictions l, b where l.geom && b.env4326',
            ],
        ],
    ];

    public function show(Request $request, string $layer, int $z, int $x, int $y): Response
    {
        abort_unless(isset(self::TILES[$layer]), 404);
        abort_if($z > 16 || $x < 0 || $y < 0 || $x >= 2 ** $z || $y >= 2 ** $z, 404);

        $def = self::TILES[$layer];
        // A tile changes when a run lands or when this file's SQL changes on deploy.
        $version = (Register::latestRun()?->id ?? 0).'-'.substr(md5(serialize(self::TILES)), 0, 8);

        $headers = [
            'Content-Type' => 'application/vnd.mapbox-vector-tile',
            // Tiles change once a night. A CDN may keep one an hour and serve it stale
            // while it revalidates; the ETag makes that revalidation a 304.
            'Cache-Control' => 'public, max-age=3600, s-maxage=3600, stale-while-revalidate=86400',
            'Vary' => 'Accept-Encoding',
        ];
        $response = response('', 200, $headers)->setEtag($version, weak: true);
        if ($response->isNotModified($request)) {
            return $response;
        }

        $tile = '';
        if ($z >= $def['min_zoom'] && $z <= ($def['max_zoom'] ?? 16)) {
            $tile = Cache::remember("tile:gz:$version:$layer:$z:$x:$y", now()->addDay(), fn () => self::render($def['layers'], $z, $x, $y));
        }

        $body = base64_decode($tile);
        if ($body !== '' && in_array('gzip', $request->getEncodings(), true)) {
            $response->headers->set('Content-Encoding', 'gzip');
        } elseif ($body !== '') {
            $body = gzdecode($body);
        }

        return $response->setContent($body);
    }

    /**
     * One tile as base64 of its gzipped bytes, '' when empty. A missing optional
     * table only appears with the nightly schema, which also starts a new run
     * and so new cache keys: caching a tile drawn without it is safe.
     *
     * @param  array<string, string>  $layers
     */
    private static function render(array $layers, int $z, int $x, int $y): string
    {
        $tables = [];
        foreach (self::OPTIONAL_TABLES as $name => $columns) {
            if (str_contains(implode(' ', $layers), '{'.$name.'}')) {
                $exists = (bool) DB::scalar('select to_regclass(?) is not null', [$name]);
                $tables['{'.$name.'}'] = $exists ? $name : "(select $columns where false)";
            }
        }

        $ctes = $parts = [];
        foreach (array_values($layers) as $i => $sql) {
            $ctes[] = "l$i as (".strtr($sql, ['{z}' => (string) $z] + $tables).')';
            $parts[] = "coalesce((select ST_AsMVT(l$i.*, ?, 4096, 'geom') from l$i where geom is not null), '')";
        }

        $row = DB::selectOne(
            'with b as (select ST_TileEnvelope(?, ?, ?) as env,
                               ST_Transform(ST_TileEnvelope(?, ?, ?, margin => 0.02), 4326) as env4326,
                               40075016.68 / (256 * power(2, ?)) as px),
                  '.implode(",\n", $ctes).'
             select encode('.implode(' || ', $parts).", 'base64') as tile",
            [$z, $x, $y, $z, $x, $y, $z, ...array_keys($layers)]
        );

        $mvt = base64_decode($row->tile ?? '');

        return $mvt === '' ? '' : base64_encode(gzencode($mvt, 6));
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
