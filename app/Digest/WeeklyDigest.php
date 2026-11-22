<?php

namespace App\Digest;

use App\Mail\WeeklyDigestMail;
use App\Models\DigestSubscriber;
use App\Support\Register;
use App\Support\Slug;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * The weekly WA digest: one state-wide summary of the register's changes,
 * built once per Perth ISO week and sent to every confirmed subscriber.
 *
 * Called after each nightly ingest. An issue is due from the configured
 * weekday on (default Monday) until it has gone out, so a missed night only
 * delays it. Sending is resumable: each delivery is recorded, and a call that
 * runs out of time leaves the rest for the next call.
 */
class WeeklyDigest
{
    public const TZ = 'Australia/Perth';

    /** Count only these, in this order, in the "this week" table. */
    public const COUNTED = [
        'application', 'grant', 'death', 'holder_change', 'shrink',
        'release_scheduled', 'released', 'release_cancelled',
    ];

    public static function weekKey(CarbonImmutable $at): string
    {
        $p = $at->setTimezone(self::TZ);

        return $p->format('o').'-W'.$p->format('W');
    }

    public static function isDue(CarbonImmutable $now): bool
    {
        $day = (int) config('pegwatch.digest_day', 1);

        $weekday = (int) $now->setTimezone(self::TZ)->format('N');
        $first = ! DB::table('digest_issues')->exists();

        // From the send day to the end of the ISO week, until it goes out. The
        // first issue waits for the send day itself rather than going out on
        // whatever day the app first runs.
        return ($first ? $weekday === $day : $weekday >= $day)
            && ! DB::table('digest_issues')->where('week', self::weekKey($now))->exists();
    }

    /**
     * Build this week's issue if it is due, then send whatever is unsent.
     *
     * @return array{issue: ?string, built: bool, sent: int, remaining: int}
     */
    public function run(?CarbonImmutable $now = null, bool $force = false, float $budgetSeconds = 40): array
    {
        $now = ($now ?? CarbonImmutable::now())->utc();
        $built = false;
        if ($force || self::isDue($now)) {
            $built = $this->build($now) !== null;
        }

        $issue = DB::table('digest_issues')->whereNull('finished_at')->orderBy('id')->first();
        if (! $issue) {
            return ['issue' => null, 'built' => $built, 'sent' => 0, 'remaining' => 0];
        }

        return ['issue' => $issue->week, 'built' => $built] + $this->send($issue, $budgetSeconds);
    }

    /** Build and store the issue for the week containing $now. Null if it exists already. */
    public function build(CarbonImmutable $now): ?object
    {
        $now = $now->utc();
        $week = self::weekKey($now);
        if (DB::table('digest_issues')->where('week', $week)->exists()) {
            return null;
        }
        $last = DB::table('digest_issues')->max('period_end');
        $start = $last ? CarbonImmutable::parse($last)->utc() : $now->subDays(7);
        $content = $this->content($start, $now);

        DB::table('digest_issues')->insertOrIgnore([
            'week' => $week, 'period_start' => $start, 'period_end' => $now,
            'content' => json_encode($content), 'created_at' => $now, 'updated_at' => $now,
        ]);

        return DB::table('digest_issues')->where('week', $week)->first();
    }

