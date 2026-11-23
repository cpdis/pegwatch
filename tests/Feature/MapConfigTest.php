<?php

namespace Tests\Feature;

use App\Support\Format;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** The `pegwatch-map` meta tag the map script reads (resources/js/map/util.js `site()`). */
class MapConfigTest extends TestCase
{
    use RefreshDatabase;

    private static function mapConfig(TestResponse $response): array
    {
        preg_match('~<meta name="pegwatch-map" content="([^"]*)"~', $response->getContent(), $m);

        return json_decode(html_entity_decode($m[1] ?? '', ENT_QUOTES), true) ?? [];
    }

    public function test_the_map_page_sends_field_names_status_colours_and_the_tengraph_link(): void
    {
        $config = self::mapConfig($this->get('/')->assertOk());

        // The hover card says "Mining lease · Kanowna" for M 27/518 and "Broad Arrow" for E 24/250.
        $this->assertSame('Kanowna', $config['fields']['27']);
        $this->assertSame('Broad Arrow', $config['fields']['24']);
        $this->assertSame(Format::TENGRAPH_URL, $config['tengraph']);
        $this->assertSame('#c026d3', $config['status']['release']);
    }

    public function test_the_satellite_toggle_is_offered_only_when_a_provider_is_configured(): void
    {
        config(['pegwatch.satellite' => ['url' => null, 'attribution' => null]]);
        $this->assertNull(self::mapConfig($this->get('/'))['satellite']);

        config(['pegwatch.satellite' => ['url' => 'https://tiles.example/{z}/{x}/{y}.jpg', 'attribution' => '© Example imagery']]);
        $this->assertSame(
            ['url' => 'https://tiles.example/{z}/{x}/{y}.jpg', 'attribution' => '© Example imagery'],
            self::mapConfig($this->get('/'))['satellite'],
        );
    }
}
