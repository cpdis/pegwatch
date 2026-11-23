<?php

namespace Tests\Feature;

use App\Support\Format;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The frame (brand, freshness chip, footer), /data, share tags and images, and the 404. */
class GlobalPagesTest extends TestCase
{
    use RefreshDatabase;

    private static function square(float $x, float $y, float $w = 0.01): string
    {
        return sprintf('SRID=4326;MULTIPOLYGON(((%1$F %2$F,%3$F %2$F,%3$F %4$F,%1$F %4$F,%1$F %2$F)))', $x, $y, $x + $w, $y + $w);
    }

    /** A run whose live extract is $extract (UTC), plus two tenements: a company's and a person's. */
    private static function seedRegister(string $extract = '2026-11-22 20:11:00+00', bool $stale = false): int
    {
        $run = DB::table('ingest_runs')->insertGetId([
            'status' => 'ok', 'started_at' => '2026-11-22 21:55:00+00', 'finished_at' => '2026-11-22 22:00:00+00',
            'tenements_extract' => $extract, 'stale' => $stale,
        ]);
        DB::insert(
            "insert into tenements (tenid, fmt_tenid, type, type_code, status, survstatus, holders, holder_is_company, legal_area, area_unit, area_ha, applied_at, ends_at, extract_at, geom)
             values ('M  2700518', 'M 27/518', 'MINING LEASE', 'M', 'PENDING', 'UNSURVEYED', '{\"YANDAL RESOURCES LIMITED\"}', '{t}', 198, 'HA.', 197.4, '2023-04-21 08:24', null, now(), ?::geometry),
                    ('P  1500002', 'P 15/2', 'PROSPECTING LICENCE', 'P', 'PENDING', 'UNSURVEYED', '{\"SMITH, JOHN\"}', '{f}', 100, 'HA.', 100, '2026-11-18 08:30', null, now(), ?::geometry)",
            [self::square(121.50, -30.80), self::square(121.52, -30.80)]
        );

        return $run;
    }

    public function test_the_brand_says_pegwatch_whatever_app_name_says(): void
    {
        config(['app.name' => 'pegwatch']);

        $this->get('/activity')->assertOk()
            ->assertSee('<title>Activity · Pegwatch</title>', false)
            ->assertSee('<meta property="og:site_name" content="Pegwatch" />', false)
            ->assertSeeInOrder(['data-flux-brand', 'Pegwatch</span>'], false);
    }

    public function test_the_data_page_lists_the_last_30_checks_with_layers_and_changes(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-23 06:00:00', 'UTC'));
        for ($i = 31; $i >= 1; $i--) {
            DB::table('ingest_runs')->insert([
                'status' => 'ok', 'started_at' => "2026-10-$i 21:00:00+00", 'finished_at' => "2026-10-$i 21:05:00+00",
                'tenements_extract' => '2026-10-01 04:00:00+00', 'stale' => true,
            ]);
        }
        $run = self::seedRegister('2026-11-22 20:11:00+00');
        DB::table('layer_extracts')->insert([
            ['run_id' => $run, 'layer_code' => 'DMIRS-003', 'layer_key' => 'tenements', 'fetched_rows' => 30610, 'max_extract' => '2026-11-22 20:11:00+00'],
            ['run_id' => $run, 'layer_code' => 'DMIRS-030', 'layer_key' => 'release_pending', 'fetched_rows' => 11, 'max_extract' => null],
        ]);
        DB::insert("insert into tenement_events (run_id, event_type, tenid, fmt_tenid) values (?, 'application', 'P  1500002', 'P 15/2'), (?, 'grant', 'M  2700518', 'M 27/518')", [$run, $run]);

        $html = $this->get('/data')->assertOk()
            ->assertSee('The last 30 nightly checks')
            ->assertSeeInOrder(['Mon 23 Nov,', '6:00am', '23 Nov, 4:11am', '2', 'Checked'])
            ->assertSee('Live and pending tenements')
            ->assertSee('30,610 rows')
            ->assertSee('Release pending (s65 surrender)')
            ->assertSee('Same extract')
            ->assertSee('Pegging on the ground.')
            ->assertSee('Private land.')
            ->assertSee('Same-day lodgements.')
            ->assertSee('href="'.Format::TENGRAPH_URL.'"', false)
            ->assertSee('href="'.Format::MINING_REGISTRAR_URL.'"', false)
            ->assertSee('CC BY 4.0')
            ->getContent();

        $this->assertSame(30, substr_count($html, 'data-test="run-row"'));
    }

    public function test_a_natural_person_holder_page_has_no_share_image_and_a_company_page_does(): void
    {
        self::seedRegister();

        $this->get('/holders/smith-john')->assertOk()
            ->assertSee('<meta property="og:description" content="SMITH, JOHN: 0 live and 1 pending tenement on the WA register, 100 ha mapped." />', false)
            ->assertDontSee('og:image', false)
            ->assertDontSee('twitter:image', false)
            ->assertSee('<meta name="twitter:card" content="summary" />', false);

        $this->get('/holders/yandal-resources-limited')->assertOk()
            ->assertSee('<meta property="og:image" content="'.asset('og.png').'" />', false);
    }

    public function test_a_digest_issue_describes_its_week(): void
    {
        DB::table('digest_issues')->insert([
            'week' => '2026-W47', 'period_start' => '2026-11-15 16:00:00+00', 'period_end' => '2026-11-22 16:00:00+00',
            'content' => json_encode([
                'period_start' => '2026-11-15T16:00:00+00:00', 'period_end' => '2026-11-22T16:00:00+00:00',
                'counts' => ['application' => 12, 'death' => 1, 'release_scheduled' => 0],
                'freed' => [], 'applications' => [], 'applicants' => [], 'releases' => [],
            ]),
        ]);

        $this->get('/digest/2026-W47')->assertOk()->assertSee(
            '<meta name="description" content="The WA mining register, 16 Nov 2026 to 23 Nov 2026: 12 new applications, 1 tenement dead, no releases scheduled." />',
            false
        );
    }
}
