<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Read-only queries over the ingest's tables (tenements, dead_tenements,
 * tenement_events, ingest_runs). The ingest owns these tables.
 */
class Register
{
    public const SEARCH_MAX_LENGTH = 100;

    public const EVENT_LABELS = [
        'application' => 'New application',
        'appeared' => 'Appeared on the register',
        'reappeared' => 'Back on the register',
        'death' => 'Tenement recorded dead',
        'grant' => 'Granted',
        'status_change' => 'Status changed',
        'holder_change' => 'Holder changed',
        'shrink' => 'Tenement shrank',
        'grow' => 'Tenement grew',
        'release_scheduled' => 'Release scheduled',
        'released' => 'Ground released',
        'release_cancelled' => 'Release cancelled',
    ];

    public static function latestRun(): ?object
    {
        return DB::selectOne(
            "select id, finished_at, tenements_extract, stale from ingest_runs
             where status in ('ok', 'baseline') order by id desc limit 1"
        );
    }

    /** When the nightly job last finished, whatever it found (null before the first run). */
    public static function lastCheck(): ?string
    {
        return DB::scalar('select max(finished_at) from ingest_runs');
    }

    /**
     * The earliest death on record. Until the full DEAD layer is loaded this is
     * recent (the nightly pull keeps 30 days), so labels must not claim 12 months.
     */
    public static function deadHistoryStart(): ?string
    {
        return DB::scalar('select min(ended_at) from dead_tenements');
    }

    public static function totals(): array
    {
        $rows = DB::select('select status, count(*) as n from tenements group by status');
        $out = ['LIVE' => 0, 'PENDING' => 0];
        foreach ($rows as $r) {
            $out[$r->status] = (int) $r->n;
        }

        return $out;
    }

    public static function tenement(string $slug): ?array
    {
        $t = DB::selectOne(
            'select tenid, fmt_tenid, type, type_code, status, survstatus, holders, holder_is_company,
                    legal_area, area_unit, extract_at, applied_at, granted_at, ends_at, area_ha,
                    ST_AsGeoJSON(geom, 6) as geojson, ST_AsGeoJSON(ST_Envelope(geom)) as bbox
             from tenements where pw_slug(fmt_tenid) = ? limit 1',
            [$slug]
        );
        $dead = null;
        if (! $t) {
            $dead = DB::selectOne(
                'select tenid, fmt_tenid, type, death_reason, holders, holders_are_company, started_at,
                        granted_at, ended_at, extract_at, ST_AsGeoJSON(geom, 6) as geojson,
                        ST_AsGeoJSON(ST_Envelope(geom)) as bbox
                 from dead_tenements where pw_slug(fmt_tenid) = ? order by ended_at desc limit 1',
                [$slug]
            );
            if (! $dead) {
                return null;
            }
        }
        $tenid = $t->tenid ?? $dead->tenid;

        return [
            'tenement' => $t ? [
                'fmt_tenid' => trim($t->fmt_tenid),
                'slug' => Slug::make($t->fmt_tenid),
                'type' => $t->type,
                'type_code' => $t->type_code,
                'status' => $t->status,
                'survey_status' => $t->survstatus,
                'holders' => Holders::present(PgArray::parse($t->holders), PgArray::parse($t->holder_is_company)),
                'legal_area' => $t->legal_area !== null ? (float) $t->legal_area : null,
                'area_unit' => $t->area_unit,
                'area_ha' => $t->area_ha !== null ? round((float) $t->area_ha, 1) : null,
                'applied_at' => $t->applied_at,
                'granted_at' => $t->granted_at,
                'ends_at' => $t->ends_at,
                'extract_at' => $t->extract_at,
                'dead' => null,
            ] : [
                'fmt_tenid' => trim($dead->fmt_tenid),
                'slug' => Slug::make($dead->fmt_tenid),
                'type' => $dead->type,
                'type_code' => null,
                'status' => 'DEAD',
                'survey_status' => null,
                'holders' => Holders::present(PgArray::parse($dead->holders), PgArray::parse($dead->holders_are_company)),
                'legal_area' => null,
                'area_unit' => null,
                'area_ha' => null,
                'applied_at' => $dead->started_at,
                'granted_at' => $dead->granted_at,
                'ends_at' => $dead->ended_at,
                'extract_at' => $dead->extract_at,
                'dead' => ['reason' => $dead->death_reason, 'ended_at' => $dead->ended_at],
            ],
            'geometry' => json_decode(($t ?? $dead)->geojson ?? 'null'),
            'bbox' => self::bbox(($t ?? $dead)->bbox),
            'events' => self::events(['tenid' => $tenid], 50),
            'overlaps' => $t ? self::overlaps($tenid) : [],
            'releases' => array_map(fn ($r) => (array) $r, DB::select(
                'select rlesid, release_at from release_pending where tenid = ? order by release_at', [$tenid]
            )),
        ];
    }

    /** Other live or pending tenements sharing ground with this one. */
    public static function overlaps(string $tenid): array
    {
        return array_map(fn ($r) => [
            'fmt_tenid' => trim($r->fmt_tenid),
            'slug' => Slug::make($r->fmt_tenid),
            'type' => $r->type,
            'status' => $r->status,
            'share' => round((float) $r->share, 3),
        ], DB::select(
            'select o.fmt_tenid, o.type, o.status,
                    ST_Area(ST_Intersection(o.geom, t.geom)) / nullif(ST_Area(t.geom), 0) as share
             from tenements t join tenements o on o.geom && t.geom and o.tenid <> t.tenid
               and ST_Intersects(o.geom, t.geom)
             where t.tenid = ?
               and ST_Area(ST_Intersection(o.geom, t.geom)) >= 0.005 * nullif(ST_Area(t.geom), 0)
             order by share desc limit 25',
            [$tenid]
        ));
    }

