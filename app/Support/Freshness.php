<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * How current the register copy is, for the header chip and /data. One
 * extract time across the site: the latest good run's live-layer extract.
 */
final class Freshness
{
    /** An extract older than this reads as stale even if the last run was not (weekends are 2 days). */
    public const STALE_AFTER_HOURS = 72;

    private function __construct(
        public readonly CarbonImmutable $extract,
        public readonly bool $stale,
        public readonly ?CarbonImmutable $lastCheck,
        private readonly CarbonImmutable $now,
    ) {}

    /** Null before the first good run. */
    public static function current(?CarbonImmutable $now = null): ?self
    {
        $run = Register::latestRun();
        if (! $run?->tenements_extract) {
            return null;
        }
        $now = ($now ?? CarbonImmutable::now())->setTimezone('Australia/Perth');
        $extract = CarbonImmutable::parse($run->tenements_extract)->setTimezone('Australia/Perth');
        $check = Register::lastCheck();

        return new self(
            $extract,
            // Age alone decides: a run that found the same extract is normal on a weekend.
            Register::isStale($run, $now),
            $check ? CarbonImmutable::parse($check)->setTimezone('Australia/Perth') : null,
            $now,
        );
    }

    /** "Register 22 Nov, 4:11am" when fresh, "Register 19 Nov · 4 days old" when stale. */
    public function label(): string
    {
        if (! $this->stale) {
            return 'Register '.$this->extract->format('j M, g:ia');
        }
        $days = (int) $this->extract->startOfDay()->diffInDays($this->now->startOfDay());
        $age = match ($days) {
            0 => 'from today',
            1 => '1 day old',
            default => "$days days old",
        };

        return 'Register '.$this->extract->format('j M').' · '.$age;
    }
}
