<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WatchArea;
use App\Support\Format;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
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

    public function test_the_header_shows_a_grey_chip_with_the_extract_time_when_the_register_is_fresh(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-23 06:00:00', 'UTC'));
        self::seedRegister('2026-11-22 20:11:00+00');

        $html = preg_replace('/\s+/', ' ', $this->get('/activity')->assertOk()->getContent());

        $chip = $this->between($html, 'data-test="freshness-chip"', '</button>');
        $this->assertStringContainsString('Register 23 Nov, 4:11am', $chip);
        $this->assertStringContainsString('bg-zinc-400', $chip);
        $this->assertStringContainsString('The department publishes a new extract most business days. Pegwatch checks every night. Last check: 23 Nov, 6:00am AWST.', $html);
        $this->assertStringContainsString('href="'.route('data').'"', $html);
    }

    public function test_the_chip_turns_amber_with_the_age_when_the_run_is_stale_or_the_extract_is_over_72_hours_old(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-23 06:00:00', 'UTC'));
        // Stale by the run's flag alone: the extract is only 34 hours old.
        $run = self::seedRegister('2026-11-21 20:11:00+00', stale: true);

        $chip = $this->between($this->get('/')->assertOk()->getContent(), 'data-test="freshness-chip"', '</button>');
        $this->assertStringContainsString('Register 22 Nov · 1 day old', $chip);
        $this->assertStringContainsString('bg-amber-500', $chip);

        // Not stale by the run's flag, but the extract is four days old.
        DB::table('ingest_runs')->where('id', $run)->update(['stale' => false, 'tenements_extract' => '2026-11-18 20:11:00+00']);
        $chip = $this->between($this->get('/')->getContent(), 'data-test="freshness-chip"', '</button>');
        $this->assertStringContainsString('Register 19 Nov · 4 days old', $chip);

        // The phone menu carries the same words.
        $this->get('/')->assertSee('data-test="freshness-inline"', false);
    }

    public function test_no_chip_before_the_first_run(): void
    {
        $this->get('/activity')->assertOk()->assertDontSee('freshness-chip');
    }

    public function test_the_footer_is_one_legal_line_with_tengraph_and_data_links(): void
    {
        self::seedRegister();
        $footer = $this->between($this->get('/activity')->getContent(), 'data-test="site-footer"', '</footer>');
        $text = preg_replace('/\s+/', ' ', strip_tags($footer));

        $this->assertStringContainsString('A screening aid, not advice. Confirm in TENGRAPH and with the Mining Registrar. Data © State of WA (DMPE), CC BY 4.0, changes made. Data and disclaimer', $text);
        $this->assertStringContainsString('href="'.Format::TENGRAPH_URL.'"', $footer);
        $this->assertStringContainsString('href="'.route('data').'"', $footer);
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

    public function test_a_tenement_page_carries_its_headline_and_image_as_share_tags(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-23 06:00:00', 'UTC'));
        $run = self::seedRegister();
        $headline = 'Pending mining lease application by YANDAL RESOURCES LIMITED, lodged 21 Apr 2023 (3 years 7 months ago). 198 ha in the Kanowna district, unsurveyed.';

        $this->get('/tenements/m-27-518')->assertOk()
            ->assertSee('<meta name="description" content="'.$headline.'" />', false)
            ->assertSee('<meta property="og:description" content="'.$headline.'" />', false)
            ->assertSee('<meta property="og:title" content="M 27/518 · Mining lease · Pending" />', false)
            ->assertSee('<link rel="canonical" href="'.route('tenements.show', 'm-27-518').'" />', false)
            ->assertSee('<meta property="og:image" content="'.route('og.tenement', ['slug' => 'm-27-518', 'v' => $run]).'" />', false)
            ->assertSee('<meta name="twitter:card" content="summary_large_image" />', false);
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

    public function test_a_missing_tenement_gets_a_pegwatch_404_that_names_the_id(): void
    {
        self::seedRegister();

        $this->get('/tenements/e-99-999')->assertNotFound()
            ->assertSee('<title>Not found · Pegwatch</title>', false)
            ->assertSee('data-flux-brand', false)
            ->assertSeeInOrder(['No tenement', 'E 99/999', 'on the register or in the dead history.'])
            ->assertSee('href="'.Format::TENGRAPH_URL.'"', false)
            ->assertSee('Search tenements and holders');

        $this->get('/no-such-page')->assertNotFound()
            ->assertSee('Nothing at this address.')
            ->assertSee('data-test="site-footer"', false);
    }

    public function test_the_tenement_share_image_is_a_1200_by_630_png(): void
    {
        self::seedRegister();

        $res = $this->get('/og/tenements/m-27-518.png')->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeaderMissing('Set-Cookie');
        [$w, $h] = getimagesizefromstring($res->getContent());
        $this->assertSame([1200, 630], [$w, $h]);

        $this->get('/og/tenements/e-99-999.png')->assertNotFound();
    }

    public function test_the_watch_area_image_needs_a_signed_url(): void
    {
        $run = self::seedRegister();
        DB::insert(
            "insert into tenement_events (run_id, event_type, tenid, fmt_tenid, geom) values (?, 'death', 'P  1500009', 'P 15/9', ?::geometry)",
            [$run, self::square(121.51, -30.79)]
        );
        $area = User::factory()->create()->watchAreas()->create([
            'name' => 'Kalgoorlie east', 'latitude' => -30.79, 'longitude' => 121.51, 'radius_km' => 5, 'event_types' => WatchArea::FREE_EVENT_TYPES,
        ]);

        $this->get("/og/areas/{$area->id}.png")->assertForbidden();

        $res = $this->get(URL::signedRoute('og.area', ['watchArea' => $area->id, 'run' => $run]))->assertOk()
            ->assertHeader('Content-Type', 'image/png');
        $this->assertSame([1200, 630], array_slice(getimagesizefromstring($res->getContent()), 0, 2));
    }

    private function between(string $html, string $from, string $to): string
    {
        $start = strpos($html, $from);
        $this->assertNotFalse($start, "Missing $from");

        return substr($html, $start, strpos($html, $to, $start) - $start);
    }
}