    public static function holder(string $slug): ?array
    {
        $rows = DB::select(
            'select t.fmt_tenid, t.type, t.type_code, t.status, t.applied_at, t.granted_at, t.ends_at,
                    t.area_ha, h.name, h.is_company
             from tenements t
             cross join lateral unnest(t.holders, t.holder_is_company) as h(name, is_company)
             where pw_slug(h.name) = ?
             order by t.applied_at desc nulls last',
            [$slug]
        );
        $dead = DB::select(
            'select d.fmt_tenid, d.type, d.death_reason, d.ended_at, h.name as holder_name, h.is_company as holder_is_company
             from dead_tenements d
             cross join lateral unnest(d.holders, d.holders_are_company) as h(name, is_company)
             where pw_slugs(d.holders) @> array[?]::text[] and pw_slug(h.name) = ?
             order by d.ended_at desc limit 200',
            [$slug, $slug]
        );
        if (! $rows && ! $dead) {
            return null;
        }
        $name = $rows[0]->name ?? $dead[0]->holder_name;
        $isCompany = (bool) ($rows[0]->is_company ?? $dead[0]->holder_is_company);

        $byMonth = DB::select(
            "select to_char(date_trunc('month', t.applied_at), 'YYYY-MM') as month, count(*) as n
             from tenements t cross join lateral unnest(t.holders) as h(name)
             where pw_slug(h.name) = ? and t.applied_at >= now() - interval '24 months'
             group by 1 order by 1",
            [$slug]
        );

        $extent = DB::selectOne(
            'select ST_AsGeoJSON(ST_Extent(t.geom)) as bbox, sum(t.area_ha) as area_ha,
                    ST_AsGeoJSON(ST_SimplifyPreserveTopology(ST_Collect(t.geom), 0.0005), 5) as geojson
             from tenements t cross join lateral unnest(t.holders) as h(name)
             where pw_slug(h.name) = ?',
            [$slug]
        );

        // Zero-fill the last 24 months so the chart has a steady time axis.
        $counts = array_column(array_map(fn ($r) => (array) $r, $byMonth), 'n', 'month');
        $months = [];
        $cursor = now('Australia/Perth')->startOfMonth()->subMonths(23);
        for ($i = 0; $i < 24; $i++) {
            $key = $cursor->format('Y-m');
            $months[] = ['month' => $key, 'n' => (int) ($counts[$key] ?? 0)];
            $cursor = $cursor->addMonth();
        }

        return [
            'holder' => ['name' => $name, 'slug' => $slug, 'is_company' => $isCompany],
            'summary' => [
                'live' => count(array_filter($rows, fn ($r) => $r->status === 'LIVE')),
                'pending' => count(array_filter($rows, fn ($r) => $r->status === 'PENDING')),
                'area_ha' => round((float) ($extent->area_ha ?? 0)),
                'dead' => count($dead),
            ],
            'bbox' => self::bbox($extent->bbox ?? null),
            'tenements' => array_map(fn ($r) => [
                'fmt_tenid' => trim($r->fmt_tenid),
                'slug' => Slug::make($r->fmt_tenid),
                'type' => $r->type,
                'status' => $r->status,
                'applied_at' => $r->applied_at,
                'granted_at' => $r->granted_at,
                'ends_at' => $r->ends_at,
                'area_ha' => $r->area_ha !== null ? round((float) $r->area_ha, 1) : null,
            ], $rows),
            'dead' => array_map(fn ($r) => [
                'fmt_tenid' => trim($r->fmt_tenid),
                'slug' => Slug::make($r->fmt_tenid),
                'type' => $r->type,
                'reason' => $r->death_reason,
                'ended_at' => $r->ended_at,
            ], $dead),
            'applications_by_month' => $months,
            'geometry' => json_decode($extent->geojson ?? 'null'),
            'events' => self::events(['holder_slug' => $slug], 50),
        ];
    }

    /**
     * Recent events, newest first.
     *
     * @param  array{tenid?: string, holder_slug?: string, type?: string, run_id?: int, bbox?: array}  $filter
     */
    public static function events(array $filter = [], int $limit = 100, int $offset = 0): array
    {
        $where = ['1=1'];
        $bind = [];
        if (isset($filter['tenid'])) {
            $where[] = 'e.tenid = ?';
            $bind[] = $filter['tenid'];
        }
        if (isset($filter['holder_slug'])) {
            $where[] = 'exists (select 1 from unnest(e.holders) h where pw_slug(h) = ?)';
            $bind[] = $filter['holder_slug'];
        }
        if (isset($filter['type'])) {
            $where[] = 'e.event_type = ?';
            $bind[] = $filter['type'];
        }
        if (isset($filter['from'])) {
            $where[] = "(e.created_at at time zone 'Australia/Perth')::date >= ?::date";
            $bind[] = $filter['from'];
        }
        if (isset($filter['to'])) {
            $where[] = "(e.created_at at time zone 'Australia/Perth')::date <= ?::date";
            $bind[] = $filter['to'];
        }
        if (isset($filter['run_id'])) {
            $where[] = 'e.run_id = ?';
            $bind[] = $filter['run_id'];
        }
        $bind[] = $limit;
        $bind[] = $offset;

        return array_map(fn ($e) => self::presentEvent($e), DB::select(
            'select e.id, e.run_id, e.event_type, e.tenid, e.fmt_tenid, e.tenement_type, e.holders,
                    e.extract_at, e.occurred_at, e.details, e.created_at, '.self::EVENT_AT.' as at,
                    ST_X(ST_PointOnSurface(e.geom)) as lon, ST_Y(ST_PointOnSurface(e.geom)) as lat
             from tenement_events e where '.implode(' and ', $where).'
             order by e.created_at desc, e.id desc limit ? offset ?',
            $bind
        ));
    }

