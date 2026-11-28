<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Throwable;

/**
 * The words at the top of a tenement page (Build spec 3), from Register::tenement():
 * its state, eyebrow, headline, area, place and lifecycle. headlineText() is the plain
 * sentence, for share previews.
 */
class TenementSummary
{
    /** Land restriction kinds (land_restrictions.kind) as the page names them. */
    public const RESTRICTION_KINDS = [
        's19' => 'Section 19 land',
        's57_4' => 'Section 57(4) land',
        's57_2aa' => 'Section 57(2aa) land',
        'file_notation' => 'File notation area',
        'clearance' => 'Clearance area',
    ];

    /** pending, live, expired (live but past its expiry date) or dead. */
    public static function state(array $t, ?CarbonImmutable $now = null): string
    {
        return match (true) {
            $t['dead'] !== null => 'dead',
            $t['status'] === 'PENDING' => 'pending',
            self::isPast($t['ends_at'], $now) => 'expired',
            default => 'live',
        };
    }

    public static function typeName(array $t): string
    {
        return Format::sentence($t['type']) ?: (Format::TYPE_NAMES[$t['type_code'] ?? ''] ?? 'Tenement');
    }

    /** "Mining lease · Pending application", small caps over the ID. */
    public static function eyebrow(array $data): string
    {
        $t = $data['tenement'];
        $state = match (self::state($t)) {
            'pending' => 'Pending application',
            'live' => 'Live',
            'expired' => 'Live, past expiry',
            'dead' => 'Dead'.($t['dead']['reason'] ? ' ('.strtolower($t['dead']['reason']).')' : ''),
        };

        return self::typeName($t).' · '.$state.($data['releases'] ? ' · Release due' : '');
    }

    /**
     * What it is, who holds it, how old it is and how big, in one to three sentences.
     * Parts are strings or holders (arrays from Holders::present), so the page can link
     * each holder; names stay exactly as registered.
     *
     * @return list<string|array{name: string, slug: string, is_company: bool}>
     */
    public static function headline(array $data, ?CarbonImmutable $now = null): array
    {
        $t = $data['tenement'];
        $type = self::typeName($t);
        $holders = self::holderParts($t['holders']);
        $date = fn (?string $v) => Format::localDate($v);
        $where = self::fieldPhrase($t['fmt_tenid']);
        $size = trim(self::areaHeadline($t).($where ? ' in '.$where : ''));
        $sentence = fn (string $s) => $s === '' ? '' : ' '.ucfirst($s).'.';

        switch (self::state($t, $now)) {
            case 'pending':
                $lodged = $t['applied_at'] ? ', lodged '.$date($t['applied_at']).' ('.Format::relative($t['applied_at'], $now).')' : '';
                $survey = $t['survey_status'] ? strtolower($t['survey_status']) : '';

                return ['Pending '.lcfirst($type).' application by ', ...$holders,
                    $lodged.'.'.$sentence(implode(', ', array_filter([$size, $survey])))];

            case 'dead':
                $granted = $t['granted_at'] ? ', granted on '.$date($t['granted_at']).',' : '';
                $ended = self::deathVerb($t['dead']['reason']).' on '.$date($t['dead']['ended_at']);
                $span = $t['applied_at'] ? self::span($t['applied_at'], $t['dead']['ended_at']) : null;
                $after = match ($span) {
                    null => '',
                    '0 days' => ', the same day',
                    default => ', after '.$span,
                };

                return [$type.' applied for by ', ...$holders,
                    ($t['applied_at'] ? ' on '.$date($t['applied_at']) : '').$granted.' and '.$ended.$after.'.'.$sentence($size)];

            default:
                // "Since" the grant only while no transfer has been seen; after one, since the transfer.
                $changed = $t['holder_changed_at'] ?? null;
                $since = match (true) {
                    $changed !== null => ' since a change of holder seen '.Format::awst($changed, false),
                    (bool) $t['granted_at'] => ' since '.$date($t['granted_at']),
                    default => '',
                };
                if ($changed !== null && $t['granted_at']) {
                    $type .= ' granted '.$date($t['granted_at']).',';
                }
                $expiry = match (true) {
                    $t['ends_at'] === null => '',
                    self::isPast($t['ends_at'], $now) => ' The expiry date passed on '.$date($t['ends_at']).', but the register still lists it as live.',
                    default => ' Expires '.$date($t['ends_at']).' ('.Format::relative($t['ends_at'], $now).').',
                };

                return [$type.' held by ', ...$holders, $since.'.'.$expiry.$sentence($size)];
        }
    }

    public static function headlineText(array $data): string
    {
        return implode('', array_map(fn ($p) => is_array($p) ? $p['name'] : $p, self::headline($data)));
    }

