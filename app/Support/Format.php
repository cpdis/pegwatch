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

    /** The department's TENGRAPH page, the place users must confirm anything Pegwatch shows. */
    public const TENGRAPH_URL = 'https://www.wa.gov.au/service/natural-resources/mineral-resources/access-tengraph-web';

    /** One status palette for badges, dots and the map. No tenement type colour reuses these. */
    public const STATUS_COLOURS = [
        'live' => '#15803d', 'pending' => '#d97706', 'dead' => '#71717a',
        'release' => '#c026d3', 'restricted' => '#a16207',
    ];

    public const STATUS_LABELS = [
        'live' => 'Live', 'pending' => 'Pending', 'dead' => 'Dead',
        'release' => 'Release due', 'restricted' => 'Restricted',
    ];

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

    /** The register's own area, in its unit: BL. is graticular blocks, HA. hectares. */
    public static function legalArea(float $area, ?string $unit): string
    {
        $n = rtrim(rtrim(number_format($area, 4), '0'), '.');

        return match (strtoupper(rtrim((string) $unit, '.'))) {
            'BL' => $n.' '.($area == 1 ? 'block' : 'blocks'),
            'HA' => $n.' ha',
            'KM2' => $n.' km²',
            default => trim($n.' '.strtolower(rtrim((string) $unit, '.'))),
        };
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

    /** Register words (types, statuses, survey states) in sentence case. Never use it on holder names. */
    public static function sentence(?string $v): string
    {
        $v = trim((string) $v);

        return $v === '' ? '' : ucfirst(strtolower($v));
    }

    /** A status key for badges and colours from a register status ("LIVE") or a key ("release"). */
    public static function statusKey(?string $status): string
    {
        $k = strtolower(trim((string) $status));

        return array_key_exists($k, self::STATUS_COLOURS) ? $k : 'dead';
    }

    /**
     * How long ago or how far ahead a register date is, from today in Perth:
     * "3 years 7 months ago", "in 4 years", "5 days ago", "today".
     * Register values are AWST wall-clock times, so both sides are compared in Perth.
     */
    public static function relative(?string $value, ?CarbonImmutable $now = null): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        try {
            $then = CarbonImmutable::parse($value, 'Australia/Perth');
        } catch (Throwable) {
            return '';
        }
        if ($then->year >= 2999) {
            return '';
        }
        $now ??= CarbonImmutable::now('Australia/Perth');
        $past = $then->lessThanOrEqualTo($now);
        [$from, $to] = $past ? [$then, $now] : [$now, $then];
        $months = (int) floor($from->diffInMonths($to));
        if ($months === 0) {
            $days = (int) floor($from->startOfDay()->diffInDays($to->startOfDay()));

            return match (true) {
                $days === 0 => 'today',
                $days === 1 => $past ? 'yesterday' : 'tomorrow',
                default => $past ? "$days days ago" : "in $days days",
            };
        }
        $years = intdiv($months, 12);
        $rest = $months % 12;
        $plural = fn (int $n, string $unit) => $n.' '.$unit.($n === 1 ? '' : 's');
        $span = $years > 0
            ? $plural($years, 'year').($rest > 0 ? ' '.$plural($rest, 'month') : '')
            : $plural($rest, 'month');

        return $past ? "$span ago" : "in $span";
    }

    /** The two-digit mineral field number in a tenement ID ("E 24/250" is 24), or null. */
    public static function fieldNumber(?string $fmtTenid): ?string
    {
        return preg_match('~^\s*[A-Z]{1,2}\s*(\d{2})\s*/~i', (string) $fmtTenid, $m) ? $m[1] : null;
    }

    /** Where a tenement is, from its ID: the mineral district if named, else the field ("Kanowna", "Broad Arrow"). */
    public static function field(?string $fmtTenid): ?string
    {
        $n = self::fieldNumber($fmtTenid);
        $row = $n === null ? null : config('mineral_fields.'.$n);

        return $row ? ($row['district'] ?? $row['field']) : null;
    }

    /** A register value with its time, or the date alone when the register holds no time (midnight). */
    public static function when(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        try {
            $d = CarbonImmutable::parse($value);
        } catch (Throwable) {
            return $value;
        }

        return self::localDate($value, $d->format('H:i:s') !== '00:00:00');
    }
}