    /**
     * A diff event as the pages and the digest show it. Its keys are a subset of
     * a feed item's (see presentFeedItem), so one list renders both.
     */
    public static function presentEvent(object $e): array
    {
        $details = json_decode($e->details ?? '{}', true) ?: [];
        $holders = PgArray::parse($e->holders);
        $fmt = trim((string) $e->fmt_tenid);

        return [
            'key' => 'e'.$e->id,
            'id' => (int) $e->id,
            'source' => 'change',
            'type' => $e->event_type,
            'label' => self::EVENT_LABELS[$e->event_type] ?? $e->event_type,
            'summary' => self::summarise($e->event_type, $details),
            'tenid' => $e->tenid ?? null,
            'fmt_tenid' => $fmt,
            'slug' => Slug::make($fmt),
            'tenement_type' => $e->tenement_type,
            'type_code' => self::typeCode($fmt),
            'holders' => Holders::present($holders),
            'at' => $e->at ?? $e->occurred_at ?? $e->created_at,
            'occurred_at' => $e->occurred_at,
            'extract_at' => $e->extract_at,
            'detected_at' => $e->created_at,
            'area_ha' => isset($details['area_ha']) ? round((float) $details['area_ha'], 1) : null,
            'details' => $details,
            'notice' => null,
            'point' => $e->lon !== null ? [(float) $e->lon, (float) $e->lat] : null,
        ];
    }

    public static function summarise(string $type, array $d): string
    {
        $pct = fn ($v) => $v === null ? null : round((float) $v * 100).'%';

        return match ($type) {
            'death' => match ($d['kind'] ?? 'ended') {
                'converted' => 'Converted to '.($d['converted_to'] ?? 'another tenement'),
                'amalgamated' => 'Amalgamated ('.($d['amalgamation_id'] ?? '').')',
                default => trim(($d['death_reason'] ?? 'Ended').'. '.(isset($d['uncovered_share']) && (float) $d['uncovered_share'] < 0.005
                    ? 'Other tenements cover all of it.'
                    : ($pct($d['uncovered_share'] ?? null) ?? '?').' of its area has no other tenement over it.')),
            },
            'application' => ($d['area_ha'] ?? '?').' ha applied for',
            'grant' => 'Pending to live',
            'holder_change' => 'Added: '.implode(', ', $d['added'] ?? []).'; removed: '.implode(', ', $d['removed'] ?? []),
            'shrink' => ($d['changed_ha'] ?? '?').' ha removed'.(($d['released'] ?? false) ? ' (compulsory surrender)' : ''),
            'grow' => ($d['changed_ha'] ?? '?').' ha added',
            'release_scheduled', 'released', 'release_cancelled' => ($d['release_id'] ?? '').' at '.self::awst($d['release_at'] ?? null),
            'status_change' => ($d['from'] ?? '?').' to '.($d['to'] ?? '?'),
            'reappeared' => 'Back after being recorded dead',
            default => '',
        };
    }

    /** Render a stored timestamptz (ISO 8601 with offset) as AWST wall-clock time. */
    private static function awst(?string $ts): string
    {
        if ($ts === null || $ts === '') {
            return '?';
        }
        try {
            return CarbonImmutable::parse($ts)->setTimezone('Australia/Perth')->format('j M Y H:i').' AWST';
        } catch (\Throwable) {
            return $ts;
        }
    }

    /**
     * Cmd+K search: tenements by ID prefix (live first, then dead ones that
     * have a page) and holders by name. Returns one ranked list, so Enter
     * and the first row always agree: a query shaped like a tenement ID
     * ("E45", "m 70/1") lists tenements first, anything else lists holders
     * first. Holder names that differ only in punctuation share a page
     * (pw_slug), so they are one result.
     */
    public static function search(string $q, int $limit = 8): array
    {
        $q = mb_check_encoding($q, 'UTF-8') ? trim(mb_substr($q, 0, self::SEARCH_MAX_LENGTH)) : '';
        if (mb_strlen($q) < 2) {
            return [];
        }

        $compact = preg_replace('/[^a-z0-9]/', '', strtolower($q));
        $tenements = [];
        if ($compact !== '') {
            $tenements = DB::select(
                'select fmt_tenid, type, status, null as death_reason from tenements
                 where pw_compact(fmt_tenid) like ? order by pw_compact(fmt_tenid), fmt_tenid limit ?',
                [$compact.'%', $limit]
            );
            // Dead tenements have pages too. Skip any ID that is live again.
            if (count($tenements) < $limit) {
                $tenements = [...$tenements, ...DB::select(
                    "select fmt_tenid, type, 'DEAD' as status, death_reason from (
                       select fmt_tenid, type, death_reason, ended_at from dead_tenements
                       where pw_compact(fmt_tenid) like ? order by pw_compact(fmt_tenid), ended_at desc limit ?
                     ) d where not exists (select 1 from tenements t where pw_slug(t.fmt_tenid) = pw_slug(d.fmt_tenid))",
                    [$compact.'%', $limit * 4]
                )];
            }
        }

