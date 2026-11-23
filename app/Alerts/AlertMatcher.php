<?php

namespace App\Alerts;

use App\Mail\WatchAreaDigest;
use App\Models\WatchArea;
use App\Support\Register;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Matches an ingest run's events to watch areas and sends one email per
 * watch. Safe to run twice: deliveries are unique per area and event.
 */
class AlertMatcher
{
    /** A death leaving less ground than this with no other blocking tenement over it is not news (G15). */
    public const MIN_UNCOVERED_HA = 1;

    /**
     * Whether event `e` is news for watch `w`: one of its alert types, inside
     * its shape or on its holder's tenements, not undone by a later run, and
     * not a conversion, amalgamation or covered death unless the watch opted in.
     */
    private const MATCH = <<<'SQL'
        w.event_types @> jsonb_build_array(e.event_type)
        and not jsonb_exists(e.details, 'reversed_by_run')
        and case when w.kind = 'holder' then w.holder_slug = any(public.pw_slugs(e.holders))
                 else e.geom && w.area and ST_Intersects(e.geom, w.area) end
        and (e.event_type <> 'death' or w.include_covered_deaths or (
             coalesce(e.details->>'kind', 'ended') = 'ended'
             and coalesce((e.details->>'uncovered_share')::numeric, 1)
                 * coalesce((e.details->>'area_ha')::numeric, ST_Area(e.geom::geography)::numeric / 10000)
                 >= %d))
        SQL;

    private const EVENT_COLUMNS = 'e.id, e.run_id, e.event_type, e.tenid, e.fmt_tenid, e.tenement_type, e.holders,
        e.extract_at, e.occurred_at, e.details, e.created_at,
        ST_X(ST_PointOnSurface(e.geom)) as lon, ST_Y(ST_PointOnSurface(e.geom)) as lat';

    /**
     * Match one run's events, or with no run id every successful run of the last
     * week, so a run whose hook call was missed is still alerted on later.
     *
     * @return array{matched: int, emails: int}
     */
    public function run(?int $runId = null): array
    {
        $runs = $runId !== null ? [$runId] : DB::table('ingest_runs')->where('status', 'ok')
            ->where('started_at', '>=', now()->subDays(7))->pluck('id')->all();
        if (! $runs) {
            return ['matched' => 0, 'emails' => 0];
        }

        $matched = DB::affectingStatement(
            'insert into alert_deliveries (watch_area_id, tenement_event_id, created_at, updated_at)
             select w.id, e.id, now(), now()
             from tenement_events e
             join watch_areas w on '.self::match().'
             where e.run_id = any(?::bigint[]) and e.created_at >= w.created_at
               and w.confirmed_at is not null and w.unsubscribed_at is null
             on conflict (watch_area_id, tenement_event_id) do nothing',
            ['{'.implode(',', array_map('intval', $runs)).'}']
        );

        $emails = 0;
        $areaIds = DB::table('alert_deliveries')->whereNull('sent_at')->distinct()->pluck('watch_area_id');
        $areas = WatchArea::with('user')->whereIn('id', $areaIds)->confirmed()->whereNull('unsubscribed_at')->get();
        foreach ($areas as $area) {
            $deliveries = DB::table('alert_deliveries')->where('watch_area_id', $area->id)
                ->whereNull('sent_at')->pluck('tenement_event_id')->all();
            if (! $deliveries) {
                continue;
            }
            $events = self::present(DB::select(
                'select '.self::EVENT_COLUMNS.' from tenement_events e where e.id = any(?::bigint[]) order by e.event_type, e.fmt_tenid',
                ['{'.implode(',', $deliveries).'}']
            ));
            Mail::to($area->user)->send(new WatchAreaDigest($area, $events, Register::latestRun()));
            DB::table('alert_deliveries')->where('watch_area_id', $area->id)
                ->whereIn('tenement_event_id', $deliveries)->update(['sent_at' => now()]);
            $emails++;
        }

        return ['matched' => $matched, 'emails' => $emails];
    }

    /** What this watch would have been told about in the last $days days, newest first (the email preview). */
    public static function recent(WatchArea $area, int $days = 30, int $limit = 40): array
    {
        return self::present(DB::select(
            'select '.self::EVENT_COLUMNS.'
             from watch_areas w join tenement_events e on '.self::match().'
             where w.id = ? and e.created_at >= now() - make_interval(days => ?)
             order by e.created_at desc, e.id desc limit ?',
            [$area->id, $days, $limit]
        ));
    }

    /**
     * The alert emails a watch was sent, newest first: when, and which tenements.
     *
     * @return list<array{sent_at: string, n: int, tenements: list<string>}>
     */
    public static function history(WatchArea $area, int $limit = 20): array
    {
        return array_map(fn ($r) => [
            'sent_at' => $r->sent_at, 'n' => (int) $r->n,
            'tenements' => array_values(array_unique(array_map('trim', json_decode($r->tenements, true) ?? []))),
        ], DB::select(
            'select d.sent_at, count(*) as n, json_agg(e.fmt_tenid order by e.fmt_tenid) as tenements
             from alert_deliveries d join tenement_events e on e.id = d.tenement_event_id
             where d.watch_area_id = ? and d.sent_at is not null
             group by d.sent_at order by d.sent_at desc limit ?',
            [$area->id, $limit]
        ));
    }

    private static function match(): string
    {
        return sprintf(self::MATCH, self::MIN_UNCOVERED_HA);
    }

    private static function present(array $rows): array
    {
        return array_map(fn ($e) => Register::presentEvent($e), $rows);
    }
}