    /** Area for the key facts: "4 blocks (1,184 ha mapped)"; mapped hectares only for blocks or a gap over 2%. */
    public static function areaFact(array $t): ?string
    {
        $mapped = $t['area_ha'] !== null ? self::hectares($t['area_ha']).' mapped' : null;
        if ($t['legal_area'] === null) {
            return $mapped;
        }
        $legal = Format::legalArea($t['legal_area'], $t['area_unit']);
        $unit = strtoupper(rtrim((string) $t['area_unit'], '.'));
        $differs = $unit === 'HA' && $t['area_ha'] !== null && abs($t['area_ha'] - $t['legal_area']) > 0.02 * $t['legal_area'];

        return $mapped && ($unit === 'BL' || $differs) ? "$legal ($mapped)" : $legal;
    }

    /** Area for the headline: "31 blocks, 9,072 ha", "198 ha", or the mapped hectares when the register gives none. */
    public static function areaHeadline(array $t): string
    {
        if ($t['legal_area'] === null) {
            return $t['area_ha'] !== null ? self::hectares($t['area_ha']) : '';
        }
        $legal = Format::legalArea($t['legal_area'], $t['area_unit']);
        $blocks = strtoupper(rtrim((string) $t['area_unit'], '.')) === 'BL';

        return $blocks && $t['area_ha'] !== null ? $legal.', '.self::hectares($t['area_ha']) : $legal;
    }

    public static function hectares(float $ha): string
    {
        return ($ha < 10 ? number_format($ha, 1) : number_format($ha)).' ha';
    }

    /** "Kanowna (27)": the mineral district or field and its number. */
    public static function fieldLabel(string $fmtTenid): ?string
    {
        $name = Format::field($fmtTenid);

        return $name ? $name.' ('.Format::fieldNumber($fmtTenid).')' : null;
    }

    /** "the Kanowna district", or "the Broad Arrow field" where the field has no districts. */
    public static function fieldPhrase(string $fmtTenid): ?string
    {
        $n = Format::fieldNumber($fmtTenid);
        $row = $n === null ? null : config('mineral_fields.'.$n);

        return match (true) {
            ! $row => null,
            (bool) $row['district'] => 'the '.$row['district'].' district',
            default => 'the '.$row['field'].' field',
        };
    }

    /** "12 km north-east of Kalgoorlie" from the nearest place, else "Kanowna district, North East Coolgardie mineral field". */
    public static function locationLine(array $data): ?string
    {
        $p = $data['location'];
        if ($p) {
            if ($p['km'] < 1 || $p['bearing'] === null) {
                return 'Within 1 km of '.$p['place'];
            }
            $km = $p['km'] < 10 ? number_format($p['km'], 1) : number_format($p['km']);
            $compass = ['north', 'north-east', 'east', 'south-east', 'south', 'south-west', 'west', 'north-west'];

            return $km.' km '.$compass[(int) round($p['bearing'] / 45) % 8].' of '.$p['place'];
        }
        $n = Format::fieldNumber($data['tenement']['fmt_tenid']);
        $row = $n === null ? null : config('mineral_fields.'.$n);

        return match (true) {
            ! $row => null,
            (bool) $row['district'] => $row['district'].' district, '.$row['field'].' mineral field',
            default => $row['field'].' mineral field',
        };
    }

    /**
     * A restriction's name and purpose without repeating its kind or its name, cut to 160
     * characters: "Ngarluma area", "Extraction of road making materials".
     */
    public static function restrictionText(array $r): string
    {
        $name = Format::sentence($r['name']);
        $purpose = Format::sentence(preg_replace('/^(file notation area|section \S+)\s*-\s*/i', '', trim((string) $r['purpose'])));
        if ($name !== '' && str_starts_with(strtolower($purpose), strtolower($name))) {
            $purpose = ucfirst(ltrim(substr($purpose, strlen($name)), ' -.,'));
        }

        // Cut at a word, not mid-word.
        return Str::limit(implode(' · ', array_filter([$name, $purpose])), 160, preserveWords: true);
    }

    /** How a death reads in a sentence: "withdrawn", "declared null and void". */
    public static function deathVerb(?string $reason): string
    {
        return match ($reason) {
            null, '', 'Unknown' => 'ended',
            'Null and Void' => 'declared null and void',
            'Invalid' => 'recorded as invalid',
            'Number Not Used' => 'recorded as a number not used',
            default => strtolower($reason),
        };
    }

    /** The time between two register dates: "17 days", "3 years 2 months". Order does not matter. */
    public static function span(string $a, string $b): string
    {
        [$from, $to] = [CarbonImmutable::parse($a), CarbonImmutable::parse($b)];
        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }
        $plural = fn (int $n, string $unit) => $n.' '.$unit.($n === 1 ? '' : 's');
        $months = (int) floor($from->diffInMonths($to));
        if ($months === 0) {
            return $plural((int) floor($from->startOfDay()->diffInDays($to->startOfDay())), 'day');
        }
        $years = intdiv($months, 12);
        $rest = $months % 12;

