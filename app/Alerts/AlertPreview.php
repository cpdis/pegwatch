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
        return Cache::remember('alert-preview:v2', 600, function () {
            // Recent releases and deaths that left ground uncovered, so one field can be
            // found that has both: a real alert is for one area, not the whole state.
            $rows = DB::select(
                "(select e.id, e.run_id, e.event_type, e.tenid, e.fmt_tenid, e.tenement_type, e.holders, e.extract_at,
                         e.occurred_at, e.details, e.created_at, null::float as lon, null::float as lat
                  from tenement_events e
                  where e.event_type = 'release_scheduled' and not jsonb_exists(e.details, 'reversed_by_run')
                  order by (e.details->>'release_at')::timestamptz > now() desc, e.created_at desc limit 50)
                 union all
                 (select e.id, e.run_id, e.event_type, e.tenid, e.fmt_tenid, e.tenement_type, e.holders, e.extract_at,
                         e.occurred_at, e.details, e.created_at, null, null
                  from tenement_events e
                  where e.event_type = 'death' and coalesce(e.details->>'kind', 'ended') = 'ended'
                    and not jsonb_exists(e.details, 'reversed_by_run')
                    and (e.details->>'area_ha')::numeric * (e.details->>'uncovered_share')::numeric >= ?
                  order by e.created_at desc, (e.details->>'area_ha')::numeric desc limit 50)",
                [AlertMatcher::MIN_UNCOVERED_HA]
            );
            $events = self::oneArea(array_map(fn ($e) => Register::presentEvent($e), $rows));
            if (! $events) {
                return self::fallback();
            }
            $area = new WatchArea(['name' => Format::field($events[0]['fmt_tenid']) ?? 'Western Australia']);

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

    /**
     * The first release and death that share a mineral field, release first; else the
     * latest event alone. Rows arrive releases first, each kind newest first.
     */
    private static function oneArea(array $events): array
    {
        $releases = array_values(array_filter($events, fn ($e) => $e['type'] === 'release_scheduled'));
        $deaths = array_values(array_filter($events, fn ($e) => $e['type'] === 'death'));
        foreach ($releases as $r) {
            foreach ($deaths as $d) {
                if (Format::fieldNumber($r['fmt_tenid']) !== null && Format::fieldNumber($r['fmt_tenid']) === Format::fieldNumber($d['fmt_tenid'])) {
                    return [$r, $d];
                }
            }
        }

        return array_slice($releases ?: $deaths, 0, 1);
    }

    /** Before the first changes are on file: a made-up but typical alert. */
    private static function fallback(): array
    {
        return [
            'area' => 'Kalgoorlie',
            'subject' => 'Kalgoorlie: 1 release scheduled, 1 tenement dead',
            'items' => [
                ['id' => 'E 26/180', 'meta' => 'Exploration licence', 'line' => 'Part releases Wed 2 Dec 2026, 8:30am AWST (in 9 days). Applications lodged in the first 15 minutes go to ballot.'],
                ['id' => 'P 26/4512', 'meta' => 'Prospecting licence', 'line' => 'Surrendered on 16 Nov 2026. 112.0 ha uncovered (93% of 120.4 ha): no other live or pending tenement over it on the register, '.Format::UNCOUNTED.'.'],
            ],
        ];
    }
}
