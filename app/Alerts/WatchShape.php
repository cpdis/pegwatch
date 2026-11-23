<?php

namespace App\Alerts;

use App\Models\WatchArea;
use App\Support\Format;
use App\Support\Slug;
use Illuminate\Support\Facades\DB;
use Livewire\Wireable;

/**
 * What a watch covers: a circle, a tenement's outline plus 2 km, or a holder.
 * Parsed once where it enters (a map `watch-shape` event, a query string, a
 * stored watch area); everything after trusts it.
 */
final class WatchShape implements Wireable
{
    public const TENEMENT_BUFFER_KM = 2;

    public const MIN_RADIUS_KM = 1;

    public const MAX_RADIUS_KM = 50;

    private function __construct(
        public readonly string $kind,
        public readonly ?float $lng = null,
        public readonly ?float $lat = null,
        public readonly ?float $radiusKm = null,
        public readonly ?string $tenid = null,
        public readonly ?string $holderSlug = null,
        // The tenement ID ("M 27/518") or the holder's name as registered.
        public readonly ?string $label = null,
    ) {}

    public static function circle(mixed $lng, mixed $lat, mixed $radiusKm): ?self
    {
        if (! is_numeric($lng) || ! is_numeric($lat) || ! is_numeric($radiusKm)) {
            return null;
        }
        [$lng, $lat] = [(float) $lng, (float) $lat];
        // Roughly WA, with a margin.
        if ($lat < -36 || $lat > -13 || $lng < 112 || $lng > 130) {
            return null;
        }
        $r = min(max((float) $radiusKm, self::MIN_RADIUS_KM), self::MAX_RADIUS_KM);

        return new self('circle', round($lng, 5), round($lat, 5), round($r, 1));
    }

    /** A live, pending or dead tenement by its page slug ("m-27-518"). */
    public static function tenement(mixed $slug): ?self
    {
        if (! is_string($slug) || $slug === '' || strlen($slug) > 40) {
            return null;
        }
        $t = DB::selectOne('select tenid, fmt_tenid from tenements where pw_slug(fmt_tenid) = ? limit 1', [$slug])
            ?? DB::selectOne('select tenid, fmt_tenid from dead_tenements where pw_slug(fmt_tenid) = ? order by ended_at desc limit 1', [$slug]);

        return $t ? new self('tenement', tenid: $t->tenid, label: trim($t->fmt_tenid)) : null;
    }

    /** A holder by page slug, named as the register names them (natural persons by name only). */
    public static function holder(mixed $slug): ?self
    {
        if (! is_string($slug) || $slug === '' || strlen($slug) > 200) {
            return null;
        }
        $name = DB::scalar(
            'select h.name from tenements t cross join lateral unnest(t.holders) as h(name)
             where pw_holder_text(t.holders) like ? and pw_slug(h.name) = ?
             group by h.name order by count(*) desc limit 1',
            ['%'.$slug.'%', $slug]
        ) ?? DB::scalar(
            'select h.name from dead_tenements d cross join lateral unnest(d.holders) as h(name)
             where public.pw_slugs(d.holders) @> array[?]::text[] and pw_slug(h.name) = ? limit 1',
            [$slug, $slug]
        );

        return $name ? new self('holder', holderSlug: $slug, label: $name) : null;
    }

    /** The detail of a `watch-shape` browser event (contracts.md), or null when it is not one. */
    public static function fromEvent(mixed $detail): ?self
    {
        if (! is_array($detail)) {
            return null;
        }

        return match ($detail['kind'] ?? null) {
            'circle' => is_array($detail['center'] ?? null)
                ? self::circle($detail['center'][0] ?? null, $detail['center'][1] ?? null, $detail['radiusKm'] ?? null)
                : null,
            'tenement' => self::tenement($detail['slug'] ?? null),
            'holder' => self::holder($detail['slug'] ?? null),
            default => null,
        };
    }

