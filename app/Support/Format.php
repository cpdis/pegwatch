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

    // g-visual: share previews and the data status page.

    /** The department's list of regional mining registrar offices (checked 23 Nov 2026). */
    public const MINING_REGISTRAR_URL = 'https://www.wa.gov.au/organisation/department-of-local-government-industry-regulation-and-safety/regional-offices-lgirs-and-dmpe';

    /** Death reasons that read as a verb: "withdrawn on 16 Nov 2026". */
    private const DEATH_VERBS = ['Surrendered', 'Expired', 'Withdrawn', 'Forfeited', 'Cancelled', 'Refused', 'Lapsed', 'Terminated'];

    /**
     * A tenement in plain sentences (build spec 3's headline), for share
     * previews. Takes a row from Register::tenementBrief(). Holder names
     * stay as registered.
     */
    public static function headline(object $t, ?CarbonImmutable $now = null): string
    {
        $type = self::sentence($t->type);
        $names = PgArray::parse($t->holders);
        $holders = match (count($names)) {
            0 => '',
            1 => $names[0],
            2 => $names[0].' and '.$names[1],
            default => $names[0].' and '.(count($names) - 1).' others',
        };
        $by = $holders === '' ? '' : " by $holders";
        $applied = self::localDate($t->applied_at);
        $ends = self::localDate($t->ends_at);

        if ($t->status === 'DEAD') {
            $how = in_array($t->death_reason, self::DEATH_VERBS, true) ? strtolower($t->death_reason) : 'recorded dead';
            $span = self::relative($t->applied_at, $t->ends_at ? CarbonImmutable::parse($t->ends_at, 'Australia/Perth') : null);
            $after = in_array($span, ['', 'today'], true) ? '' : ', after '.str_replace(' ago', '', $span);

            return "$type applied for$by".($applied ? " on $applied" : '')." and $how".($ends ? " on $ends" : '')."$after.";
        }

        $area = match (true) {
            $t->legal_area === null => $t->area_ha ? number_format((float) $t->area_ha).' ha' : '',
            str_starts_with(strtoupper((string) $t->area_unit), 'BL') && $t->area_ha => self::legalArea((float) $t->legal_area, $t->area_unit).', '.number_format((float) $t->area_ha).' ha',
            default => self::legalArea((float) $t->legal_area, $t->area_unit),
        };

        if ($t->status === 'PENDING') {
            $row = config('mineral_fields.'.self::fieldNumber($t->fmt_tenid));
            $place = match (true) {
                ! $row => '',
                $row['district'] !== null => ' in the '.$row['district'].' district',
                default => ' in the '.$row['field'].' field',
            };
            $survey = $t->survstatus ? ', '.strtolower($t->survstatus) : '';

            return trim('Pending '.strtolower($type)." application$by".($applied ? ', lodged '.$applied.' ('.self::relative($t->applied_at, $now).')' : '').'.'
                .($area !== '' ? ' '.ucfirst("$area$place$survey").'.' : ''));
        }

        $since = self::localDate($t->granted_at ?: $t->applied_at);
        $expiry = match (true) {
            $ends === '' => '',
            CarbonImmutable::parse($t->ends_at, 'Australia/Perth')->lessThan($now ?? CarbonImmutable::now('Australia/Perth')) => " The expiry date passed on $ends, but the register still lists it as live.",
            default => " Expires $ends (".self::relative($t->ends_at, $now).').',
        };

        return "$type held$by".($since ? " since $since" : '').".$expiry".($area !== '' ? " $area." : '');
    }
}
