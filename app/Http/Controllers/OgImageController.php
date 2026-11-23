<?php

namespace App\Http\Controllers;

use App\Models\WatchArea;
use App\Support\OgImage;
use App\Support\Register;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Share images. Both are cached per ingest run (base64, like tiles, so the
 * database cache store can hold them) and served without cookies.
 */
class OgImageController extends Controller
{
    /** Which status colour an event's tenement is drawn in. */
    private const EVENT_STATUS = [
        'death' => 'dead', 'released' => 'release', 'release_scheduled' => 'release',
        'application' => 'pending', 'appeared' => 'pending', 'reappeared' => 'pending', 'grant' => 'live',
    ];

    public function tenement(string $slug): Response
    {
        abort_unless(Register::tenementBrief($slug), 404);
        $runId = Register::latestRun()?->id ?? 0;

        $png = Cache::remember("og:tenement:$runId:$slug", now()->addDay(), fn () => base64_encode(
            OgImage::tenement(Register::tenementBrief($slug, outline: true))
        ));

        return $this->png($png);
    }

    /**
     * A watch area with the tenements that changed in it in one run (the
     * latest unless the signed URL names one), for alert emails. Only the
     * area's alert types count, so the image matches the email. (The route
     * runs without the web middleware, so there is no model binding.)
     */
    public function area(Request $request, int $watchArea): Response
    {
        $watchArea = WatchArea::findOrFail($watchArea);
        $runId = (int) ($request->query('run') ?? Register::latestRun()?->id ?? 0);

        $png = Cache::remember("og:area:$runId:{$watchArea->id}:".$watchArea->updated_at?->timestamp, now()->addDay(), function () use ($watchArea, $runId) {
            $area = DB::scalar('select ST_AsGeoJSON(area, 6) from watch_areas where id = ?', [$watchArea->id]);
            abort_unless($area, 404);
            $events = DB::select(
                'select e.event_type, ST_AsGeoJSON(ST_SimplifyPreserveTopology(e.geom, 0.0001), 5) as geojson
                 from tenement_events e join watch_areas w on w.id = ?
                 where e.run_id = ? and e.geom && w.area and ST_Intersects(e.geom, w.area)
                   and e.event_type = any(array(select jsonb_array_elements_text(w.event_types)))
                 order by e.id limit 200',
                [$watchArea->id, $runId]
            );
            $context = array_column(DB::select(
                'select ST_AsGeoJSON(ST_SimplifyPreserveTopology(t.geom, 0.0005), 4) as g
                 from tenements t join watch_areas w on w.id = ?
                 where t.geom && w.area and ST_Intersects(t.geom, w.area) limit 1500',
                [$watchArea->id]
            ), 'g');
            $n = count($events);

            return base64_encode(OgImage::area(
                $watchArea->name,
                $area,
                array_map(fn ($e) => ['geojson' => $e->geojson, 'status' => self::EVENT_STATUS[$e->event_type] ?? 'other'], $events),
                $context,
                trans_choice('{0} No changes in this extract|{1} 1 change in this extract|[2,*] :n changes in this extract', $n, ['n' => $n]),
            ));
        });

        return $this->png($png);
    }

    private function png(string $base64): Response
    {
        return response(base64_decode($base64), 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