    public static function fromArea(WatchArea $a): self
    {
        return new self($a->kind, $a->longitude, $a->latitude, $a->radius_km, $a->tenid, $a->holder_slug,
            match ($a->kind) {
                'tenement' => DB::scalar('select coalesce((select fmt_tenid from tenements where tenid = ?),
                    (select fmt_tenid from dead_tenements where tenid = ? order by ended_at desc limit 1))', [$a->tenid, $a->tenid]),
                'holder' => self::holder($a->holder_slug)?->label ?? $a->name,
                default => null,
            });
    }

    public function withRadius(mixed $km): self
    {
        return $this->kind === 'circle' ? (self::circle($this->lng, $this->lat, $km) ?? $this) : $this;
    }

    public function isArea(): bool
    {
        return $this->kind !== 'holder';
    }

    /** The columns a watch area stores for this shape. */
    public function attributes(): array
    {
        return [
            'kind' => $this->kind,
            'longitude' => $this->lng,
            'latitude' => $this->lat,
            'radius_km' => $this->radiusKm,
            'tenid' => $this->tenid,
            'holder_slug' => $this->holderSlug,
        ];
    }

    /**
     * SQL for the watched polygon and its bindings: a geodesic circle, or the
     * tenement's outline (live, pending, or its latest dead record) plus 2 km.
     * Holders have none.
     *
     * @return array{0: string, 1: list<mixed>}|null
     */
    public function polygonSql(): ?array
    {
        return match ($this->kind) {
            'circle' => [
                'ST_Multi(ST_Buffer(ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ?)::geometry)',
                [$this->lng, $this->lat, $this->radiusKm * 1000],
            ],
            'tenement' => [
                'ST_Multi(ST_Buffer(coalesce((select geom from tenements where tenid = ?),
                    (select geom from dead_tenements where tenid = ? order by ended_at desc limit 1))::geography, ?)::geometry)',
                [$this->tenid, $this->tenid, self::TENEMENT_BUFFER_KM * 1000],
            ],
            default => null,
        };
    }

    /** [west, south, east, north] of the shape, or of a holder's live and pending tenements. */
    public function bounds(): ?array
    {
        if ($this->kind === 'circle') {
            $dLat = $this->radiusKm / 110.57;
            $dLng = $this->radiusKm / (111.32 * cos(deg2rad($this->lat)));

            return [$this->lng - $dLng, $this->lat - $dLat, $this->lng + $dLng, $this->lat + $dLat];
        }
        [$sql, $bind] = $this->kind === 'holder'
            ? ['select ST_Extent(t.geom) from '.$this->source('t')[0], $this->source('t')[1]]
            : $this->polygonSql();
        $b = DB::selectOne("select ST_XMin(g) as w, ST_YMin(g) as s, ST_XMax(g) as e, ST_YMax(g) as n from (select ({$sql}) as g) x", $bind);

        return $b?->w === null ? null : [(float) $b->w, (float) $b->s, (float) $b->e, (float) $b->n];
    }

    /** The shape as a GeoJSON geometry for the map's highlight (simplified holdings for a holder). */
    public function geometry(): ?array
    {
        [$sql, $bind] = $this->kind === 'holder'
            ? ['select ST_CollectionExtract(ST_SimplifyPreserveTopology(ST_Collect(t.geom), 0.0003), 3) from '.$this->source('t')[0], $this->source('t')[1]]
            : $this->polygonSql();
        $json = DB::scalar("select ST_AsGeoJSON(({$sql}), 5)", $bind);

        return $json ? json_decode($json, true) : null;
    }

    /** A name for a new watch: the nearest town (when places exist), else the mineral field. */
    public function defaultName(): string
    {
        if ($this->kind !== 'circle') {
            return (string) $this->label;
        }
        if (DB::scalar("select to_regclass('public.places') is not null")) {
            $place = DB::scalar(
                'select name from places order by geom <-> ST_SetSRID(ST_MakePoint(?, ?), 4326) limit 1',
                [$this->lng, $this->lat]
            );
            if ($place) {
                return $place;
            }
        }
        $nearest = DB::scalar(
            'select fmt_tenid from tenements order by geom <-> ST_SetSRID(ST_MakePoint(?, ?), 4326) limit 1',
            [$this->lng, $this->lat]
        );

        $field = Format::field($nearest);

        // Field 69 is "Outside Proclaimed": not a place anyone would call the ground.
        return $field !== null && Format::fieldNumber($nearest) !== '69' ? $field : __('My watch area');
    }

    /** One line on what is watched: "10 km around -30.7500, 121.4700". */
    public function describe(): string
    {
        return match ($this->kind) {
            'circle' => __(':r km around :lat, :lng', [
                'r' => rtrim(rtrim(number_format($this->radiusKm, 1), '0'), '.'),
                'lat' => number_format($this->lat, 4), 'lng' => number_format($this->lng, 4),
            ]),
            'tenement' => __(':id and 2 km around it', ['id' => $this->label]),
            default => __('Tenements held by :name', ['name' => $this->label]),
        };
    }

    /**
     * Tenements inside today and register changes in the last 30 days, for
     * the live counts while a shape is drawn.
     *
     * @return array{live: int, pending: int, deaths: int, applications: int, releases: int}
     */
    public function summary(): array
    {
        [$from, $bind] = $this->source('t');
        [$events, $eventBind] = $this->source('e', 'tenement_events');
        $r = DB::selectOne(
            "select (select count(*) filter (where t.status = 'LIVE') from {$from}) as live_n,
                    (select count(*) filter (where t.status = 'PENDING') from {$from}) as pending_n,
                    (select count(*) filter (where e.event_type = 'death') from {$events}
                       and e.created_at >= now() - interval '30 days' and not jsonb_exists(e.details, 'reversed_by_run')) as deaths_n,
                    (select count(*) filter (where e.event_type = 'application') from {$events}
                       and e.created_at >= now() - interval '30 days' and not jsonb_exists(e.details, 'reversed_by_run')) as applications_n,
                    (select count(*) filter (where e.event_type = 'release_scheduled') from {$events}
                       and e.created_at >= now() - interval '30 days' and not jsonb_exists(e.details, 'reversed_by_run')) as releases_n",
            [...$bind, ...$bind, ...$eventBind, ...$eventBind, ...$eventBind]
        );

        return [
            'live' => (int) $r->live_n, 'pending' => (int) $r->pending_n, 'deaths' => (int) $r->deaths_n,
            'applications' => (int) $r->applications_n, 'releases' => (int) $r->releases_n,
        ];
    }

    /**
     * Live and pending tenements inside, pending first then newest.
     *
     * @return list<array{fmt_tenid: string, slug: string, status: string, type: ?string, holder: ?string}>
     */
    public function tenements(int $limit = 10): array
    {
        [$from, $bind] = $this->source('t');

        return array_map(fn ($r) => [
            'fmt_tenid' => trim($r->fmt_tenid), 'slug' => Slug::make($r->fmt_tenid), 'status' => (string) $r->status,
            'type' => $r->type, 'holder' => $r->holder,
        ], DB::select(
            "select t.fmt_tenid, t.status, t.type, t.holders[1] as holder from {$from}
             order by t.status = 'PENDING' desc, t.applied_at desc nulls last limit ?",
            [...$bind, $limit]
        ));
    }

    /**
     * A FROM clause over $table (with geom and holders columns) limited to the
     * rows this shape covers, ending in a WHERE so callers can add "and ...".
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private function source(string $alias, string $table = 'tenements'): array
    {
        if ($this->kind === 'holder') {
            // The trigram prefilter only exists on the tenement tables.
            $prefilter = $table === 'tenements' ? "pw_holder_text({$alias}.holders) like ? and " : '';

            return [
                "{$table} {$alias} where {$prefilter}? = any(public.pw_slugs({$alias}.holders))",
                $table === 'tenements' ? ['%'.$this->holderSlug.'%', $this->holderSlug] : [$this->holderSlug],
            ];
        }
        [$poly, $bind] = $this->polygonSql();

        return ["{$table} {$alias}, (select {$poly} as g) w where {$alias}.geom && w.g and ST_Intersects({$alias}.geom, w.g)", $bind];
    }

    public function toLivewire(): array
    {
        return get_object_vars($this);
    }

    public static function fromLivewire($value): self
    {
        return new self(...$value);
    }
}
