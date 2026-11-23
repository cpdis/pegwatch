<?php

namespace Tests\Unit;

use App\Support\Format;
use App\Support\Ics;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FormatTest extends TestCase
{
    public static function relativeDates(): array
    {
        return [
            ['2023-04-21 08:24:00', '3 years 7 months ago'],
            ['2030-11-05 00:00:00', 'in 3 years 11 months'],
            ['2026-11-23 08:00:00', 'today'],
            ['2026-11-22 08:00:00', 'yesterday'],
            ['2026-11-18 08:00:00', '5 days ago'],
            ['2026-12-02 08:30:00', 'in 8 days'],
            ['2025-11-23 08:00:00', '1 year ago'],
            ['2026-10-01 00:00:00', '1 month ago'],
            ['2999-12-31 00:00:00', ''],
        ];
    }

    #[DataProvider('relativeDates')]
    public function test_it_says_how_long_ago_or_ahead_a_register_date_is(string $value, string $expected): void
    {
        $now = CarbonImmutable::parse('2026-11-23 14:00', 'Australia/Perth');

        $this->assertSame($expected, Format::relative($value, $now));
    }

    public static function tenementIds(): array
    {
        return [
            ['E 24/250', 'Broad Arrow'],
            ['M 27/518', 'Kanowna'],
            ['P 53/1765', 'Wiluna'],
            ['E 80/5411', 'Kimberley'],
            ['M 261SA', null],
            ['', null],
        ];
    }

    #[DataProvider('tenementIds')]
    public function test_it_names_the_mineral_district_or_field_from_the_id(string $id, ?string $expected): void
    {
        $this->assertSame($expected, Format::field($id));
    }

    public function test_it_puts_register_words_in_sentence_case(): void
    {
        $this->assertSame('Mining lease', Format::sentence('MINING LEASE'));
        $this->assertSame('Unsurveyed', Format::sentence('UNSURVEYED'));
        $this->assertSame('', Format::sentence(null));
    }

    public function test_it_prints_a_time_only_when_the_register_holds_one(): void
    {
        $this->assertSame('21 Apr 2023, 8:24am', Format::when('2023-04-21 08:24:00'));
        $this->assertSame('21 Apr 2023', Format::when('2023-04-21 00:00:00'));
    }

    public function test_it_maps_any_status_to_a_palette_key(): void
    {
        $this->assertSame('live', Format::statusKey('LIVE'));
        $this->assertSame('release', Format::statusKey('release'));
        $this->assertSame('dead', Format::statusKey('UNKNOWN'));
    }

    public function test_it_writes_a_release_ballot_window_as_a_utc_calendar_event(): void
    {
        $ics = Ics::release('E 28/2780', 'RN26/140', '2026-12-03 08:30', 'https://example.test/tenements/e-28-2780');

        $this->assertStringContainsString("BEGIN:VEVENT\r\n", $ics);
        $this->assertStringContainsString('DTSTART:20261203T003000Z', $ics);
        $this->assertStringContainsString('DTEND:20261203T004500Z', $ics);
        $this->assertStringContainsString('SUMMARY:Release of E 28/2780 (RN26/140)', $ics);
        foreach (explode("\r\n", $ics) as $line) {
            $this->assertLessThanOrEqual(75, strlen($line));
        }
    }
}