    /** The issue's figures and lists, computed once and stored with it. */
    public function content(CarbonImmutable $start, CarbonImmutable $end): array
    {
        // Bind UTC: a Carbon is bound as wall-clock text in its own zone.
        [$start, $end] = [$start->utc(), $end->utc()];
        $window = 'e.created_at >= ? and e.created_at < ? and not jsonb_exists(e.details, \'reversed_by_run\')';
        $bind = [$start, $end];

        $counts = array_fill_keys(self::COUNTED, 0);
        foreach (DB::select("select e.event_type, count(*) as n from tenement_events e where $window group by 1", $bind) as $r) {
            if (array_key_exists($r->event_type, $counts)) {
                $counts[$r->event_type] = (int) $r->n;
            }
        }

        $fields = 'e.id, e.run_id, e.event_type, e.tenid, e.fmt_tenid, e.tenement_type, e.holders,
                   e.extract_at, e.occurred_at, e.details, e.created_at,
                   ST_X(ST_PointOnSurface(e.geom)) as lon, ST_Y(ST_PointOnSurface(e.geom)) as lat';
        $present = fn (array $rows) => array_map(fn ($e) => self::slim(Register::presentEvent($e)), $rows);

        // Ground that came free: deaths ranked by the area no other tenement covers.
        $freed = $present(DB::select(
            "select $fields from tenement_events e
             where $window and e.event_type = 'death' and coalesce(e.details->>'kind', 'ended') = 'ended'
               and (e.details->>'area_ha')::numeric * (e.details->>'uncovered_share')::numeric >= 1
             order by (e.details->>'area_ha')::numeric * (e.details->>'uncovered_share')::numeric desc
             limit 10", $bind
        ));

        $applications = $present(DB::select(
            "select $fields from tenement_events e
             where $window and e.event_type = 'application'
             order by (e.details->>'area_ha')::numeric desc nulls last limit 5", $bind
        ));

        $applicants = array_map(fn ($r) => [
            'name' => $r->name, 'slug' => Slug::make($r->name), 'n' => (int) $r->n,
        ], DB::select(
            "select h.name, count(*) as n from tenement_events e cross join lateral unnest(e.holders) h(name)
             where $window and e.event_type = 'application'
             group by h.name order by count(*) desc, h.name limit 5", $bind
        ));

        $releases = array_map(fn ($r) => [
            'fmt_tenid' => trim((string) $r->fmt_tenid), 'slug' => Slug::make((string) $r->fmt_tenid),
            'release_at' => CarbonImmutable::parse($r->release_at)->setTimezone(self::TZ)->format('D j M, g:ia'),
            'area_ha' => round((float) $r->area_ha, 1),
        ], DB::select(
            'select fmt_tenid, release_at, ST_Area(geom::geography) / 10000 as area_ha from release_pending
             where release_at >= ? and release_at < ? order by release_at, fmt_tenid limit 15',
            [$end, $end->addDays(7)]
        ));

        return [
            'period_start' => $start->toIso8601String(),
            'period_end' => $end->toIso8601String(),
            'counts' => $counts,
            'freed' => $freed,
            'applications' => $applications,
            'applicants' => $applicants,
            'releases' => $releases,
        ];
    }

    /** @return array{sent: int, remaining: int} */
    private function send(object $issue, float $budgetSeconds): array
    {
        $deadline = microtime(true) + $budgetSeconds;
        $content = json_decode($issue->content, true);
        $sent = 0;

        // Only people who confirmed before the issue was built.
        $pending = fn () => DigestSubscriber::active()
            ->where('confirmed_at', '<=', $issue->created_at)
            ->whereNotExists(fn ($q) => $q->from('digest_deliveries')
                ->whereColumn('digest_subscriber_id', 'digest_subscribers.id')
                ->where('digest_issue_id', $issue->id));

        foreach ($pending()->orderBy('id')->lazyById(200) as $subscriber) {
            if (microtime(true) > $deadline) {
                break;
            }
            // Claim first, so two overlapping calls never mail the same person twice.
            $claimed = DB::table('digest_deliveries')->insertOrIgnore([
                'digest_issue_id' => $issue->id, 'digest_subscriber_id' => $subscriber->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            if (! $claimed) {
                continue;
            }
            try {
                Mail::to($subscriber->email)->send(new WeeklyDigestMail($issue->week, $content, $subscriber));
                DB::table('digest_deliveries')->where('digest_issue_id', $issue->id)
                    ->where('digest_subscriber_id', $subscriber->id)->update(['sent_at' => now()]);
                $sent++;
            } catch (Throwable $e) {
                // Release the claim so the next call retries this address.
                DB::table('digest_deliveries')->where('digest_issue_id', $issue->id)
                    ->where('digest_subscriber_id', $subscriber->id)->delete();
                Log::warning('Weekly digest send failed', ['subscriber' => $subscriber->id, 'error' => $e->getMessage()]);
            }
        }

        $remaining = $pending()->count();
        if ($remaining === 0) {
            DB::table('digest_issues')->where('id', $issue->id)->update(['finished_at' => now(), 'updated_at' => now()]);
        }

        return ['sent' => $sent, 'remaining' => $remaining];
    }

    /** Keep only what the email and the archive page show. */
    private static function slim(array $e): array
    {
        return [
            'type' => $e['type'], 'reason' => $e['details']['death_reason'] ?? null, 'label' => $e['label'], 'summary' => $e['summary'],
            'fmt_tenid' => $e['fmt_tenid'], 'slug' => $e['slug'], 'tenement_type' => $e['tenement_type'],
            'holders' => array_column($e['holders'], 'name'),
            'area_ha' => isset($e['details']['area_ha']) ? round((float) $e['details']['area_ha'], 1) : null,
            'uncovered_ha' => isset($e['details']['area_ha'], $e['details']['uncovered_share'])
                ? round((float) $e['details']['area_ha'] * (float) $e['details']['uncovered_share'], 1) : null,
        ];
    }
}
