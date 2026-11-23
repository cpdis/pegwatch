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

    /**
     * A holder's portfolio page: who they are, their live and pending
     * tenements (with a summary by type), their dead ones (a true count and
     * the latest 200), applications and deaths by month, and the map focus.
     * Slugs are pw_slug() values; anything else is not a holder.
     */
    public static function holder(string $slug): ?array
    {
        if ($slug === '' || Slug::make($slug) !== $slug) {
            return null;
        }
        // The name most tenements carry for this slug; dead-only holders from
        // a bounded sample of their dead tenements.
        $who = DB::selectOne(
            'select h.name, h.is_company from tenements t
             cross join lateral unnest(t.holders, t.holder_is_company) as h(name, is_company)
             where pw_holder_text(t.holders) like ? and pw_slug(h.name) = ?
             group by 1, 2 order by count(*) desc, 1 limit 1',
            ["%$slug%", $slug]
        ) ?? DB::selectOne(
            'select h.name, h.is_company from (
               select holders, holders_are_company from dead_tenements
               where pw_slugs(holders) @> array[?]::text[] limit 500) d
             cross join lateral unnest(d.holders, d.holders_are_company) as h(name, is_company)
             where pw_slug(h.name) = ? group by 1, 2 order by count(*) desc, 1 limit 1',
            [$slug, $slug]
        );
        if (! $who) {
            return null;
        }

        $rows = self::holderTenements($slug);
        $summary = ['live' => 0, 'pending' => 0, 'area_ha' => 0.0, 'expiring_90d' => 0];
        $byType = [];
        foreach ($rows as $r) {
            $key = $r['type_code'] ?? '?';
            $byType[$key] ??= ['type_code' => $key, 'label' => $r['type_label'], 'live' => 0, 'pending' => 0, 'area_ha' => 0.0];
            $status = $r['status'] === 'LIVE' ? 'live' : ($r['status'] === 'PENDING' ? 'pending' : null);
            if ($status) {
                $summary[$status]++;
                $byType[$key][$status]++;
            }
            $summary['area_ha'] += $r['area_ha'] ?? 0;
            $byType[$key]['area_ha'] += $r['area_ha'] ?? 0;
            $summary['expiring_90d'] += (int) $r['expires_90d'];
        }
        $summary['area_ha'] = round($summary['area_ha']);
        usort($byType, fn ($a, $b) => [$b['live'] + $b['pending'], $a['type_code']] <=> [$a['live'] + $a['pending'], $b['type_code']]);

        $summary['dead'] = (int) DB::scalar(
            'select count(distinct tenid) from dead_tenements where pw_slugs(holders) @> array[?]::text[]', [$slug]
        );
        $dead = DB::select(
            'select fmt_tenid, type, death_reason, ended_at from dead_tenements
             where pw_slugs(holders) @> array[?]::text[] order by ended_at desc limit 200',
            [$slug]
        );

        [$months, $summary['applied_12m']] = self::holderActivity($slug);
        $focus = self::holderFocus($slug, $rows === []);

        return [
            'holder' => ['name' => $who->name, 'slug' => $slug, 'is_company' => (bool) $who->is_company],
            'summary' => $summary,
            'by_type' => $byType,
            'tenements' => $rows,
            'dead' => array_map(fn ($r) => [
                'fmt_tenid' => trim($r->fmt_tenid),
                'slug' => Slug::make($r->fmt_tenid),
                'type' => $r->type,
                'reason' => $r->death_reason,
                'ended_at' => $r->ended_at,
            ], $dead),
            'activity_by_month' => $months,
            'dead_since' => self::deadHistoryStart(),
            'focus' => $focus['features'],
            'focus_dead' => $rows === [],
            'bbox' => $focus['bbox'],
            'matches' => $who->is_company ? self::nameMatches(self::nameStem($who->name)) : null,
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
                    e.extract_at, e.occurred_at, e.details, e.created_at,
                    ST_X(ST_PointOnSurface(e.geom)) as lon, ST_Y(ST_PointOnSurface(e.geom)) as lat
             from tenement_events e where '.implode(' and ', $where).'
             order by e.created_at desc, e.id desc limit ? offset ?',
            $bind
        ));
    }

    public static function presentEvent(object $e): array
    {
        $details = json_decode($e->details ?? '{}', true) ?: [];
        $holders = PgArray::parse($e->holders);

        return [
            'id' => (int) $e->id,
            'type' => $e->event_type,
            'label' => self::EVENT_LABELS[$e->event_type] ?? $e->event_type,
            'summary' => self::summarise($e->event_type, $details),
            'fmt_tenid' => trim((string) $e->fmt_tenid),
            'slug' => Slug::make((string) $e->fmt_tenid),
            'tenement_type' => $e->tenement_type,
            'holders' => Holders::present($holders),
            'occurred_at' => $e->occurred_at,
            'extract_at' => $e->extract_at,
            'detected_at' => $e->created_at,
            'details' => $details,
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
                default => trim(($d['death_reason'] ?? 'Ended').'. '.
                    ($pct($d['uncovered_share'] ?? null) ?? '?').' of its area has no other tenement over it.'),
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
     * Cmd+K search over tenements, holders and places. Returns one ranked
     * list, so Enter and the first row always agree. A query shaped like a
     * tenement ID ("E45", "m 70/1") lists tenements, then holders. Any other
     * lists matching places, the holder of exactly that name,
     * the name-match group ("Entities matching NORTHERN STAR"), the other
     * holders, other places, then tenements. Holder names that differ only
     * in punctuation share a page (pw_slug), so they are one result.
     */
    public static function search(string $q, int $limit = 8): array
    {
        $q = mb_check_encoding($q, 'UTF-8') ? trim(mb_substr($q, 0, self::SEARCH_MAX_LENGTH)) : '';
        if (mb_strlen($q) < 2) {
            return [];
        }

        $compact = preg_replace('/[^a-z0-9]/', '', strtolower($q));
        $t = $compact === '' ? [] : self::searchTenements($q, $compact, $limit);

        // Holders are matched on their slugs, so punctuation and spacing
        // don't matter ("smith john" finds "SMITH, JOHN"), and names that
        // share a page are one result. Holders with live or pending ground
        // rank by how much; holders found only on dead tenements fill any
        // space left.
        $slug = Slug::make($q);
        $h = array_map(fn ($row) => [
            'kind' => 'holder', 'slug' => $row->slug, 'name' => $row->name,
            'live' => (int) $row->live, 'pending' => (int) $row->pending, 'is_company' => (bool) $row->is_company,
        ], $slug === '' ? [] : self::searchHolders($slug, $limit));

        if (preg_match('/^[a-z]{1,3}\s*\d/i', $q)) {
            return [...$t, ...$h];
        }

        // A town's name, or four or more letters of one, leads; a shorter
        // prefix ("kal") is as likely a company, so its places go last.
        $places = self::searchPlaces($slug);
        $leads = fn ($p) => $p['exact'] || strlen($slug) >= 4;
        $leadPlaces = array_values(array_filter($places, $leads));
        $otherPlaces = array_values(array_filter($places, fn ($p) => ! $leads($p)));
        $exactHolder = array_splice($h, 0, ($h[0]['slug'] ?? null) === $slug ? 1 : 0);
        $match = self::nameMatches(self::nameStem($q));
        $group = $match ? [[
            'kind' => 'group', 'slug' => $match['stem'], 'label' => $match['label'],
            'entities' => count($match['entities']), 'tenements' => $match['tenements'],
        ]] : [];

        return [...$leadPlaces, ...$exactHolder, ...$group, ...$h, ...$otherPlaces, ...$t];
    }

    /**
     * A slug of three or more characters is a substring match, served by
     * the trigram indexes on pw_holder_text(). Shorter ones are a prefix
     * match on live holders only: a two-character substring matches nearly
     * every name and can't use a trigram index.
     */
    private static function searchHolders(string $slug, int $limit): array
    {
        // Per holder name: live and pending counts and whether the register
        // marks it a company; then per slug, the commonest spelling.
        $names = "select h.name, h.is_company, count(*) as n,
                         count(*) filter (where t.status = 'LIVE') as live,
                         count(*) filter (where t.status = 'PENDING') as pending
                  from tenements t cross join lateral unnest(t.holders, t.holder_is_company) as h(name, is_company)";
        $bySlug = 'select slug, (array_agg(name order by n desc, name))[1] as name, sum(live) as live,
                          sum(pending) as pending, bool_or(is_company) as is_company, sum(n) as n';
        if (strlen($slug) < 3) {
            return DB::select(
                "$bySlug from (select *, pw_slug(name) as slug from ($names
                   where array_to_string(t.holders, ' ') ilike ? group by h.name, h.is_company) names) m
                 where slug like ? group by slug order by slug = ? desc, sum(n) desc, slug limit ?",
                ['%'.$slug[0].'%'.substr($slug, 1).'%', "$slug%", $slug, $limit]
            );
        }

        $prefixFirst = 'order by slug = ? desc, slug like ? desc, sum(n) desc, slug limit ?';
        $live = DB::select(
            "$bySlug from (select *, pw_slug(name) as slug from ($names
               where pw_holder_text(t.holders) like ? group by h.name, h.is_company) names) m
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
            'select slug, min(name) as name, 0 as live, 0 as pending, bool_or(is_company) as is_company from (
               (select h.name, h.is_company, pw_slug(h.name) as slug from dead_tenements d
                cross join lateral unnest(d.holders, d.holders_are_company) as h(name, is_company)
                where public.pw_slugs(d.holders) @> array[?]::text[] and pw_slug(h.name) = ? limit 2000)
               union all
               (select h.name, h.is_company, pw_slug(h.name) from dead_tenements d
                cross join lateral unnest(d.holders, d.holders_are_company) as h(name, is_company)
                where pw_holder_text(d.holders) like ? and pw_slug(h.name) like ? limit 2000)
             ) m
             where not (slug = any(?::text[]))
             group by slug order by slug = ? desc, slug like ? desc, slug limit ?',
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

    // d-holders: holder portfolio, name-match groups, search ranking and places.

    /** Trailing words that say what kind of company it is, not whose. */
    private const NAME_TAIL = [
        'PTY', 'PROPRIETARY', 'LTD', 'LIMITED', 'NL', 'INC', 'CORP', 'CORPORATION', 'CO', 'COMPANY', 'PLC', 'LLC',
        'THE', 'AND', 'AUSTRALIA', 'AUSTRALIAN', 'AUST', 'WA', 'RESOURCES', 'MINING', 'MINERALS', 'MINES', 'METALS',
        'GOLD', 'EXPLORATION', 'EXPLORATIONS', 'OPERATIONS', 'HOLDINGS', 'GROUP', 'PROJECTS', 'VENTURES',
        'INVESTMENTS', 'NOMINEES', 'ENTERPRISES',
    ];

    /** One-word stems too common to say anything about who owns a company. */
    private const GENERIC_STEMS = [
        'north', 'northern', 'south', 'southern', 'east', 'eastern', 'west', 'western', 'central', 'great', 'new',
        'golden', 'royal', 'united', 'general', 'national', 'international', 'pacific', 'global', 'first', 'black',
        'red', 'blue', 'white', 'green', 'silver', 'iron', 'mount', 'mt', 'lake',
    ];

    /**
     * A holder's live and pending tenements, newest application first, with
     * their expiry state from today in Perth: past expiry but still live,
     * expiring within 12 months, and expiring within 90 days.
     *
     * @return list<array<string, mixed>>
     */
    public static function holderTenements(string $slug): array
    {
        $today = CarbonImmutable::now('Australia/Perth')->startOfDay();

        return array_map(function ($r) use ($today) {
            $ends = $r->ends_at === null ? null : CarbonImmutable::parse($r->ends_at, 'Australia/Perth')->startOfDay();
            $live = $r->status === 'LIVE' && $ends !== null && $ends->year < 2999;

            return [
                'fmt_tenid' => trim($r->fmt_tenid),
                'slug' => Slug::make($r->fmt_tenid),
                'type' => $r->type,
                'type_code' => $r->type_code,
                'type_label' => Format::TYPE_NAMES[$r->type_code] ?? (Format::sentence($r->type) ?: 'Unknown type'),
                'status' => $r->status,
                'field' => Format::field($r->fmt_tenid),
                'applied_at' => $r->applied_at,
                'granted_at' => $r->granted_at,
                'ends_at' => $r->ends_at,
                'area_ha' => $r->area_ha !== null ? round((float) $r->area_ha, 1) : null,
                'group' => match (true) {
                    ! $live => null,
                    $ends->lt($today) => 'past_expiry',
                    $ends->lt($today->addMonths(12)) => 'expiring',
                    default => null,
                },
                'expires_90d' => $live && $ends->gte($today) && $ends->lte($today->addDays(90)),
            ];
        }, DB::select(
            'select fmt_tenid, type, type_code, status, applied_at, granted_at, ends_at, area_ha from tenements
             where pw_holder_text(holders) like ? and ? = any(pw_slugs(holders))
             order by applied_at desc nulls last, fmt_tenid',
            ["%$slug%", $slug]
        ));
    }

    /**
     * Applications (by application date, whether the tenement is still on
     * the register or now dead) and deaths per month for the last 24 months
     * in Perth, zero-filled, and the applications in the last 12 months.
     *
     * @return array{0: list<array{month: string, applications: int, deaths: int}>, 1: int}
     */
    private static function holderActivity(string $slug): array
    {
        $now = CarbonImmutable::now('Australia/Perth');
        $from = $now->startOfMonth()->subMonths(23)->toDateTimeString();
        $rows = DB::select(
            "select tenid, 'a' as kind, applied_at as at from tenements
              where pw_holder_text(holders) like ? and ? = any(pw_slugs(holders)) and applied_at >= ?
             union all
             select tenid, 'a', started_at from dead_tenements where pw_slugs(holders) @> array[?]::text[] and started_at >= ?
             union all
             select tenid, 'd', ended_at from dead_tenements where pw_slugs(holders) @> array[?]::text[] and ended_at >= ?",
            ["%$slug%", $slug, $from, $slug, $from, $slug, $from]
        );

        $months = [];
        for ($i = 23; $i >= 0; $i--) {
            $months[$now->startOfMonth()->subMonths($i)->format('Y-m')] = ['a' => [], 'd' => []];
        }
        // A tenement counts once per month, and an application once in the
        // year, even when it is both on the register and in the dead layer.
        $yearAgo = $now->subMonths(12)->toDateTimeString();
        $lastYear = [];
        foreach ($rows as $r) {
            $month = substr($r->at, 0, 7);
            if (isset($months[$month])) {
                $months[$month][$r->kind][$r->tenid] = true;
            }
            if ($r->kind === 'a' && $r->at >= $yearAgo) {
                $lastYear[$r->tenid] = true;
            }
        }

        return [array_map(
            fn ($month, $m) => ['month' => $month, 'applications' => count($m['a']), 'deaths' => count($m['d'])],
            array_keys($months), $months,
        ), count($lastYear)];
    }

    /**
     * The holder's tenements as a GeoJSON FeatureCollection for the map's
     * focus mode, and their bounding box. A holder with nothing live or
     * pending gets their latest 1,000 dead tenements instead.
     *
     * @return array{features: array, bbox: ?array}
     */
    private static function holderFocus(string $slug, bool $dead): array
    {
        $rows = $dead ? DB::select(
            "select fmt_tenid, 'DEAD' as status, split_part(fmt_tenid, ' ', 1) as type_code,
                    ST_AsGeoJSON(ST_SimplifyPreserveTopology(geom, 0.0005), 5) as geojson,
                    ST_XMin(geom) as w, ST_YMin(geom) as s, ST_XMax(geom) as e, ST_YMax(geom) as n
             from (select distinct on (tenid) fmt_tenid, geom, ended_at from dead_tenements
                   where pw_slugs(holders) @> array[?]::text[] and geom is not null
                   order by tenid, ended_at desc) d
             order by ended_at desc limit 1000",
            [$slug]
        ) : DB::select(
            'select fmt_tenid, status, type_code,
                    ST_AsGeoJSON(ST_SimplifyPreserveTopology(geom, 0.0002), 5) as geojson,
                    ST_XMin(geom) as w, ST_YMin(geom) as s, ST_XMax(geom) as e, ST_YMax(geom) as n
             from tenements where pw_holder_text(holders) like ? and ? = any(pw_slugs(holders)) and geom is not null',
            ["%$slug%", $slug]
        );

        return [
            'features' => ['type' => 'FeatureCollection', 'features' => array_map(fn ($r) => [
                'type' => 'Feature',
                'geometry' => json_decode($r->geojson),
                'properties' => [
                    'fmt_tenid' => trim($r->fmt_tenid), 'slug' => Slug::make($r->fmt_tenid),
                    'status' => $r->status, 'type_code' => $r->type_code,
                ],
            ], $rows)],
            'bbox' => $rows ? [
                min(array_column($rows, 'w')), min(array_column($rows, 's')),
                max(array_column($rows, 'e')), max(array_column($rows, 'n')),
            ] : null,
        ];
    }

    /**
     * The part of a company name that related entities share: "NORTHERN STAR
     * (KLV) PTY LTD" and "NORTHERN STAR RESOURCES LTD" both give
     * "northern-star". Null when nothing distinctive is left.
     */
    public static function nameStem(string $name): ?string
    {
        $words = preg_split('/[^A-Z0-9]+/', strtoupper(explode('(', $name)[0]), -1, PREG_SPLIT_NO_EMPTY);
        while ($words && in_array(end($words), self::NAME_TAIL, true)) {
            array_pop($words);
        }
        $stem = strtolower(implode('-', $words));

        return strlen($stem) < 3 || in_array($stem, self::GENERIC_STEMS, true) ? null : $stem;
    }

    /**
     * Companies with live or pending tenements whose names start with a stem
     * ("Entities matching NORTHERN STAR"), and how many distinct tenements
     * they hold between them, so joint tenements count once. The register
     * has no parent-company field, so this is a name match and says so.
     * Null unless two or more entities match.
     */
    public static function nameMatches(?string $stem): ?array
    {
        if ($stem === null || $stem === '' || Slug::make($stem) !== $stem) {
            return null;
        }
        $entities = DB::select(
            "select slug, (array_agg(name order by n desc, name))[1] as name, sum(live) as live, sum(pending) as pending
             from (select h.name, pw_slug(h.name) as slug, count(*) as n,
                          count(*) filter (where t.status = 'LIVE') as live,
                          count(*) filter (where t.status = 'PENDING') as pending
                   from tenements t
                   cross join lateral unnest(t.holders, t.holder_is_company) as h(name, is_company)
                   where pw_holder_text(t.holders) like ? and h.is_company
                   group by h.name) m
             where slug = ? or slug like ?
             group by slug order by sum(n) desc, slug",
            ["%$stem%", $stem, "$stem-%"]
        );
        if (count($entities) < 2) {
            return null;
        }
        $distinct = DB::selectOne(
            'select count(*) as n, sum(area_ha) as area_ha from tenements t
             where pw_holder_text(t.holders) like ? and exists (
               select 1 from unnest(t.holders, t.holder_is_company) as h(name, is_company)
               where h.is_company and (pw_slug(h.name) = ? or pw_slug(h.name) like ?))',
            ["%$stem%", $stem, "$stem-%"]
        );
        // The label is the stem as the register spells it in the largest entity's name.
        $words = preg_split('/[^A-Za-z0-9]+/', $entities[0]->name, -1, PREG_SPLIT_NO_EMPTY);

        return [
            'stem' => $stem,
            'label' => implode(' ', array_slice($words, 0, substr_count($stem, '-') + 1)),
            'tenements' => (int) $distinct->n,
            'area_ha' => round((float) $distinct->area_ha),
            'entities' => array_map(fn ($r) => [
                'name' => $r->name, 'slug' => $r->slug, 'live' => (int) $r->live, 'pending' => (int) $r->pending,
            ], $entities),
        ];
    }

    /**
     * Tenements whose compact ID starts with the query, live ones first and
     * then dead ones that have a page. The ID itself ranks first, with any
     * letter suffix ("p 27/2688" finds P 27/2688-S), then IDs of the shape
     * the query spells ("m 26/1" is type M, field 26, a number starting 1),
     * so M 26/1xx, live or dead, lists before M 261SA.
     *
     * @return list<array<string, mixed>>
     */
    private static function searchTenements(string $q, string $compact, int $limit): array
    {
        $shape = preg_match('~^([a-z]{1,3})\s*(\d{1,2})\s*/\s*(\d*)~i', $q, $m)
            ? strtoupper($m[1]).' '.$m[2].'/'.$m[3].'%' : '';
        $shaped = "(? <> '' and fmt_tenid ilike ?)";
        $rank = "pw_compact(fmt_tenid) ~ ? and (? = '' or fmt_tenid ilike ?) as exact, $shaped as shaped";
        $bind = ['^'.$compact.'[a-z]*$', $shape, $shape, $shape, $shape];
        $rows = [
            ...DB::select(
                "select fmt_tenid, type, status, null as death_reason, area_ha, 0 as dead, $rank from tenements
                 where pw_compact(fmt_tenid) like ? order by pw_compact(fmt_tenid), fmt_tenid limit ?",
                [...$bind, $compact.'%', $limit]
            ),
            // Dead tenements have pages too. Skip any ID that is live again.
            ...DB::select(
                "select fmt_tenid, type, 'DEAD' as status, death_reason,
                        ST_Area(geom::geography) / 10000 as area_ha, 1 as dead, $rank from (
                   select fmt_tenid, type, death_reason, geom, ended_at from dead_tenements
                   where pw_compact(fmt_tenid) like ? order by pw_compact(fmt_tenid), ended_at desc limit ?
                 ) d where not exists (select 1 from tenements t where pw_slug(t.fmt_tenid) = pw_slug(d.fmt_tenid))",
                [...$bind, $compact.'%', $limit * 4]
            ),
        ];
        // A stable sort keeps the ID order within each rank.
        usort($rows, fn ($a, $b) => [$b->exact, $b->shaped, $a->dead] <=> [$a->exact, $a->shaped, $b->dead]);

        $out = [];
        foreach ($rows as $row) {
            $slug = Slug::make($row->fmt_tenid);
            if (count($out) < $limit && ! isset($out[$slug])) {
                $out[$slug] = [
                    'kind' => 'tenement', 'slug' => $slug, 'fmt_tenid' => trim($row->fmt_tenid),
                    'type' => $row->type, 'status' => $row->status, 'death_reason' => $row->death_reason,
                    'area_ha' => $row->area_ha !== null ? (float) $row->area_ha : null,
                    'field' => Format::field($row->fmt_tenid),
                ];
            }
        }

        return array_values($out);
    }

    /** Whether h-data's places table exists yet (it ships separately). */
    private static function hasPlaces(): bool
    {
        return (bool) DB::scalar("select to_regclass('public.places') is not null");
    }

    /**
     * Towns and localities whose name starts with the query slug, exact
     * names first, then towns, then shorter names.
     *
     * @return list<array<string, mixed>>
     */
    private static function searchPlaces(string $slug): array
    {
        if (strlen($slug) < 3 || ! self::hasPlaces()) {
            return [];
        }

        return array_map(fn ($r) => [
            'kind' => 'place', 'slug' => (string) $r->id, 'name' => $r->name, 'place_kind' => $r->kind,
            'lga' => $r->lga, 'center' => [round((float) $r->lng, 5), round((float) $r->lat, 5)], 'exact' => (bool) $r->exact,
        ], DB::select(
            "select id, name, kind, lga, ST_X(geom) as lng, ST_Y(geom) as lat, pw_slug(name) = ? as exact
             from places where pw_slug(name) like ?
             order by pw_slug(name) = ? desc, lower(coalesce(kind, '')) like 'town%' desc, length(name), name limit 3",
            [$slug, "$slug%", $slug]
        ));
    }

    /** One place's centre as [lng, lat], or null. */
    public static function place(int $id): ?array
    {
        $p = self::hasPlaces()
            ? DB::selectOne('select ST_X(geom) as lng, ST_Y(geom) as lat from places where id = ?', [$id])
            : null;

        return $p ? [round((float) $p->lng, 5), round((float) $p->lat, 5)] : null;
    }
}
