<?php

namespace App\Models;

use App\Alerts\WatchShape;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

/**
 * A watch: a circle, a tenement outline plus 2 km, or a holder. It starts
 * unconfirmed when a guest asks for it, and alerts only once confirmed.
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $kind circle | tenement | holder
 * @property float|null $latitude
 * @property float|null $longitude
 * @property float|null $radius_km
 * @property string|null $tenid
 * @property string|null $holder_slug
 * @property list<string> $event_types
 * @property bool $include_covered_deaths
 * @property Carbon|null $confirmed_at
 * @property Carbon|null $unsubscribed_at
 */
class WatchArea extends Model
{
    use Prunable;

    /** Alert types on the free plan (see plan: Free vs Pro). */
    public const FREE_EVENT_TYPES = ['death', 'release_scheduled', 'released'];

    public const ALL_EVENT_TYPES = [
        'death', 'release_scheduled', 'released', 'application', 'grant',
        'holder_change', 'shrink', 'release_cancelled',
    ];

    /** The free alerts as people choose them, each with the event types it covers. */
    public const FREE_ALERTS = [
        'death' => ['death'],
        'release' => ['release_scheduled', 'released'],
    ];

    /** Prospector alert types, shown locked on the free plan. */
    public const PRO_ALERTS = ['New applications', 'Grants', 'Transfers and holder changes', 'Partial surrenders', 'Expiry countdowns'];

    /** Free plan: one area (a circle or a tenement) and one holder watch. */
    public const FREE_LIMIT = 1;

    public const MAX_RADIUS_KM = WatchShape::MAX_RADIUS_KM;

    /** How long a confirm link works. Unconfirmed watches are pruned a day after. */
    public const CONFIRM_DAYS = 7;

    protected $fillable = [
        'name', 'kind', 'latitude', 'longitude', 'radius_km', 'tenid', 'holder_slug',
        'event_types', 'include_covered_deaths',
    ];

    protected $hidden = ['area'];

    protected $attributes = ['kind' => 'circle', 'include_covered_deaths' => false];

    protected function casts(): array
    {
        return [
            'event_types' => 'array', 'latitude' => 'float', 'longitude' => 'float', 'radius_km' => 'float',
            'include_covered_deaths' => 'boolean', 'confirmed_at' => 'datetime', 'unsubscribed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Keep the polygon in step with the shape: the stored area is what alerts match on.
        static::saved(function (WatchArea $area) {
            if (! $area->wasRecentlyCreated && ! $area->wasChanged(['kind', 'latitude', 'longitude', 'radius_km', 'tenid'])) {
                return;
            }
            [$sql, $bind] = $area->shape()->polygonSql() ?? ['null', []];
            DB::update("update watch_areas set area = {$sql} where id = ?", [...$bind, $area->id]);
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function shape(): WatchShape
    {
        return WatchShape::fromArea($this);
    }

    /** Which free limit this counts against: "area" (circles and tenements) or "holder". */
    public function group(): string
    {
        return $this->kind === 'holder' ? 'holder' : 'area';
    }

    /** @param Builder<WatchArea> $q */
    public function scopeInGroup(Builder $q, string $group): void
    {
        $group === 'holder' ? $q->where('kind', 'holder') : $q->where('kind', '<>', 'holder');
    }

    /** @param Builder<WatchArea> $q */
    public function scopeConfirmed(Builder $q): void
    {
        $q->whereNotNull('confirmed_at');
    }

    /** The user's confirmed watch that confirming this one would replace on the free plan, if any. */
    public function replaces(): ?WatchArea
    {
        $others = self::where('user_id', $this->user_id)->inGroup($this->group())->confirmed()
            ->whereKeyNot($this->id)->oldest()->get();

        return $others->count() >= self::FREE_LIMIT ? $others->first() : null;
    }

    /**
     * Confirm this watch, replacing the confirmed one the free plan has room
     * for. Safe to run twice. Returns the name of the watch it replaced.
     */
    public function confirm(): ?string
    {
        return DB::transaction(function () {
            // Lock the user row so two confirms at once cannot both fit under the limit.
            User::whereKey($this->user_id)->lockForUpdate()->first();
            $replaced = $this->replaces();
            $replaced?->delete();
            $this->forceFill(['confirmed_at' => $this->confirmed_at ?? now(), 'unsubscribed_at' => null])->save();

            return $replaced?->name;
        });
    }

    /** Free alert keys (see FREE_ALERTS) this watch has on. */
    public function alerts(): array
    {
        return array_keys(array_filter(self::FREE_ALERTS, fn ($types) => array_intersect($types, $this->event_types ?? [])));
    }

    /** @param list<string> $keys free alert keys */
    public static function eventTypesFor(array $keys): array
    {
        $types = [];
        foreach (self::FREE_ALERTS as $key => $covers) {
            if (in_array($key, $keys, true)) {
                $types = [...$types, ...$covers];
            }
        }

        return $types;
    }

    /** The signed link in the confirm email. Opening it shows a button; only the button confirms. */
    public function confirmUrl(): string
    {
        return URL::temporarySignedRoute('watch.confirm', now()->addDays(self::CONFIRM_DAYS), ['watchArea' => $this->id]);
    }

    /** One-click "Stop alerts for this area" (RFC 8058 POST, or a page with a button), no login needed. */
    public function stopUrl(): string
    {
        return URL::signedRoute('watch-areas.stop', ['watchArea' => $this->id]);
    }

    /** The map at this watch (`/#map=zoom/lat/lng`), or the holder's page. */
    public function mapUrl(): string
    {
        if ($this->kind === 'holder') {
            return url('/holders/'.$this->holder_slug);
        }
        $b = $this->shape()->bounds();
        if (! $b) {
            return url('/');
        }
        $lat = ($b[1] + $b[3]) / 2;
        $spanKm = max(($b[2] - $b[0]) * 111.32 * cos(deg2rad($lat)), ($b[3] - $b[1]) * 110.57, 1);
        // About 1,000 px across, with the watch filling two thirds of it.
        $zoom = (int) max(5, min(14, floor(log(1000 * 40075 * cos(deg2rad($lat)) / (256 * $spanKm * 1.5), 2))));

        return rtrim(url('/'), '/').'/#map='.$zoom.'/'.round($lat, 4).'/'.round(($b[0] + $b[2]) / 2, 4);
    }

    /** Unconfirmed watches whose link has expired. */
    public function prunable(): Builder
    {
        return static::whereNull('confirmed_at')->where('created_at', '<', now()->subDays(self::CONFIRM_DAYS + 1));
    }
}
