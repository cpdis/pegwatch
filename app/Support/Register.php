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

    public static function tenement(string $slug): ?array
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
}
