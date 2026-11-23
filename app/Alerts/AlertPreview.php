<?php

namespace App\Alerts;

use App\Mail\WatchAreaDigest;
use App\Models\WatchArea;
use App\Support\Format;
use App\Support\Register;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * A real alert, built from the latest changes on the register, for the
 * sign-in pages and the plans page to show what an alert email says.
 */
final class AlertPreview
{
    /** @return array{area: string, subject: string, items: list<array{id: string, meta: string, line: string}>} */
    public static function sample(): array
    {
        return Cache::remember('alert-preview', 600, function () {
            // The latest death that left ground uncovered, and the next release.
            $rows = DB::select(
                "(select e.id, e.run_id, e.event_type, e.tenid, e.fmt_tenid, e.tenement_type, e.holders, e.extract_at,
                         e.occurred_at, e.details, e.created_at, null::float as lon, null::float as lat
                  from tenement_events e
                  where e.event_type = 'release_scheduled' and not jsonb_exists(e.details, 'reversed_by_run')
                  order by (e.details->>'release_at')::timestamptz > now() desc, e.created_at desc limit 1)
                 union all
                 (select e.id, e.run_id, e.event_type, e.tenid, e.fmt_tenid, e.tenement_type, e.holders, e.extract_at,
                         e.occurred_at, e.details, e.created_at, null, null
                  from tenement_events e
                  where e.event_type = 'death' and coalesce(e.details->>'kind', 'ended') = 'ended'
                    and not jsonb_exists(e.details, 'reversed_by_run')
                    and (e.details->>'area_ha')::numeric * (e.details->>'uncovered_share')::numeric >= ?
                  order by e.created_at desc, (e.details->>'area_ha')::numeric desc limit 1)",
                [AlertMatcher::MIN_UNCOVERED_HA]
            );
            $events = array_map(fn ($e) => Register::presentEvent($e), $rows);
            if (! $events) {
                return self::fallback();
            }
            $area = new WatchArea(['name' => Format::field($events[array_key_last($events)]['fmt_tenid']) ?? 'Kalgoorlie']);

            return [
                'area' => $area->name,
                'subject' => (new WatchAreaDigest($area, $events, null))->envelope()->subject,
                'items' => array_map(fn ($e) => [
                    'id' => $e['fmt_tenid'],
                    'meta' => WatchAreaDigest::typeName($e['tenement_type']),
                    'line' => WatchAreaDigest::line($e),
                ], $events),
            ];
        });
    }

    /** Before the first changes are on file: a made-up but typical alert. */
    private static function fallback(): array
    {
        return [
            'area' => 'Kalgoorlie',
            'subject' => 'Kalgoorlie: 1 release scheduled, 1 tenement dead',
            'items' => [
                ['id' => 'E 26/180', 'meta' => 'Exploration licence', 'line' => 'Part releases Wed 2 Dec 2026, 8:30am AWST (in 9 days). Applications lodged in the first 15 minutes go to ballot.'],
                ['id' => 'P 26/4512', 'meta' => 'Prospecting licence', 'line' => 'Surrendered on 16 Nov 2026. 112 ha uncovered (93% of 120.4 ha): no other live or pending tenement over it on the register.'],
            ],
        ];
    }
}
