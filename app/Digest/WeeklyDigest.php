<?php

namespace App\Digest;

use App\Mail\WeeklyDigestMail;
use App\Models\DigestSubscriber;
use App\Support\Format;
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

        // A week with no new extract has nothing to report: the issue says so instead of empty tables.
        $extract = DB::scalar("select max(tenements_extract) from ingest_runs where status in ('ok', 'baseline') and finished_at < ?", [$end]);

        return [
            'period_start' => $start->toIso8601String(),
            'period_end' => $end->toIso8601String(),
            'counts' => $counts,
            'freed' => $freed,
            'applications' => $applications,
            'applicants' => $applicants,
            'releases' => self::releases($end),
            'extract_at' => $extract ? CarbonImmutable::parse($extract)->toIso8601String() : null,
            'stale' => array_sum($counts) === 0 && ($extract === null || CarbonImmutable::parse($extract)->lt($start)),
        ];
    }

    /**
     * What an issue looks like, before the first one exists (D1): the feed, register
     * dates included, for the 7 days to the latest extract. Shown on /digest, never
     * stored or sent.
     */
    public function sample(): array
    {
        $run = Register::latestRun();
        $to = CarbonImmutable::parse($run->tenements_extract ?? 'now')->setTimezone(self::TZ)->startOfDay();
        $from = $to->subDays(6);
        $f = ['from' => $from->toDateString(), 'to' => $to->toDateString()];

        $counts = array_fill_keys(self::COUNTED, 0);
        foreach (Register::feedCounts($f, $f['from'], $f['to']) as $byType) {
            foreach (array_intersect_key($byType, $counts) as $type => $n) {
                $counts[$type] += $n;
            }
        }

        // Mining Notices have no tenement page yet, so the lists leave them out.
        $apps = array_values(array_filter(Register::feed($f + ['types' => ['application']], 1000), fn ($e) => $e['slug'] !== null));
        usort($apps, fn ($a, $b) => ($b['area_ha'] ?? 0) <=> ($a['area_ha'] ?? 0));
        $applicants = [];
        foreach ($apps as $e) {
            foreach (array_unique(array_column($e['holders'], 'name')) as $name) {
                $applicants[$name] = ($applicants[$name] ?? 0) + 1;
            }
        }
        uksort($applicants, fn ($a, $b) => [$applicants[$b], $a] <=> [$applicants[$a], $b]);

        return [
            'period_start' => $from->utc()->toIso8601String(),
            'period_end' => $to->setTime(23, 59, 59)->utc()->toIso8601String(),
            'counts' => $counts,
            'freed' => $this->sampleFreed($f),
            'applications' => array_map(self::slim(...), array_slice($apps, 0, 5)),
            'applicants' => array_map(fn ($name) => ['name' => $name, 'slug' => Slug::make($name), 'n' => $applicants[$name]],
                array_slice(array_keys($applicants), 0, 5)),
            'releases' => self::releases(CarbonImmutable::now()),
            'extract_at' => $run?->tenements_extract ? CarbonImmutable::parse($run->tenements_extract)->toIso8601String() : null,
            'stale' => false,
            'sample' => true,
        ];
    }

    /**
     * The week's biggest item by hectares (E4): the freed ground with the most area
     * uncovered, or the biggest new application, whichever is larger.
     *
     * @return array{kind: 'freed'|'application', item: array}|null
     */
    public static function lead(array $c): ?array
    {
        $freed = $c['freed'][0] ?? null;
        $app = $c['applications'][0] ?? null;
        if ($freed === null && $app === null) {
            return null;
        }

        return ($freed['uncovered_ha'] ?? 0) >= ($app['area_ha'] ?? 0)
            ? ['kind' => 'freed', 'item' => $freed]
            : ['kind' => 'application', 'item' => $app];
    }

    /** Every upcoming compulsory release from $after, up to 15 (G19). */
    private static function releases(CarbonImmutable $after): array
    {
        return array_map(fn ($r) => [
            'fmt_tenid' => $r['fmt_tenid'], 'slug' => $r['slug'],
            'release_at' => CarbonImmutable::parse($r['release_at'])->setTimezone(self::TZ)->format('D j M, g:ia'),
            'area_ha' => $r['area_ha'],
            'holders' => array_column($r['holders'], 'name'),
        ], Register::upcomingReleases(null, 15, $after));
    }

    /**
     * Deaths in the sample's window ranked by area no other tenement covers. Deaths
     * Pegwatch saw carry that share; deaths known only from the register's dates are
     * measured here against today's tenements, as the nightly diff does.
     */
    private function sampleFreed(array $f): array
    {
        $deaths = array_filter(Register::feed($f + ['types' => ['death']], 1000),
            fn ($e) => ($e['details']['kind'] ?? 'ended') === 'ended');
        usort($deaths, fn ($a, $b) => ($b['area_ha'] ?? 0) <=> ($a['area_ha'] ?? 0));
        $deaths = array_slice($deaths, 0, 30);

        $register = array_values(array_filter($deaths, fn ($e) => $e['source'] === 'register'));
        $measured = [];
        if ($register) {
            $pairs = implode(', ', array_fill(0, count($register), '(?, ?::date)'));
            $bind = array_merge(...array_map(fn ($e) => [$e['tenid'], substr($e['at'], 0, 10)], $register));
            foreach (DB::select(
                "select d.tenid, ST_Area(d.geom::geography) / 10000 as area_ha,
                        coalesce(ST_Area(ST_Difference(d.geom, c.cover)::geography), ST_Area(d.geom::geography)) / 10000 as uncovered_ha
                 from dead_tenements d
                 left join lateral (select ST_Union(t.geom) as cover from tenements t
                                    where t.geom && d.geom and ST_Intersects(t.geom, d.geom) and t.type_code not in ('L', 'G')) c on true
                 where (d.tenid, d.ended_at::date) in ($pairs) and d.geom is not null", $bind) as $r) {
                $measured[$r->tenid] = [(float) $r->area_ha, (float) $r->uncovered_ha];
            }
        }

        $freed = [];
        foreach ($deaths as $e) {
            if ($e['source'] === 'register') {
                if (! isset($measured[$e['tenid']])) {
                    continue;
                }
                [$area, $uncovered] = $measured[$e['tenid']];
                $e['details'] += ['area_ha' => $area, 'uncovered_share' => $area > 0 ? $uncovered / $area : 0];
            }
            $item = self::slim($e);
            if (($item['uncovered_ha'] ?? 0) >= 1) {
                $freed[] = $item;
            }
        }
        usort($freed, fn ($a, $b) => $b['uncovered_ha'] <=> $a['uncovered_ha']);

        return array_slice($freed, 0, 10);
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
            'type' => $e['type'], 'reason' => isset($e['details']['death_reason']) ? Format::sentence($e['details']['death_reason']) : null,
            'label' => $e['label'], 'summary' => $e['summary'],
            'fmt_tenid' => $e['fmt_tenid'], 'slug' => $e['slug'], 'tenement_type' => $e['tenement_type'],
            'holders' => array_column($e['holders'], 'name'),
            'area_ha' => isset($e['details']['area_ha']) ? round((float) $e['details']['area_ha'], 1) : ($e['area_ha'] ?? null),
            'uncovered_ha' => isset($e['details']['area_ha'], $e['details']['uncovered_share'])
                ? round((float) $e['details']['area_ha'] * (float) $e['details']['uncovered_share'], 1) : null,
        ];
    }
}
