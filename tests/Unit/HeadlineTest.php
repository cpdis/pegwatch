<?php

namespace Tests\Unit;

use App\Support\Format;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/** The plain-text tenement headline used for share previews (build spec 3). */
class HeadlineTest extends TestCase
{
    private static function row(array $over): object
    {
        return (object) array_merge([
            'fmt_tenid' => 'M 27/518', 'type' => 'MINING LEASE', 'status' => 'PENDING', 'survstatus' => 'UNSURVEYED',
            'holders' => '{"YANDAL RESOURCES LIMITED"}', 'legal_area' => '198', 'area_unit' => 'HA.', 'area_ha' => 197.4,
            'applied_at' => '2023-04-21 08:24:00', 'granted_at' => null, 'ends_at' => null, 'death_reason' => null,
        ], $over);
    }

    private static function now(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-11-23 14:00', 'Australia/Perth');
    }

    public function test_a_pending_application_names_its_holder_lodgement_area_and_district(): void
    {
        $this->assertSame(
            'Pending mining lease application by YANDAL RESOURCES LIMITED, lodged 21 Apr 2023 (3 years 7 months ago). 198 ha in the Kanowna district, unsurveyed.',
            Format::headline(self::row([]), self::now())
        );
    }

    public function test_a_live_licence_in_blocks_gives_blocks_and_hectares_and_its_expiry(): void
    {
        $t = self::row([
            'fmt_tenid' => 'E 24/250', 'type' => 'EXPLORATION LICENCE', 'status' => 'LIVE',
            'holders' => '{"ACME GOLD PTY LTD","SMITH, JOHN","JONES, MARY"}', 'legal_area' => '31', 'area_unit' => 'BL.', 'area_ha' => 9072.4,
            'applied_at' => '2020-01-10 09:00:00', 'granted_at' => '2020-11-06 00:00:00', 'ends_at' => '2030-11-05 00:00:00',
        ]);

        $this->assertSame(
            'Exploration licence held by ACME GOLD PTY LTD and 2 others since 6 Nov 2020. Expires 5 Nov 2030 (in 3 years 11 months). 31 blocks, 9,072 ha.',
            Format::headline($t, self::now())
        );
    }

    public function test_a_live_tenement_past_its_expiry_says_the_register_still_lists_it_as_live(): void
    {
        $t = self::row(['status' => 'LIVE', 'granted_at' => '2002-04-23 00:00:00', 'ends_at' => '2023-04-22 00:00:00']);

        $this->assertSame(
            'Mining lease held by YANDAL RESOURCES LIMITED since 23 Apr 2002. The expiry date passed on 22 Apr 2023, but the register still lists it as live. 198 ha.',
            Format::headline($t, self::now())
        );
    }

    public function test_a_dead_tenement_says_how_and_when_it_ended_and_after_how_long(): void
    {
        $t = self::row([
            'type' => 'PROSPECTING LICENCE', 'status' => 'DEAD', 'holders' => '{"SMITH, JOHN"}', 'legal_area' => null, 'area_ha' => null,
            'applied_at' => '2026-10-30 08:00:00', 'ends_at' => '2026-11-16 00:00:00', 'death_reason' => 'Withdrawn',
        ]);
        $this->assertSame(
            'Prospecting licence applied for by SMITH, JOHN on 30 Oct 2026 and withdrawn on 16 Nov 2026, after 17 days.',
            Format::headline($t, self::now())
        );

        // A reason that is not a verb reads as "recorded dead".
        $this->assertStringContainsString('and recorded dead on 16 Nov 2026', Format::headline((object) ([...(array) $t, 'death_reason' => 'Null and Void']), self::now()));
    }
}
