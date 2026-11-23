<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Shapes a page of feed items for reading: a run of applications by the same
 * holders folds into one row, and rows group under their day.
 */
class FeedGroups
{
    /** The fewest applications that fold into one row. */
    public const BULK_MIN = 3;

    /** Days allowed between one application in a run and the next. */
    private const BULK_GAP_DAYS = 2;

    /** Count nouns for day headings, [one, many]. */
    private const NOUNS = [
        'application' => ['application', 'applications'],
        'appeared' => ['tenement appeared', 'tenements appeared'],
        'reappeared' => ['back on the register', 'back on the register'],
        'death' => ['death', 'deaths'],
        'grant' => ['grant', 'grants'],
        'status_change' => ['status change', 'status changes'],
        'holder_change' => ['holder change', 'holder changes'],
        'shrink' => ['area cut', 'area cuts'],
        'grow' => ['area added', 'areas added'],
        'release_scheduled' => ['release scheduled', 'releases scheduled'],
        'released' => ['release', 'releases'],
        'release_cancelled' => ['release cancelled', 'releases cancelled'],
    ];

    /**
     * Fold bulk lodgements: applications with the same holders and tenement
     * type, each within two days of the next, BULK_MIN or more of them. The
     * folded row takes the place of the newest one.
     */
    public static function collapse(array $items): array
    {
        $runs = [];
        foreach ($items as $i => $item) {
            if ($item['type'] !== 'application' || ! $item['holders']) {
                continue;
            }
            $names = array_column($item['holders'], 'name');
            sort($names);
            $key = $item['type_code'].'|'.implode('|', $names);
            $day = substr((string) $item['at'], 0, 10);
            $last = $runs[$key] ?? null;
            $lastRun = $last ? $last[count($last) - 1] : null;
            $gap = $lastRun ? CarbonImmutable::parse(end($lastRun)['day'])->diffInDays(CarbonImmutable::parse($day), true) : null;
            if ($gap !== null && $gap <= self::BULK_GAP_DAYS) {
                $runs[$key][count($last) - 1][] = ['i' => $i, 'day' => $day];
            } else {
                $runs[$key][] = [['i' => $i, 'day' => $day]];
            }
        }

        $replace = [];
        $drop = [];
        foreach ($runs as $keyRuns) {
            foreach ($keyRuns as $run) {
                if (count($run) < self::BULK_MIN) {
                    continue;
                }
                $members = array_map(fn ($m) => $items[$m['i']], $run);
                $replace[$run[0]['i']] = self::bulk($members);
                foreach (array_slice($run, 1) as $m) {
                    $drop[$m['i']] = true;
                }
            }
        }

        $out = [];
        foreach ($items as $i => $item) {
            if (! isset($drop[$i])) {
                $out[] = $replace[$i] ?? $item;
            }
        }

        return $out;
    }

    /** Entries under their AWST day, newest day first: [['day' => 'Y-m-d', 'entries' => [...]]]. */
    public static function byDay(array $entries): array
    {
        $days = [];
        foreach ($entries as $e) {
            $days[substr((string) $e['at'], 0, 10)][] = $e;
        }

        return array_map(fn ($day, $list) => ['day' => $day, 'entries' => $list], array_keys($days), $days);
    }

    /** "12 applications · 3 grants · 1 death", biggest first. */
    public static function countLine(array $byType): string
    {
        arsort($byType);
        $parts = [];
        foreach ($byType as $type => $n) {
            [$one, $many] = self::NOUNS[$type] ?? [$type, $type];
            $parts[] = number_format($n).' '.($n === 1 ? $one : $many);
        }

        return implode(' · ', $parts);
    }

    /** A day heading: "Thursday 19 November", with the year when it is not this year. */
    public static function dayLabel(string $day): string
    {
        $d = CarbonImmutable::parse($day, 'Australia/Perth');
        $today = CarbonImmutable::now('Australia/Perth');

        return match (true) {
            $d->isSameDay($today) => __('Today'),
            $d->isSameDay($today->subDay()) => __('Yesterday'),
            default => $d->format($d->year === $today->year ? 'l j F' : 'l j F Y'),
        };
    }

    /** The span of a run: "3 Oct", "3 and 4 Oct", "between 1 and 4 Oct", "30 Sep and 1 Oct". */
    public static function span(array $days): string
    {
        $days = array_values(array_unique($days));
        sort($days);
        $first = CarbonImmutable::parse($days[0]);
        $last = CarbonImmutable::parse($days[count($days) - 1]);
        if (count($days) === 1) {
            return 'on '.$first->format('j M');
        }
        $from = $first->month === $last->month ? $first->format('j') : $first->format('j M');
        $joiner = count($days) === 2 ? 'on %s and %s' : 'between %s and %s';

        return sprintf($joiner, $from, $last->format('j M'));
    }

    private static function bulk(array $members): array
    {
        $first = $members[0];
        $plural = strtolower(Format::TYPE_NAMES[$first['type_code']] ?? 'tenement').'s';
        $ha = array_sum(array_map(fn ($m) => (float) ($m['area_ha'] ?? 0), $members));
        $holder = $first['holders'][0]['name'].(count($first['holders']) > 1 ? ' and others' : '');

        return [
            'key' => 'bulk:'.$first['key'],
            'bulk' => true,
            'type' => 'application',
            'at' => $first['at'],
            'holder' => $first['holders'][0],
            'members' => $members,
            'sentence' => sprintf('%s applied for %d %s %s%s', $holder, count($members), $plural,
                self::span(array_map(fn ($m) => substr((string) $m['at'], 0, 10), $members)),
                $ha > 0 ? ', '.number_format($ha).' ha' : ''),
        ];
    }
}
