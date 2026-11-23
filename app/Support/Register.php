<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
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

    /**
     * A tenement's register record, without the spatial queries: the live or pending
     * row, else its latest dead one. `tenement` is the page's shape (what
     * TenementSummary reads); `row` is the database row with its outline.
     *
     * @return array{tenement: array, row: object, live: ?object, dead: ?object}|null
     */
    public static function tenementRecord(string $slug): ?array
    {
        $t = DB::selectOne(
            'select tenid, fmt_tenid, type, type_code, status, survstatus, holders, holder_is_company,
                    legal_area, area_unit, special_ind, extract_at, applied_at, granted_at, ends_at, area_ha, geom,
                    ST_AsGeoJSON(geom, 6) as geojson, ST_AsGeoJSON(ST_Envelope(geom)) as bbox
             from tenements where pw_slug(fmt_tenid) = ? limit 1',
            [$slug]
        );
        $dead = null;
        if (! $t) {
            $dead = DB::selectOne(
                'select tenid, fmt_tenid, type, death_reason, holders, holders_are_company, started_at,
                        granted_at, ended_at, extract_at, geom, ST_Area(geom::geography) / 10000 as area_ha,
                        ST_AsGeoJSON(geom, 6) as geojson, ST_AsGeoJSON(ST_Envelope(geom)) as bbox
                 from dead_tenements where pw_slug(fmt_tenid) = ? order by ended_at desc limit 1',
                [$slug]
            );
            if (! $dead) {
                return null;
            }
        }
        $row = $t ?? $dead;
        $tenid = $row->tenid;
        $fmt = trim($row->fmt_tenid);
        $holders = $t
            ? Holders::present(PgArray::parse($t->holders), PgArray::parse($t->holder_is_company))
            : Holders::present(PgArray::parse($dead->holders), PgArray::parse($dead->holders_are_company));

        $tenement = [
            'tenid' => $tenid,
            'fmt_tenid' => $fmt,
            'slug' => Slug::make($fmt),
            'type' => $row->type,
            'holders' => $holders,
            'special' => self::isSpecial($t?->special_ind, $fmt),
            'area_ha' => $row->area_ha !== null ? round((float) $row->area_ha, 1) : null,
            'extract_at' => $row->extract_at,
            'granted_at' => $row->granted_at,
        ] + ($t ? [
            'type_code' => $t->type_code,
            'status' => $t->status,
            'survey_status' => $t->survstatus,
            'legal_area' => $t->legal_area !== null ? (float) $t->legal_area : null,
            'area_unit' => $t->area_unit,
            'applied_at' => $t->applied_at,
            'ends_at' => $t->ends_at,
            'dead' => null,
        ] : [
            // The dead layer carries no type code; the ID's letters are the same thing.
            'type_code' => preg_match('/^\s*([A-Z]+)/i', $fmt, $m) ? strtoupper($m[1]) : null,
            'status' => 'DEAD',
            'survey_status' => null,
            'legal_area' => null,
            'area_unit' => null,
            'applied_at' => $dead->started_at,
            'ends_at' => $dead->ended_at,
            'dead' => ['reason' => $dead->death_reason, 'ended_at' => $dead->ended_at],
        ]);

        return ['tenement' => $tenement, 'row' => $row, 'live' => $t, 'dead' => $dead];
    }

    public static function tenement(string $slug): ?array
    {
        $record = self::tenementRecord($slug);
        if (! $record) {
            return null;
        }
        ['tenement' => $tenement, 'row' => $row, 'live' => $t, 'dead' => $dead] = $record;
        $tenid = $tenement['tenid'];
        $fmt = $tenement['fmt_tenid'];
        $holders = $tenement['holders'];

        // The outline as hex EWKB, handed back to the spatial queries below. A row with
        // no outline gets an empty one, so those queries find nothing instead of failing.
        $geom = $row->geom ?? 'SRID=4326;MULTIPOLYGON EMPTY';
        $releases = self::tenementReleases($tenid);
        $overlaps = self::overlaps($geom, $tenid, $holders);

        return [
            'tenement' => $tenement,
            'geometry' => json_decode($row->geojson ?? 'null'),
            'bbox' => self::bboxUnion([self::bbox($row->bbox), ...array_column($releases, 'bbox')]),
            'events' => self::events(['tenid' => $tenid], 50),
            'overlaps' => $overlaps,
            'releases' => $releases,
            'restrictions' => self::restrictions($geom),
            'actions' => self::pendingActions($tenid),
            'uncovered' => $dead ? self::uncoveredShare($geom) : null,
            'held_before' => self::heldBefore($geom, $tenid),
            'neighbours' => self::neighbours($geom, $tenid, $holders, array_column($overlaps, 'fmt_tenid')),
            'holder_context' => self::holderContext($row->holders, $geom, $tenid),
            'location' => self::nearestPlace($geom),
            'wait' => $t?->status === 'PENDING' ? self::waitToGrant($t->type_code, $fmt, $t->applied_at) : null,
        ];
    }

    /**
     * Live and pending tenements over at least 0.5% of the given ground, oldest
     * application first (lodgement order). "Can overlap" marks the kinds that may sit
     * over other tenements: L and G licences and special prospecting licences.
     *
     * @param  string  $geom  the subject's outline as hex EWKB
     * @param  array  $holders  the subject's holders, from Holders::present()
     */
    public static function overlaps(string $geom, string $tenid, array $holders = []): array
    {
        $mine = array_column($holders, 'slug');

        return array_map(function ($r) use ($mine) {
            $holders = Holders::present(PgArray::parse($r->holders), PgArray::parse($r->holder_is_company));
            $special = self::isSpecial($r->special_ind, $r->fmt_tenid);

            return [
                'fmt_tenid' => trim($r->fmt_tenid),
                'slug' => Slug::make($r->fmt_tenid),
                'type' => $r->type,
                'type_code' => $r->type_code,
                'status' => $r->status,
                'holders' => $holders,
                'same_holder' => (bool) array_intersect($mine, array_column($holders, 'slug')),
                'special' => $special,
                'can_overlap' => $special || in_array($r->type_code, ['L', 'G'], true),
                'applied_at' => $r->applied_at,
                'share' => round((float) $r->share, 3),
            ];
        }, DB::select(
            'select * from (
               select o.fmt_tenid, o.type, o.type_code, o.status, o.holders, o.holder_is_company,
                      o.special_ind, o.applied_at,
                      ST_Area(ST_Intersection(o.geom, s.geom)) / nullif(ST_Area(s.geom), 0) as share
               from (select ?::geometry as geom offset 0) s
               join tenements o on o.geom && s.geom and o.tenid <> ? and ST_Intersects(o.geom, s.geom)
             ) x where share >= 0.005
             order by applied_at nulls last, fmt_tenid limit 50',
            [$geom, $tenid]
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

    // g-visual: share previews, OG images and the data status page.

    /**
     * One tenement's facts for a share preview: the live or pending record,
     * else its latest dead one. With $outline, also its shape as GeoJSON,
     * simplified to about 2 m for drawing.
     */
    public static function tenementBrief(string $slug, bool $outline = false): ?object
    {
        $geom = $outline ? 'ST_AsGeoJSON(ST_SimplifyPreserveTopology(geom, 0.00002), 6)' : 'null';

        return DB::selectOne(
            "select * from (
               (select 0 as rank, fmt_tenid, type, status, survstatus, holders, legal_area, area_unit, area_ha,
                       applied_at, granted_at, ends_at, null as death_reason, $geom as geojson
                from tenements where pw_slug(fmt_tenid) = ? limit 1)
               union all
               (select 1, fmt_tenid, type, 'DEAD', null, holders, null, null, null,
                       started_at, granted_at, ended_at, death_reason, $geom
                from dead_tenements where pw_slug(fmt_tenid) = ? order by ended_at desc limit 1)
             ) t order by rank limit 1",
            [$slug, $slug]
        );
    }

    /** A holder's name, whether every record calls it a company, and its live and pending counts. */
    public static function holderBrief(string $slug): ?object
    {
        $h = DB::selectOne(
            "select mode() within group (order by h.name) as name, bool_and(h.is_company) as is_company,
                    count(*) filter (where t.status = 'LIVE') as live,
                    count(*) filter (where t.status = 'PENDING') as pending,
                    coalesce(sum(t.area_ha), 0) as area_ha
             from tenements t cross join lateral unnest(t.holders, t.holder_is_company) as h(name, is_company)
             where pw_holder_text(t.holders) like ? and pw_slug(h.name) = ?",
            ['%'.$slug.'%', $slug]
        );
        if ($h->name !== null) {
            return $h;
        }

        return DB::selectOne(
            'select h.name, h.is_company, 0 as live, 0 as pending, 0 as area_ha
             from dead_tenements d cross join lateral unnest(d.holders, d.holders_are_company) as h(name, is_company)
             where pw_slugs(d.holders) @> array[?]::text[] and pw_slug(h.name) = ?
             order by h.is_company limit 1',
            [$slug, $slug]
        );
    }

    /**
     * The last nightly checks, newest first, each with its layers' extract
     * times and row counts and the number of change events it found.
     */
    public static function recentRuns(int $limit = 30): array
    {
        $runs = DB::select(
            'select r.id, r.started_at, r.finished_at, r.status, r.tenements_extract, r.stale,
                    (select count(*) from tenement_events e where e.run_id = r.id) as events
             from ingest_runs r order by r.id desc limit ?',
            [$limit]
        );
        $layers = collect(DB::select(
            'select run_id, layer_code, layer_key, fetched_rows, max_extract from layer_extracts
             where run_id = any(?::bigint[]) order by layer_code',
            ['{'.implode(',', array_map(fn ($r) => (int) $r->id, $runs)).'}']
        ))->groupBy('run_id');

        return array_map(fn ($r) => (object) [
            ...(array) $r,
            'events' => (int) $r->events,
            'layers' => $layers->get($r->id, collect())->all(),
        ], $runs);
    }

    // c-tenement: tenement page queries. The spatial ones take the subject's outline as
    // hex EWKB (from tenement()), so live and dead tenements share them. They read it
    // through `(select ?::geometry as geom offset 0) s`: the offset stops Postgres
    // inlining the parameter into every row, so PostGIS can cache the outline across
    // rows (without it, neighbours of TR 70/5461 took 7.8 s instead of 80 ms).

    /** A special prospecting licence: flagged in the register, or an ID ending in S ("P 27/2688S", "P 09/508-S"). */
    private static function isSpecial(?string $indicator, string $fmtTenid): bool
    {
        return trim((string) $indicator) === 'S' || (bool) preg_match('~/\d+-?S\s*$~i', $fmtTenid);
    }

    /** The smallest box around every given box ([w, s, e, n]); nulls are skipped. */
    private static function bboxUnion(array $boxes): ?array
    {
        $boxes = array_values(array_filter($boxes));

        return $boxes ? [
            min(array_column($boxes, 0)), min(array_column($boxes, 1)),
            max(array_column($boxes, 2)), max(array_column($boxes, 3)),
        ] : null;
    }

    /** Scheduled compulsory releases of part of a tenement, soonest first, with the released hectares and parcel. */
    public static function tenementReleases(string $tenid): array
    {
        return array_map(fn ($r) => [
            'rlesid' => $r->rlesid,
            'release_at' => $r->release_at,
            'release_local' => $r->release_local,
            'ha' => $r->ha !== null ? round((float) $r->ha, 1) : null,
            'geometry' => json_decode($r->geojson ?? 'null'),
            'bbox' => self::bbox($r->bbox),
        ], DB::select(
            "select rlesid, release_at,
                    to_char(release_at at time zone 'Australia/Perth', 'YYYY-MM-DD HH24:MI:SS') as release_local,
                    ST_Area(geom::geography) / 10000 as ha,
                    ST_AsGeoJSON(geom, 6) as geojson, ST_AsGeoJSON(ST_Envelope(geom)) as bbox
             from release_pending where tenid = ? order by release_at, rlesid",
            [$tenid]
        ));
    }

    /** The next scheduled release of a tenement, by its page slug, with the AWST wall-clock time (for the .ics route). */
    public static function nextRelease(string $slug): ?object
    {
        return DB::selectOne(
            "select trim(t.fmt_tenid) as fmt_tenid, t.slug, r.rlesid,
                    to_char(r.release_at at time zone 'Australia/Perth', 'YYYY-MM-DD HH24:MI:SS') as release_local
             from (select tenid, fmt_tenid, pw_slug(fmt_tenid) as slug from tenements where pw_slug(fmt_tenid) = ?) t
             join release_pending r on r.tenid = t.tenid
             order by r.release_at, r.rlesid limit 1",
            [$slug]
        );
    }

    /** Land restrictions over at least 0.5% of the ground, largest share first. */
    public static function restrictions(string $geom): array
    {
        return array_map(fn ($r) => [
            'kind' => $r->kind,
            'land_id' => $r->land_id,
            'name' => $r->name,
            'purpose' => $r->purpose,
            'share' => round((float) $r->share, 3),
        ], DB::select(
            'select * from (
               select r.kind, r.land_id, r.name, r.purpose,
                      ST_Area(ST_Intersection(r.geom, s.geom)) / nullif(ST_Area(s.geom), 0) as share
               from (select ?::geometry as geom offset 0) s
               join land_restrictions r on r.geom && s.geom and ST_Intersects(r.geom, s.geom)
             ) x where share >= 0.005 order by share desc, kind, land_id',
            [$geom]
        ));
    }

    /** Amalgamation and restoration applications pending on a tenement, oldest first. */
    public static function pendingActions(string $tenid): array
    {
        return array_map(fn ($r) => (array) $r, DB::select(
            "select 'amalgamation' as kind, amalgid as id, starts_at as lodged_at from amalgamation_pending where tenid = ?
             union all
             select 'restoration', restnid, starts_at from restoration_pending where tenid = ?
             order by lodged_at, id",
            [$tenid, $tenid]
        ));
    }

    /**
     * The share of the ground with no blocking tenement over it today: live or pending,
     * and not an L or G licence or a special prospecting licence. Null without an outline.
     */
    public static function uncoveredShare(string $geom): ?float
    {
        $share = DB::scalar(
            "select case when ST_Area(s.geom) = 0 then null else greatest(0, least(1,
                      1 - coalesce(ST_Area(ST_Union(ST_Intersection(o.geom, s.geom))), 0) / ST_Area(s.geom))) end
             from (select ?::geometry as geom offset 0) s
             left join tenements o on o.geom && s.geom and ST_Intersects(o.geom, s.geom)
               and coalesce(o.type_code, '') not in ('L', 'G')
               and coalesce(trim(o.special_ind), '') <> 'S' and o.fmt_tenid !~* '/[0-9]+-?S\s*$'
             group by s.geom",
            [$geom]
        );

        return $share === null ? null : round((float) $share, 3);
    }

    /** Dead tenements that covered at least 10% of the ground, the most recent end first. */
    public static function heldBefore(string $geom, string $tenid): array
    {
        return array_map(fn ($r) => [
            'fmt_tenid' => trim($r->fmt_tenid),
            'slug' => Slug::make($r->fmt_tenid),
            'type' => $r->type,
            'holders' => Holders::present(PgArray::parse($r->holders), PgArray::parse($r->holders_are_company)),
            'started_at' => $r->started_at,
            'ended_at' => $r->ended_at,
            'reason' => $r->death_reason,
            'share' => round((float) $r->share, 3),
        ], DB::select(
            'select * from (
               select distinct on (d.tenid) d.fmt_tenid, d.type, d.holders, d.holders_are_company,
                      d.started_at, d.ended_at, d.death_reason,
                      ST_Area(ST_Intersection(d.geom, s.geom)) / nullif(ST_Area(s.geom), 0) as share
               from (select ?::geometry as geom offset 0) s
               join dead_tenements d on d.geom && s.geom and d.tenid <> ? and ST_Intersects(d.geom, s.geom)
               order by d.tenid, d.ended_at desc
             ) x where share >= 0.1 order by ended_at desc, fmt_tenid limit 20',
            [$geom, $tenid]
        ));
    }

    /**
     * Live and pending tenements within 100 m, except those in $exclude (the overlaps,
     * which have their own block), grouped by holder, the group with the newest
     * application first.
     */
    public static function neighbours(string $geom, string $tenid, array $holders = [], array $exclude = []): array
    {
        $mine = array_column($holders, 'slug');
        $groups = [];
        $rows = DB::select(
            'select o.fmt_tenid, o.type, o.type_code, o.status, o.holders, o.holder_is_company, o.applied_at
             from (select ?::geometry as geom offset 0) s
             join tenements o on o.geom && ST_Expand(s.geom, 0.002) and o.tenid <> ?
               and ST_DWithin(o.geom::geography, s.geom::geography, 100)
             order by o.applied_at desc nulls last, o.fmt_tenid limit 60',
            [$geom, $tenid]
        );
        foreach ($rows as $r) {
            $fmt = trim($r->fmt_tenid);
            if (in_array($fmt, $exclude, true)) {
                continue;
            }
            $h = Holders::present(PgArray::parse($r->holders), PgArray::parse($r->holder_is_company));
            $slugs = array_column($h, 'slug');
            $groups[implode('|', $slugs)] ??= [
                'holders' => $h,
                'same_holder' => (bool) array_intersect($mine, $slugs),
                'tenements' => [],
            ];
            $groups[implode('|', $slugs)]['tenements'][] = [
                'fmt_tenid' => $fmt,
                'slug' => Slug::make($fmt),
                'type' => $r->type,
                'type_code' => $r->type_code,
                'status' => $r->status,
                'applied_at' => $r->applied_at,
            ];
        }

        return array_values($groups);
    }

    /**
     * Each holder's live and pending count ("105 tenements · 37 pending") and the
     * holders' other live or pending tenements within 20 km, nearest first.
     *
     * @param  string  $holders  the subject's holders as a Postgres text[] literal
     */
    public static function holderContext(string $holders, string $geom, string $tenid): array
    {
        $counts = DB::select(
            "select h.name, count(*) as total, count(*) filter (where t.status = 'PENDING') as pending
             from tenements t cross join lateral unnest(t.holders) as h(name)
             where t.holders && ?::text[] and h.name = any(?::text[])
             group by h.name",
            [$holders, $holders]
        );
        $nearby = DB::select(
            'select o.fmt_tenid, o.type, o.type_code, o.status,
                    ST_Distance(o.geom::geography, s.geom::geography) as m, count(*) over () as n
             from (select ?::geometry as geom offset 0) s
             join tenements o on o.holders && ?::text[] and o.tenid <> ?
               and o.geom && ST_Expand(s.geom, 0.25) and ST_DWithin(o.geom::geography, s.geom::geography, 20000)
             order by m, o.fmt_tenid limit 8',
            [$geom, $holders, $tenid]
        );

        return [
            'counts' => array_column(array_map(fn ($r) => [
                'name' => $r->name, 'total' => (int) $r->total, 'pending' => (int) $r->pending,
            ], $counts), null, 'name'),
            'nearby' => array_map(fn ($r) => [
                'fmt_tenid' => trim($r->fmt_tenid),
                'slug' => Slug::make($r->fmt_tenid),
                'type' => $r->type,
                'type_code' => $r->type_code,
                'status' => $r->status,
                'km' => round((float) $r->m / 1000, 1),
            ], $nearby),
            'nearby_total' => (int) ($nearby[0]->n ?? 0),
        ];
    }

    /** The nearest place in `places` (h-data) with distance and bearing from it, or null until that table exists. */
    public static function nearestPlace(string $geom): ?array
    {
        if (! DB::scalar("select to_regclass('places') is not null")) {
            return null;
        }
        $r = DB::selectOne(
            'select p.name, ST_Distance(p.geom::geography, s.c::geography) as m,
                    degrees(ST_Azimuth(p.geom::geography, s.c::geography)) as bearing
             from (select ST_PointOnSurface(?::geometry) as c) s cross join places p
             where not ST_IsEmpty(s.c)
             order by p.geom <-> s.c limit 1',
            [$geom]
        );

        return $r ? [
            'place' => $r->name,
            'km' => round((float) $r->m / 1000, 1),
            'bearing' => $r->bearing !== null ? (float) $r->bearing : null,
        ] : null;
    }

    /**
     * How long applications of this type took to be granted, from grants since 2020
     * (live tenements, and dead ones that had been granted), by mineral field. A field
     * needs 10 grants; with fewer, the WA-wide figure for the type stands in. The
     * medians are cached per ingest run.
     */
    public static function waitToGrant(?string $typeCode, string $fmtTenid, ?string $appliedAt): ?array
    {
        if ($typeCode === null || $appliedAt === null) {
            return null;
        }
        $key = 'wait-to-grant:'.(self::latestRun()?->id ?? 0).':'.$typeCode;
        $byField = Cache::remember($key, now()->addDay(), fn () => array_column(array_map(fn ($r) => (array) $r, DB::select(
            <<<'SQL'
            with g as (
              select fmt_tenid, applied_at as applied, granted_at as granted from tenements
               where type_code = ? and granted_at >= '2020-01-01' and granted_at > applied_at
              union all
              select d.fmt_tenid, d.started_at, d.granted_at from (
                select distinct on (tenid) tenid, fmt_tenid, started_at, granted_at from dead_tenements
                 where granted_at >= '2020-01-01' and granted_at > started_at
                 order by tenid, ended_at desc
              ) d
              where upper(substring(d.fmt_tenid from '^\s*([A-Za-z]+)')) = ?
                and not exists (select 1 from tenements t where t.tenid = d.tenid)
            )
            select case when grouping(field) = 1 then 'WA' else coalesce(field, '') end as field, count(*) as n,
                   percentile_cont(0.5) within group (order by extract(epoch from granted - applied) / 86400 / 30.4375) as months
            from (select substring(fmt_tenid from '^\s*[A-Za-z]{1,2}\s*(\d{2})\s*/') as field, applied, granted from g) x
            group by rollup (field)
            SQL,
            [$typeCode, $typeCode]
        )), null, 'field'));

        $field = Format::fieldNumber($fmtTenid);
        $scope = $field !== null && ($byField[$field]['n'] ?? 0) >= 10 ? 'field' : 'wa';
        $stats = $scope === 'field' ? $byField[$field] : ($byField['WA'] ?? null);
        if (! $stats || $stats['n'] < 10) {
            return null;
        }
        $applied = CarbonImmutable::parse($appliedAt, 'Australia/Perth');

        return [
            'scope' => $scope,
            'grants' => (int) $stats['n'],
            'median_months' => (int) round((float) $stats['months']),
            'waited_months' => (int) floor($applied->diffInMonths(CarbonImmutable::now('Australia/Perth'))),
        ];
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
