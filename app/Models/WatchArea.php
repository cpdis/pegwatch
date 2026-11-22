<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class WatchArea extends Model
{
    /** Alert types on the free plan (see plan: Free vs Pro). */
    public const FREE_EVENT_TYPES = ['death', 'release_scheduled', 'released'];

    public const ALL_EVENT_TYPES = [
        'death', 'release_scheduled', 'released', 'application', 'grant',
        'holder_change', 'shrink', 'release_cancelled',
    ];

    public const MAX_RADIUS_KM = 50;

    protected $fillable = ['name', 'latitude', 'longitude', 'radius_km', 'event_types'];

    protected $hidden = ['area'];

    protected function casts(): array
    {
        return ['event_types' => 'array', 'latitude' => 'float', 'longitude' => 'float', 'radius_km' => 'float'];
    }

    protected static function booted(): void
    {
        // Keep the polygon in step with centre and radius (a geodesic buffer).
        static::saved(function (WatchArea $area) {
            DB::update(
                'update watch_areas set area = ST_Buffer(ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ?)::geometry where id = ?',
                [$area->longitude, $area->latitude, $area->radius_km * 1000, $area->id]
            );
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
