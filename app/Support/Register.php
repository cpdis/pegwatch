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

    public static function search(string $q, int $limit = 10): array
    {
        $q = mb_check_encoding($q, 'UTF-8') ? trim($q) : '';
        if (mb_strlen($q) < 2) {
            return ['tenements' => [], 'holders' => []];
        }
        $compact = preg_replace('/[^a-z0-9]/', '', strtolower($q));
        $tenements = $compact === '' ? [] : DB::select(
            "select fmt_tenid, type, status from tenements
             where regexp_replace(lower(fmt_tenid), '[^a-z0-9]', '', 'g') like ?
             order by length(fmt_tenid), fmt_tenid limit ?",
            [$compact.'%', $limit]
        );
        $holders = DB::select(
            'select h.name, count(*) as n from tenements t cross join lateral unnest(t.holders) as h(name)
             where h.name ilike ? group by h.name order by count(*) desc limit ?',
            ['%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%', $limit]
        );

        return [
            'tenements' => array_map(fn ($t) => [
                'fmt_tenid' => trim($t->fmt_tenid), 'slug' => Slug::make($t->fmt_tenid),
                'type' => $t->type, 'status' => $t->status,
            ], $tenements),
            'holders' => array_map(fn ($h) => [
                'name' => $h->name, 'slug' => Slug::make($h->name), 'count' => (int) $h->n,
            ], $holders),
        ];
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
}
