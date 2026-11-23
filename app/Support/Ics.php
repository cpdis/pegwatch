<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/** iCalendar files for events people must not miss, such as a release's ballot window. */
class Ics
{
    /**
     * One event as a complete .ics file. Times are written in UTC, so calendar
     * apps show them in the reader's own zone.
     */
    public static function event(string $uid, CarbonImmutable $start, int $minutes, string $summary, string $description, string $url): string
    {
        $utc = fn (CarbonImmutable $t) => $t->utc()->format('Ymd\THis\Z');
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Pegwatch//Pegwatch//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:'.self::text($uid).'@pegwatch',
            'DTSTAMP:'.$utc(CarbonImmutable::now()),
            'DTSTART:'.$utc($start),
            'DTEND:'.$utc($start->addMinutes($minutes)),
            'SUMMARY:'.self::text($summary),
            'DESCRIPTION:'.self::text($description),
            'URL:'.$url,
            'END:VEVENT',
            'END:VCALENDAR',
        ];

        return implode("\r\n", array_map(self::fold(...), $lines))."\r\n";
    }

    /** A compulsory release: the 15 minutes after release time, when applications go to ballot. */
    public static function release(string $fmtTenid, string $rlesid, string $releaseAtAwst, string $url): string
    {
        $start = CarbonImmutable::parse($releaseAtAwst, 'Australia/Perth');

        return self::event(
            'release-'.Slug::make($rlesid),
            $start,
            15,
            "Release of $fmtTenid ($rlesid)",
            "Ground from $fmtTenid is released at ".$start->format('g:ia').' AWST. Applications lodged in the first 15 minutes go to ballot. Confirm in TENGRAPH and with the Mining Registrar.',
            $url,
        );
    }

    /** Escape text values (RFC 5545, 3.3.11). */
    private static function text(string $v): string
    {
        return str_replace(["\\", ';', ',', "\r\n", "\n"], ['\\\\', '\;', '\,', '\n', '\n'], $v);
    }

    /** Fold lines longer than 75 octets (RFC 5545, 3.1). */
    private static function fold(string $line): string
    {
        $out = '';
        while (strlen($line) > 75) {
            $cut = 75;
            while ($cut > 0 && (ord($line[$cut]) & 0xC0) === 0x80) {
                $cut--;
            }
            $out .= substr($line, 0, $cut)."\r\n ";
            $line = substr($line, $cut);
        }

        return $out.$line;
    }
}
