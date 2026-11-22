<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Throwable;

/** Display helpers shared by the pages, the digest email and the map. */
class Format
{
    public const TYPE_COLOURS = [
        'E' => '#2563eb', 'P' => '#ea580c', 'M' => '#dc2626',
        'L' => '#6b7280', 'G' => '#7c3aed', 'R' => '#0d9488',
    ];

    public const OTHER_COLOUR = '#0f766e';

    public const TYPE_NAMES = [
        'E' => 'Exploration licence', 'P' => 'Prospecting licence', 'M' => 'Mining lease',
        'L' => 'Miscellaneous licence', 'G' => 'General purpose lease', 'R' => 'Retention licence',
    ];

    public const EVENT_COLOURS = [
        'application' => '#2563eb', 'appeared' => '#2563eb', 'reappeared' => '#2563eb',
        'death' => '#111827', 'grant' => '#16a34a', 'holder_change' => '#9333ea',
        'shrink' => '#ea580c', 'grow' => '#0891b2', 'release_scheduled' => '#dc2626',
        'released' => '#dc2626', 'release_cancelled' => '#6b7280', 'status_change' => '#6b7280',
    ];

    /** Register timestamps are AWST wall-clock values stored without a zone. */
    public static function localDate(?string $value, bool $withTime = false): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        try {
            $d = CarbonImmutable::parse($value);

            // 31 Dec 2999 is the register's placeholder for "no date".
            return $d->year >= 2999 ? '' : $d->format($withTime ? 'j M Y, g:ia' : 'j M Y');
        } catch (Throwable) {
            return $value;
        }
    }

    /** Timestamps with a zone (extract and detection times), shown in AWST. */
    public static function awst(?string $value, bool $withTime = true): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        try {
            return CarbonImmutable::parse($value)->setTimezone('Australia/Perth')
                ->format($withTime ? 'j M Y, g:ia' : 'j M Y');
        } catch (Throwable) {
            return $value;
        }
    }

    public static function pct(mixed $v): string
    {
        return is_numeric($v) ? round((float) $v * 100).'%' : '?';
    }

    public static function eventColour(string $type): string
    {
        return self::EVENT_COLOURS[$type] ?? '#6b7280';
    }

    public static function lower(?string $v): string
    {
        return $v === null ? '' : strtolower($v);
    }
}