        return $years ? $plural($years, 'year').($rest ? ' '.$plural($rest, 'month') : '') : $plural($rest, 'month');
    }

    /** "28 months after application" (whole months, or days under a month). */
    public static function afterApplication(?string $applied, ?string $then): string
    {
        if (! $applied || ! $then) {
            return '';
        }
        $months = (int) floor(CarbonImmutable::parse($applied)->diffInMonths(CarbonImmutable::parse($then)));

        return ($months > 0 ? $months.' '.($months === 1 ? 'month' : 'months') : self::span($applied, $then)).' after application';
    }

    /** Where another tenement's lodgement sits against this one: "3 years 6 months before this one". */
    public static function lodgedRelative(?string $other, ?string $mine): string
    {
        if (! $other || ! $mine) {
            return '';
        }
        $a = CarbonImmutable::parse($other);
        $b = CarbonImmutable::parse($mine);
        if ($a->isSameDay($b)) {
            return 'the same day as this one';
        }

        return self::span($other, $mine).($a->lessThan($b) ? ' before' : ' after').' this one';
    }

    /** An expiry within the next 6 months (shown amber). */
    public static function expiresSoon(array $t, ?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now('Australia/Perth');

        return $t['dead'] === null && $t['ends_at'] !== null && ! self::isPast($t['ends_at'], $now)
            && CarbonImmutable::parse($t['ends_at'], 'Australia/Perth')->lessThan($now->addMonths(6));
    }

    /**
     * Applied, granted, the changes Pegwatch saw, releases, expiry or end, and today, in
     * date order. Each item is past, today or future, so the page can mark what is to come.
     *
     * @return list<array{date: string, label: string, detail: string, when: string}>
     */
    public static function lifecycle(array $data, ?CarbonImmutable $now = null): array
    {
        $t = $data['tenement'];
        $now ??= CarbonImmutable::now('Australia/Perth');
        $items = [];
        $add = function (?string $date, string $label, string $detail = '') use (&$items) {
            if ($date) {
                $items[] = ['date' => $date, 'label' => $label, 'detail' => $detail];
            }
        };

        $add($t['applied_at'], 'Applied', Format::when($t['applied_at']));
        $add($t['granted_at'], 'Granted', implode(' · ', array_filter([
            Format::localDate($t['granted_at']), self::afterApplication($t['applied_at'], $t['granted_at']),
        ])));
        foreach ($data['events'] as $e) {
            // Applications and grants are already on the line from the register's own dates.
            if (in_array($e['type'], ['application', 'grant', 'appeared'], true)) {
                continue;
            }
            $date = $e['occurred_at'] ?? self::perth($e['detected_at']);
            $add($date, $e['label'], trim(Format::localDate($date).($e['summary'] !== '' ? ' · '.$e['summary'] : '')));
        }
        foreach ($data['releases'] as $r) {
            $add($r['release_local'], 'Part releases ('.$r['rlesid'].')', Format::when($r['release_local']).' AWST');
        }
        if ($t['dead']) {
            $add($t['dead']['ended_at'], 'Ended', Format::localDate($t['dead']['ended_at']).' · '.ucfirst(self::deathVerb($t['dead']['reason'])));
        } elseif ($t['ends_at']) {
            $past = self::isPast($t['ends_at'], $now);
            $add($t['ends_at'], $past ? 'Expiry date passed' : 'Expires', Format::localDate($t['ends_at']).($past ? ' · still live on the register' : ''));
        }

        $today = $now->format('Y-m-d H:i:s');
        $items[] = ['date' => $today, 'label' => 'Today', 'detail' => Format::localDate($today)];
        usort($items, fn ($a, $b) => strcmp($a['date'], $b['date']) ?: ($a['label'] === 'Today') <=> ($b['label'] === 'Today'));

        return array_map(fn ($item) => $item + ['when' => match (true) {
            $item['label'] === 'Today' => 'today',
            $item['date'] > $today => 'future',
            default => 'past',
        }], $items);
    }

    /**
     * Holders as headline parts: "A", "A and B", "A, B and C", "A, B and 3 others".
     *
     * @return list<string|array>
     */
    private static function holderParts(array $holders): array
    {
        $n = count($holders);
        if ($n === 0) {
            return ['an unrecorded holder'];
        }
        $shown = $n > 3 ? array_slice($holders, 0, 2) : $holders;
        $parts = [];
        foreach ($shown as $i => $h) {
            if ($i > 0) {
                $parts[] = $i === count($shown) - 1 && $n <= 3 ? ' and ' : ', ';
            }
            $parts[] = $h;
        }
        if ($n > 3) {
            $parts[] = ' and '.($n - 2).' others';
        }

        return $parts;
    }

    /** True when a register wall-clock date (AWST) is before now. */
    private static function isPast(?string $value, ?CarbonImmutable $now = null): bool
    {
        if ($value === null || $value === '') {
            return false;
        }
        try {
            return CarbonImmutable::parse($value, 'Australia/Perth')->lessThan($now ?? CarbonImmutable::now('Australia/Perth'));
        } catch (Throwable) {
            return false;
        }
    }

    /** A zoned timestamp as an AWST wall-clock string, so it sorts with register dates. */
    private static function perth(?string $ts): ?string
    {
        return $ts ? CarbonImmutable::parse($ts)->setTimezone('Australia/Perth')->format('Y-m-d H:i:s') : null;
    }
}