        // Holders are matched on their slugs, so punctuation and spacing
        // don't matter ("smith john" finds "SMITH, JOHN"), and names that
        // share a page are one result. Live holders rank by live tenement
        // count; holders found only on dead tenements fill any space left.
        $slug = Slug::make($q);
        $holders = $slug === '' ? [] : self::searchHolders($slug, $limit);

        $seen = [];
        $t = [];
        foreach ($tenements as $row) {
            $key = Slug::make($row->fmt_tenid);
            if (isset($seen[$key]) || count($t) >= $limit) {
                continue;
            }
            $seen[$key] = true;
            $t[] = [
                'kind' => 'tenement', 'slug' => $key, 'fmt_tenid' => trim($row->fmt_tenid),
                'type' => $row->type, 'status' => $row->status, 'death_reason' => $row->death_reason,
            ];
        }
        $h = array_map(fn ($row) => [
            'kind' => 'holder', 'slug' => $row->slug, 'name' => $row->name,
            'live' => (int) $row->live,
        ], $holders);

        return preg_match('/^[a-z]{1,3}\s*\d/i', $q) ? [...$t, ...$h] : [...$h, ...$t];
    }

    /**
     * A slug of three or more characters is a substring match, served by
     * the trigram indexes on pw_holder_text(). Shorter ones are a prefix
     * match on live holders only: a two-character substring matches nearly
     * every name and can't use a trigram index.
     */
    private static function searchHolders(string $slug, int $limit): array
    {
        $order = 'order by slug = ? desc, live desc, slug limit ?';
        if (strlen($slug) < 3) {
            return DB::select(
                "select slug, (array_agg(name order by n desc, name))[1] as name, sum(n) as live
                 from (select name, pw_slug(name) as slug, n from (
                         select h.name, count(*) as n from tenements t
                         cross join lateral unnest(t.holders) as h(name)
                         where array_to_string(t.holders, ' ') ilike ? group by h.name) names
                       ) m
                 where slug like ? group by slug $order",
                ['%'.$slug[0].'%'.substr($slug, 1).'%', "$slug%", $slug, $limit]
            );
        }

        $prefixFirst = 'order by slug = ? desc, slug like ? desc, live desc, slug limit ?';
        $live = DB::select(
            "select slug, (array_agg(name order by n desc, name))[1] as name, sum(n) as live
             from (select name, pw_slug(name) as slug, n from (
                     select h.name, count(*) as n from tenements t
                     cross join lateral unnest(t.holders) as h(name)
                     where pw_holder_text(t.holders) like ? group by h.name) names
                   ) m
             where slug like ? group by slug $prefixFirst",
            ["%$slug%", "%$slug%", $slug, "$slug%", $limit]
        );
        if (count($live) >= $limit) {
            return $live;
        }

        // Every live match is in $live by now, so what's left is holders
        // found only on dead tenements. The row cap bounds a common word
        // ("gold") on the full DEAD history; an exact name is looked up by
        // index first so the cap can't hide it.
        $known = array_map(fn ($r) => $r->slug, $live);
        $dead = DB::select(
            "select slug, min(name) as name, 0 as live from (
               (select h.name, pw_slug(h.name) as slug from dead_tenements d
                cross join lateral unnest(d.holders) as h(name)
                where public.pw_slugs(d.holders) @> array[?]::text[] and pw_slug(h.name) = ?)
               union all
               (select h.name, pw_slug(h.name) from dead_tenements d
                cross join lateral unnest(d.holders) as h(name)
                where pw_holder_text(d.holders) like ? and pw_slug(h.name) like ? limit 2000)
             ) m
             where not (slug = any(?::text[]))
             group by slug $prefixFirst",
            [$slug, $slug, "%$slug%", "%$slug%", '{'.implode(',', $known).'}', $slug, "$slug%", $limit - count($live)]
        );

        return [...$live, ...$dead];
    }

    private static function bbox(?string $geojson): ?array
    {
        if (! $geojson) {
            return null;
        }
        $g = json_decode($geojson, true);
        $coords = $g['type'] === 'Point' ? [$g['coordinates']] : $g['coordinates'][0];
        $xs = array_column($coords, 0);
        $ys = array_column($coords, 1);

        return [min($xs), min($ys), max($xs), max($ys)];
    }

    // e-activity: the activity feed, and the map panel's counts and lists.

    /** How each feed item is known, as its row says. */
    public const FEED_SOURCES = [
        'change' => 'From the nightly change',
        'register' => "From the register's dates",
        'notice' => 'From a Mining Notice',
    ];

    /** Tenement type codes with their own filter chip and legend row; every other code is "other". */
    public const KINDS = ['E', 'P', 'M', 'L', 'G', 'R'];

    /**
     * The day a diff event belongs to: the register's date of the change when it
     * has one and it is not in the future (a scheduled release is dated by its
     * release time), else when the extract that showed it was taken.
     */
    private const EVENT_AT = "(case when e.occurred_at <= (now() at time zone 'Australia/Perth') then e.occurred_at
        else coalesce(e.extract_at, e.created_at) at time zone 'Australia/Perth' end)";

    private const NOW_LOCAL = "(now() at time zone 'Australia/Perth')";

    /** Show as stale when the run found no new extract, or the extract is over 72 hours old (weekends stay calm). */
    public static function isStale(?object $run): bool
    {
        return $run !== null && ($run->stale || ($run->tenements_extract !== null
            && CarbonImmutable::parse($run->tenements_extract)->lt(now()->subHours(72))));
    }

    /** True until a nightly run has compared two extracts: Pegwatch has only its first copy of the register. */
    public static function baselineOnly(): bool
    {
        return ! DB::table('ingest_runs')->where('status', 'ok')->exists();
    }

    /** When the first nightly run finished: the start of Pegwatch's own record of changes. */
    public static function watchingSince(): ?string
    {
        return DB::scalar("select min(finished_at) from ingest_runs where status in ('ok', 'baseline')");
    }

    /** Type codes on the register outside KINDS (MC, ML, CML...): what "other" means to the map's filter. */
    public static function otherKinds(): array
    {
        return array_column(DB::select(
            "select distinct type_code from tenements where type_code is not null and type_code not in ('".implode("', '", self::KINDS)."') order by 1"
        ), 'type_code');
    }

    /** The tenement type code from an ID ("E 24/250" is E, "MC 15/1" is MC). */
    public static function typeCode(?string $fmtTenid): ?string
    {
        return preg_match('~^\s*([A-Za-z]+)~', (string) $fmtTenid, $m) ? strtoupper($m[1]) : null;
    }

    /**
     * The activity feed, newest first. It unions the nightly diff's events with
     * what the register's own dates say happened (applications by lodgement
     * date, grants by grant date, deaths by end date) and with Mining Notices
     * lodged since the last extract, so it is full even while the extract is
     * stale. A register item the diff also saw shows once, as the diff's event.
     * Nothing is dated in the future, so expiries never appear.
     *
     * @param  array{types?: list<string>, kinds?: list<string>, field?: string, holder?: string, from?: string, to?: string, bbox?: array{float, float, float, float}}  $filter
     */
    public static function feed(array $filter = [], int $limit = 50, int $offset = 0): array
    {
        [$branches, $bind] = self::feedBranches($filter);
        if (! $branches) {
            return [];
        }
        // Each branch keeps its own top rows in the outer order, so the planner
        // can use an index per table and pages stay stable across requests.
        $n = $limit + $offset;
        $union = implode(' union all ', array_map(
            fn ($b) => "($b order by at desc, fmt_tenid, event_type, event_id limit $n)", $branches
        ));
        $rows = DB::select(
            "select f.*, ST_X(ST_PointOnSurface(f.geom)) as lon, ST_Y(ST_PointOnSurface(f.geom)) as lat,
                    coalesce((f.details->>'area_ha')::float, ST_Area(f.geom::geography) / 10000) as area_ha
             from ($union) f
             order by f.at desc, f.fmt_tenid, f.event_type, f.source, f.event_id limit ? offset ?",
            [...$bind, $limit, $offset]
        );

        return array_map(fn ($r) => self::presentFeedItem($r), $rows);
    }

    /**
     * How many feed items fall on each AWST day between two dates, by type:
     * ['2026-11-19' => ['application' => 12, 'grant' => 3]], newest day first.
     */
    public static function feedCounts(array $filter, string $from, string $to): array
    {
        [$branches, $bind] = self::feedBranches(['from' => $from, 'to' => $to] + $filter);
        if (! $branches) {
            return [];
        }
        $out = [];
        foreach (DB::select(
            "select to_char(f.at, 'YYYY-MM-DD') as day, f.event_type, count(*) as n
             from (".implode(' union all ', $branches).') f group by 1, 2', $bind
        ) as $r) {
            $out[$r->day][$r->event_type] = (int) $r->n;
        }
        krsort($out);

        return $out;
    }

    /** One select per source, all with the same columns, each filtered the same way. */
    private static function feedBranches(array $f): array
    {
        $types = isset($f['types']) && $f['types'] ? array_values($f['types']) : null;
        $wants = fn (string $t) => $types === null || in_array($t, $types, true);
        $branches = [];
        $bind = [];
        $add = function (string $select, array $where, array $own, array $cols) use (&$branches, &$bind, $f) {
            [$w, $b] = self::feedWhere($f, $cols);
            $branches[] = $select.' where '.implode(' and ', [...$where, ...$w]);
            array_push($bind, ...$own, ...$b);
        };
        $now = self::NOW_LOCAL;
        $notReversed = "not jsonb_exists(e.details, 'reversed_by_run')";
        $sawIt = fn (string $type, string $extra = '') => "not exists (select 1 from tenement_events e
            where e.tenid = t.tenid and e.event_type = '$type' and $notReversed $extra)";

        $typeWhere = $types ? ['e.event_type in ('.implode(', ', array_fill(0, count($types), '?')).')'] : [];
        $add(
            "select 'change' as source, e.id as event_id, e.event_type, e.tenid, e.fmt_tenid, e.tenement_type,
                    e.holders, null::boolean[] as holder_is_company, ".self::EVENT_AT.' as at, e.created_at as detected_at,
                    e.details, null::text as link, null::text as shire, null::text as area_text, e.geom
             from tenement_events e',
            [$notReversed, ...$typeWhere], $types ?? [],
            ['fmt' => 'e.fmt_tenid', 'holders' => 'e.holders', 'at' => self::EVENT_AT, 'geom' => 'e.geom'],
        );

        // Every branch names its columns: each one sorts on its own before the union.
        $tenement = fn (string $type, string $at) => "select 'register' as source, null::bigint as event_id, '$type' as event_type,
                    t.tenid, t.fmt_tenid, t.type as tenement_type, t.holders, t.holder_is_company, $at as at,
                    null::timestamptz as detected_at,
                    jsonb_build_object('area_ha', round(t.area_ha::numeric, 2), 'legal_area', t.legal_area,
                                       'area_unit', t.area_unit, 'applied_at', t.applied_at) as details,
                    null::text as link, null::text as shire, null::text as area_text, t.geom
             from tenements t";
        $cols = fn (string $at) => ['fmt' => 't.fmt_tenid', 'holders' => 't.holders', 'at' => $at, 'geom' => 't.geom'];
        if ($wants('application')) {
            $add($tenement('application', 't.applied_at'),
                ["t.status = 'PENDING'", "t.applied_at <= $now", $sawIt('application')], [], $cols('t.applied_at'));
        }
        if ($wants('grant')) {
            $add($tenement('grant', 't.granted_at'),
                ["t.status = 'LIVE'", "t.granted_at <= $now", $sawIt('grant')], [], $cols('t.granted_at'));
        }
        if ($wants('death')) {
            // A death the diff saw is back-filled with the register's end date, so match on the day.
            // A tenement back on the register (a restoration) is not shown as dead.
            $add(
                "select 'register' as source, null::bigint as event_id, 'death' as event_type, d.tenid, d.fmt_tenid,
                        d.type as tenement_type, d.holders, d.holders_are_company as holder_is_company, d.ended_at as at,
                        null::timestamptz as detected_at,
                        jsonb_build_object('death_reason', d.death_reason, 'applied_at', d.started_at) as details,
                        null::text as link, null::text as shire, null::text as area_text, d.geom
                 from dead_tenements d",
                ["d.ended_at <= $now", 'not exists (select 1 from tenements t where t.tenid = d.tenid)',
                    "not exists (select 1 from tenement_events e where e.tenid = d.tenid and e.event_type = 'death'
                       and $notReversed and (e.occurred_at is null or e.occurred_at::date = d.ended_at::date))"],
                [], ['fmt' => 'd.fmt_tenid', 'holders' => 'd.holders', 'at' => 'd.ended_at', 'geom' => 'd.geom'],
            );
        }
        // Mining Notices have no geometry, so a view of part of the map leaves them out.
        if ($wants('application') && empty($f['bbox'])) {
            // The extract (about 4am) holds lodgements to the end of the previous day,
            // so a notice from midnight on the extract's date on is not in it yet.
            $at = "(m.received_at at time zone 'Australia/Perth')";
            $add(
                "select 'notice' as source, null::bigint as event_id, 'application' as event_type, null::text as tenid,
                        m.fmt_tenid, null::text as tenement_type, m.applicants as holders, null::boolean[] as holder_is_company,
                        $at as at, m.first_seen_at as detected_at, '{}'::jsonb as details,
                        m.link, m.shire, m.area_text, null::geometry as geom
                 from mining_notices m",
                ["m.received_at >= (select date_trunc('day', max(tenements_extract) at time zone 'Australia/Perth')
                                             at time zone 'Australia/Perth'
                                      from ingest_runs where status in ('ok', 'baseline'))",
                    'm.received_at <= now()',
                    'not exists (select 1 from tenements t where pw_slug(t.fmt_tenid) = pw_slug(m.fmt_tenid))',
                    'not exists (select 1 from dead_tenements d where pw_slug(d.fmt_tenid) = pw_slug(m.fmt_tenid))'],
                [], ['fmt' => 'm.fmt_tenid', 'holders' => 'm.applicants', 'at' => $at, 'geom' => null],
            );
        }

        return [$branches, $bind];
    }

    /** The filters every feed source shares, on that source's columns. */
    private static function feedWhere(array $f, array $c): array
    {
        $where = [];
        $bind = [];
        if (! empty($f['kinds'])) {
            $code = "upper(substring({$c['fmt']} from '^\\s*([A-Za-z]+)'))";
            $named = array_values(array_intersect($f['kinds'], self::KINDS));
            $parts = $named ? ["$code in (".implode(', ', array_fill(0, count($named), '?')).')'] : [];
            if (in_array('other', $f['kinds'], true)) {
                $parts[] = "$code not in ('".implode("', '", self::KINDS)."')";
            }
            $where[] = $parts ? '('.implode(' or ', $parts).')' : 'false';
            array_push($bind, ...$named);
        }
        if (! empty($f['field'])) {
            $where[] = "substring({$c['fmt']} from '^\\s*[A-Za-z]{1,3}\\s*(\\d{2})\\s*/') = ?";
            $bind[] = $f['field'];
        }
        $holder = Slug::make((string) ($f['holder'] ?? ''));
        if ($holder !== '') {
            // The trigram index serves the joined text; each name is then checked on its own.
            $where[] = "(pw_holder_text({$c['holders']}) like ? and exists (select 1 from unnest({$c['holders']}) h where pw_slug(h) like ?))";
            array_push($bind, "%$holder%", "%$holder%");
        }
        if (! empty($f['from'])) {
            $where[] = "{$c['at']} >= ?::date";
            $bind[] = $f['from'];
        }
        if (! empty($f['to'])) {
            $where[] = "{$c['at']} < ?::date + 1";
            $bind[] = $f['to'];
        }
        if (! empty($f['bbox'])) {
            $where[] = $c['geom'] === null ? 'false' : "{$c['geom']} && ST_MakeEnvelope(?, ?, ?, ?, 4326)";
            if ($c['geom'] !== null) {
                array_push($bind, ...array_map('floatval', $f['bbox']));
            }
        }

        return [$where, $bind];
    }

    private static function presentFeedItem(object $r): array
    {
        if ($r->source === 'change') {
            $r->id = $r->event_id;
            $r->created_at = $r->detected_at;
            $r->occurred_at ??= null;
            $r->extract_at ??= null;
            $item = self::presentEvent($r);
            $item['area_ha'] ??= $r->area_ha !== null ? round((float) $r->area_ha, 1) : null;

            return $item;
        }

        $fmt = trim((string) $r->fmt_tenid);
        $code = self::typeCode($fmt);
        $details = json_decode($r->details ?? '{}', true) ?: [];
        $notice = $r->source === 'notice';
        $areaText = null;
        if ($notice && preg_match('~^\s*([\d.]+)\s*(HA|BL)~i', (string) $r->area_text, $m)) {
            $areaText = Format::legalArea((float) $m[1], $m[2]);
        }
        $legal = isset($details['legal_area']) ? Format::legalArea((float) $details['legal_area'], $details['area_unit'] ?? null) : null;
        $shire = $notice && $r->shire ? ucwords(strtolower(trim($r->shire)), " -\t") : null;

        return [
            'key' => $r->source[0].':'.$r->event_type.':'.Slug::make($fmt).':'.substr((string) $r->at, 0, 10),
            'id' => null,
            'source' => $r->source,
            'type' => $r->event_type,
            'label' => $notice ? 'Lodged since the last extract' : (self::EVENT_LABELS[$r->event_type] ?? $r->event_type),
            'summary' => match (true) {
                $notice => trim(($areaText ?? '').($shire ? ' in '.$shire : '')),
                $r->event_type === 'application' => ($legal ?? '?').' applied for',
                $r->event_type === 'grant' => trim(($legal ? $legal.', ' : '').'applied '.Format::localDate($details['applied_at'] ?? null), ', '),
                default => Format::sentence($details['death_reason'] ?? 'Ended'),
            },
            'tenid' => $r->tenid,
            'fmt_tenid' => $fmt,
            'slug' => $notice ? null : Slug::make($fmt),
            'tenement_type' => $r->tenement_type ?? Format::TYPE_NAMES[$code] ?? null,
            'type_code' => $code,
            // A notice can list one applicant twice; notices carry no company flags to keep aligned.
            'holders' => $notice ? Holders::present(array_values(array_unique(PgArray::parse($r->holders))))
                : Holders::present(PgArray::parse($r->holders), PgArray::parse($r->holder_is_company)),
            'at' => $r->at,
            'occurred_at' => $r->at,
            'extract_at' => null,
            'detected_at' => $r->detected_at,
            'area_ha' => $r->area_ha !== null ? round((float) $r->area_ha, 1) : null,
            'details' => $details,
            'notice' => $notice ? ['link' => $r->link, 'shire' => $shire, 'area' => $areaText] : null,
            'point' => $r->lon !== null ? [(float) $r->lon, (float) $r->lat] : null,
        ];
    }

    /**
     * The map panel's numbers for a view ($bbox [w, s, e, n], null for all of WA)
     * under the panel's filters: tenements in view, by status and by type (the
     * type counts ignore the type filter, so each legend toggle shows what it
     * would add), feed items in the last 7 days, and upcoming releases.
     *
     * @param  array{kinds?: list<string>, statuses?: list<string>, appliedFrom?: ?string, appliedTo?: ?string, expiresWithinMonths?: ?int, minHa?: ?float, maxHa?: ?float}  $filter
     */
    public static function countsInBounds(?array $bbox, array $filter = []): array
    {
        [$where, $bind] = self::tenementWhere(['kinds' => []] + $filter, $bbox);
        $kind = "case when t.type_code in ('".implode("', '", self::KINDS)."') then t.type_code else 'other' end";
        $byStatus = ['LIVE' => 0, 'PENDING' => 0];
        $byKind = array_fill_keys([...self::KINDS, 'other'], 0);
        $kinds = $filter['kinds'] ?? [];
        foreach (DB::select("select t.status, $kind as kind, count(*) as n from tenements t
                             where ".implode(' and ', $where).' group by 1, 2', $bind) as $r) {
            $byKind[$r->kind] += (int) $r->n;
            if ((! $kinds || in_array($r->kind, $kinds, true)) && isset($byStatus[$r->status])) {
                $byStatus[$r->status] += (int) $r->n;
            }
        }

        $today = CarbonImmutable::now('Australia/Perth');
        $week = self::feedCounts(array_filter(['bbox' => $bbox, 'kinds' => $kinds]), $today->subDays(6)->toDateString(), $today->toDateString());

        return [
            'tenements' => array_sum($byStatus),
            'by_status' => $byStatus,
            'by_kind' => $byKind,
            'changed_week' => array_sum(array_map('array_sum', $week)),
            'releases' => (int) DB::scalar(
                'select count(*) from release_pending r where r.release_at >= now()'.($bbox ? ' and r.geom && ST_MakeEnvelope(?, ?, ?, ?, 4326)' : ''),
                $bbox ? array_map('floatval', $bbox) : []
            ),
        ];
    }

    /** Live and pending tenements in a view under the panel's filters, newest application first. */
    public static function tenementsInBounds(?array $bbox, array $filter = [], int $limit = 40): array
    {
        [$where, $bind] = self::tenementWhere($filter, $bbox);

        return array_map(fn ($r) => [
            'tenid' => $r->tenid,
            'fmt_tenid' => trim($r->fmt_tenid),
            'slug' => Slug::make($r->fmt_tenid),
            'type' => $r->type,
            'type_code' => $r->type_code,
            'status' => $r->status,
            'holders' => Holders::present(PgArray::parse($r->holders), PgArray::parse($r->holder_is_company)),
            'area_ha' => $r->area_ha !== null ? round((float) $r->area_ha, 1) : null,
            'applied_at' => $r->applied_at,
            'granted_at' => $r->granted_at,
            'ends_at' => $r->ends_at,
            'bbox' => self::bbox($r->bbox),
        ], DB::select(
            'select t.tenid, t.fmt_tenid, t.type, t.type_code, t.status, t.holders, t.holder_is_company, t.area_ha,
                    t.applied_at, t.granted_at, t.ends_at, ST_AsGeoJSON(ST_Envelope(t.geom)) as bbox
             from tenements t where '.implode(' and ', $where).'
             order by t.applied_at desc nulls last, t.fmt_tenid limit ?',
            [...$bind, $limit]
        ));
    }

    /** Compulsory releases still to come, soonest first, optionally only those in a view. */
    public static function upcomingReleases(?array $bbox = null, int $limit = 20, ?CarbonImmutable $after = null): array
    {
        $bind = [($after ?? CarbonImmutable::now())->utc()];
        $inView = '';
        if ($bbox) {
            $inView = ' and r.geom && ST_MakeEnvelope(?, ?, ?, ?, 4326)';
            array_push($bind, ...array_map('floatval', $bbox));
        }
        $bind[] = $limit;

        return array_map(fn ($r) => [
            'tenid' => $r->tenid,
            'fmt_tenid' => trim((string) $r->fmt_tenid),
            'slug' => Slug::make((string) $r->fmt_tenid),
            'rlesid' => $r->rlesid,
            'release_at' => $r->release_at,
            'area_ha' => round((float) $r->area_ha, 1),
            'holders' => Holders::present(PgArray::parse($r->holders), PgArray::parse($r->holder_is_company)),
            'bbox' => self::bbox($r->bbox),
        ], DB::select(
            "select r.tenid, r.fmt_tenid, r.rlesid, r.release_at, ST_Area(r.geom::geography) / 10000 as area_ha,
                    coalesce(t.holders, '{}') as holders, coalesce(t.holder_is_company, '{}') as holder_is_company,
                    ST_AsGeoJSON(ST_Envelope(r.geom)) as bbox
             from release_pending r left join tenements t on t.tenid = r.tenid
             where r.release_at >= ?$inView order by r.release_at, r.fmt_tenid, r.rlesid limit ?",
            $bind
        ));
    }

    /** The map filter (contracts.md, map-filter) as SQL on tenements t, plus the view. */
    private static function tenementWhere(array $f, ?array $bbox): array
    {
        $where = ["t.status in ('LIVE', 'PENDING')"];
        $bind = [];
        if (! empty($f['kinds'])) {
            $named = array_values(array_intersect($f['kinds'], self::KINDS));
            $parts = $named ? ['t.type_code in ('.implode(', ', array_fill(0, count($named), '?')).')'] : [];
            if (in_array('other', $f['kinds'], true)) {
                $parts[] = "t.type_code not in ('".implode("', '", self::KINDS)."')";
            }
            $where[] = $parts ? '('.implode(' or ', $parts).')' : 'false';
            array_push($bind, ...$named);
        }
        if (! empty($f['statuses'])) {
            $where[] = 't.status in ('.implode(', ', array_fill(0, count($f['statuses']), '?')).')';
            array_push($bind, ...array_values($f['statuses']));
        }
        if (! empty($f['appliedFrom'])) {
            $where[] = 't.applied_at >= ?::date';
            $bind[] = $f['appliedFrom'];
        }
        if (! empty($f['appliedTo'])) {
            $where[] = 't.applied_at < ?::date + 1';
            $bind[] = $f['appliedTo'];
        }
        if (! empty($f['expiresWithinMonths'])) {
            $where[] = "t.status = 'LIVE' and t.ends_at >= ".self::NOW_LOCAL.'::date and t.ends_at < '.self::NOW_LOCAL.'::date + make_interval(months => ?)';
            $bind[] = (int) $f['expiresWithinMonths'];
        }
        if (isset($f['minHa'])) {
            $where[] = 't.area_ha >= ?';
            $bind[] = (float) $f['minHa'];
        }
        if (isset($f['maxHa'])) {
            $where[] = 't.area_ha < ?';
            $bind[] = (float) $f['maxHa'];
        }
        if ($bbox) {
            $where[] = 't.geom && ST_MakeEnvelope(?, ?, ?, ?, 4326)';
            array_push($bind, ...array_map('floatval', $bbox));
        }

        return [$where, $bind];
    }
}
