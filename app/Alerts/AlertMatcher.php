<?php

namespace App\Alerts;

use App\Mail\WatchAreaDigest;
use App\Models\WatchArea;
use App\Support\Register;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Matches an ingest run's events to watch areas and sends one digest email
 * per watch area. Safe to run twice: deliveries are unique per area and event.
 */
class AlertMatcher
{
    /** @return array{matched: int, emails: int} */
    public function run(int $runId): array
    {
        $matched = DB::affectingStatement(
            'insert into alert_deliveries (watch_area_id, tenement_event_id, created_at, updated_at)
             select w.id, e.id, now(), now()
             from watch_areas w
             join tenement_events e on e.geom && w.area and ST_Intersects(e.geom, w.area)
             where e.run_id = ? and w.event_types @> jsonb_build_array(e.event_type)
             on conflict (watch_area_id, tenement_event_id) do nothing',
            [$runId]
        );

        $emails = 0;
        $areaIds = DB::table('alert_deliveries')->whereNull('sent_at')->distinct()->pluck('watch_area_id');
        foreach (WatchArea::with('user')->whereIn('id', $areaIds)->get() as $area) {
            $deliveries = DB::table('alert_deliveries')->where('watch_area_id', $area->id)
                ->whereNull('sent_at')->pluck('tenement_event_id')->all();
            if (! $deliveries) {
                continue;
            }
            $events = array_map(fn ($e) => Register::presentEvent($e), DB::select(
                'select e.id, e.run_id, e.event_type, e.tenid, e.fmt_tenid, e.tenement_type, e.holders,
                        e.extract_at, e.occurred_at, e.details, e.created_at,
                        ST_X(ST_PointOnSurface(e.geom)) as lon, ST_Y(ST_PointOnSurface(e.geom)) as lat
                 from tenement_events e where e.id = any(?::bigint[]) order by e.event_type, e.fmt_tenid',
                ['{'.implode(',', $deliveries).'}']
            ));
            Mail::to($area->user)->send(new WatchAreaDigest($area, $events, Register::latestRun()));
            DB::table('alert_deliveries')->where('watch_area_id', $area->id)
                ->whereIn('tenement_event_id', $deliveries)->update(['sent_at' => now()]);
            $emails++;
        }

        return ['matched' => $matched, 'emails' => $emails];
    }
}
