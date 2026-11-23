<?php

namespace Tests\Feature;

use App\Alerts\AlertMatcher;
use App\Mail\WatchAreaDigest;
use App\Models\User;
use App\Models\WatchArea;
use App\Support\Format;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class PegwatchTest extends TestCase
{
    use RefreshDatabase;

    /** A square tenement about 1 km across near Kalgoorlie. */
    private static function square(float $x, float $y, float $w = 0.01): string
    {
        return sprintf('SRID=4326;MULTIPOLYGON(((%1$F %2$F,%3$F %2$F,%3$F %4$F,%1$F %4$F,%1$F %2$F)))', $x, $y, $x + $w, $y + $w);
    }

    private static function seedRegister(): int
    {
        $runId = DB::table('ingest_runs')->insertGetId([
            'status' => 'ok', 'finished_at' => now(), 'tenements_extract' => now()->subHours(15), 'stale' => false,
        ]);
        DB::insert(
            "insert into tenements (tenid, fmt_tenid, type, type_code, status, holders, holder_is_company, area_ha, applied_at, ends_at, extract_at, geom)
             values ('E  1500001', 'E 15/1', 'EXPLORATION LICENCE', 'E', 'LIVE', '{\"ACME GOLD PTY LTD\"}', '{t}', 100, '2025-01-02 09:00', '2030-01-01', now(), ?::geometry),
                    ('P  1500002', 'P 15/2', 'PROSPECTING LICENCE', 'P', 'PENDING', '{\"SMITH, JOHN\"}', '{f}', 100, '2026-11-18 08:30', '2999-12-31', now(), ?::geometry)",
            [self::square(121.50, -30.80), self::square(121.505, -30.80)]
        );
        DB::insert(
            "insert into tenement_events (run_id, event_type, tenid, fmt_tenid, tenement_type, holders, details, geom)
             values (?, 'death', 'P  1500009', 'P 15/9', 'PROSPECTING LICENCE', '{\"JONES, MARY\"}',
                     '{\"kind\": \"ended\", \"death_reason\": \"Surrendered\", \"uncovered_share\": 0.97}', ?::geometry),
                    (?, 'application', 'P  1500002', 'P 15/2', 'PROSPECTING LICENCE', '{\"SMITH, JOHN\"}', '{\"area_ha\": 100}', ?::geometry)",
            [$runId, self::square(121.52, -30.80), $runId, self::square(121.505, -30.80)]
        );

        return $runId;
    }

    public function test_map_page_renders_totals_and_recent_events(): void
    {
        self::seedRegister();

        $this->get('/')->assertOk()
            ->assertSee('1 live and 1 pending')
            ->assertSee('P 15/9')
            ->assertSee('Surrendered. 97% of its area has no other tenement over it.');
    }

    public function test_tenement_page_shows_holders_and_overlaps(): void
    {
        self::seedRegister();

        $this->get('/tenements/e-15-1')->assertOk()
            ->assertSeeInOrder(['E 15/1', 'ACME GOLD PTY LTD', 'Overlapping tenements', '(1)', 'P 15/2']);
        $this->get('/tenements/nope-1')->assertNotFound();

        $this->get('/tenements/e-99-999')->assertNotFound();
    }

    public function test_holder_page_lists_the_portfolio(): void
    {
        self::seedRegister();

        $this->get('/holders/smith-john')->assertOk()
            ->assertSee('SMITH, JOHN')
            ->assertSee('0 live, 1 pending')
            ->assertSee('Shown by name only')
            ->assertSee('P 15/2');
    }

    public function test_search_finds_tenements_by_compact_id_and_holders_by_name(): void
    {
        self::seedRegister();

        Livewire::test('search')->set('q', 'E15/1')->assertSee('E 15/1')
            ->set('q', 'acme')->assertSee('ACME GOLD PTY LTD')
            ->set('q', 'E15/1')->call('go')->assertRedirect('/tenements/e-15-1')
            ->call('open', 'holder', 'acme-gold-pty-ltd')->assertRedirect('/holders/acme-gold-pty-ltd')
            ->assertSet('q', '');

        // One palette per page, opened from the header or with Cmd/Ctrl+K.
        $html = $this->get('/activity')->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, 'data-flux-command>'));
        $this->assertStringContainsString('keydown.cmd.k.document', $html);
    }

    public function test_vector_tiles_are_served_from_postgis(): void
    {
        self::seedRegister();

        // z12 tile containing 121.5E, 30.8S
        $n = 2 ** 12;
        $x = (int) floor((121.505 + 180) / 360 * $n);
        $lat = deg2rad(-30.795);
        $y = (int) floor((1 - log(tan($lat) + 1 / cos($lat)) / M_PI) / 2 * $n);

        $res = $this->get("/tiles/tenements/12/$x/$y.mvt")->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.mapbox-vector-tile');
        $this->assertGreaterThan(50, strlen($res->getContent()));

        $this->get('/tiles/nope/1/0/0.mvt')->assertNotFound();
    }

    public function test_tiles_carry_no_cookies_and_are_gzipped_for_clients_that_accept_it(): void
    {
        self::seedRegister();
        $n = 2 ** 12;
        $x = (int) floor((121.505 + 180) / 360 * $n);
        $lat = deg2rad(-30.795);
        $y = (int) floor((1 - log(tan($lat) + 1 / cos($lat)) / M_PI) / 2 * $n);

        $plain = $this->get("/tiles/tenements/12/$x/$y.mvt", ['Accept-Encoding' => 'identity'])->assertOk()
            ->assertHeaderMissing('Set-Cookie')->assertHeaderMissing('Content-Encoding');
        $gzipped = $this->get("/tiles/tenements/12/$x/$y.mvt", ['Accept-Encoding' => 'gzip, br'])->assertOk()
            ->assertHeaderMissing('Set-Cookie')->assertHeader('Content-Encoding', 'gzip');
        $this->assertSame($plain->getContent(), gzdecode($gzipped->getContent()));

        // An empty tile has no body to compress.
        $this->get('/tiles/tenements/12/0/0.mvt', ['Accept-Encoding' => 'gzip'])->assertOk()
            ->assertHeaderMissing('Content-Encoding')->assertContent('');
    }

    public function test_ingest_hook_prunes_expired_rows_from_the_database_cache(): void
    {
        config(['cache.default' => 'database', 'services.ingest.token' => 'secret']);
        DB::table('cache')->insert([
            ['key' => 'tile:gz:1:tenements:5:26:18', 'value' => 'old', 'expiration' => now()->subHour()->getTimestamp()],
            ['key' => 'tile:gz:2:tenements:5:26:18', 'value' => 'new', 'expiration' => now()->addHour()->getTimestamp()],
        ]);

        $this->withToken('secret')->postJson('/hooks/ingest-complete')->assertOk();

        $this->assertSame(['tile:gz:2:tenements:5:26:18'], DB::table('cache')->pluck('key')->all());
    }

    public function test_event_lists_do_not_repeat_holders_or_lead_with_a_separator(): void
    {
        $runId = self::seedRegister();
        DB::insert("insert into tenement_events (run_id, event_type, tenid, fmt_tenid, holders, details, geom)
            values (?, 'holder_change', 'E  1500001', 'E 15/1', '{\"ACME GOLD PTY LTD\"}', '{\"added\": [\"ACME GOLD PTY LTD\"], \"removed\": [\"OLD CO PTY LTD\"]}', ?::geometry),
                   (?, 'appeared', 'E  1500001', 'E 15/1', '{\"ACME GOLD PTY LTD\"}', '{}', ?::geometry)",
            [$runId, self::square(121.50, -30.80), $runId, self::square(121.50, -30.80)]);

        $text = preg_replace('/\s+/', ' ', strip_tags($this->get('/activity')->assertOk()->getContent()));
        $this->assertStringContainsString('Added: ACME GOLD PTY LTD; removed: OLD CO PTY LTD', $text);
        $this->assertStringNotContainsString('· ACME GOLD PTY LTD', $text);
    }

    public function test_register_units_and_counts_read_as_words(): void
    {
        $this->assertSame('14 blocks', Format::legalArea(14, 'BL.'));
        $this->assertSame('1 block', Format::legalArea(1, 'BL.'));
        $this->assertSame('9.715 ha', Format::legalArea(9.715, 'HA.'));

        self::seedRegister();
        $this->get('/holders/smith-john')->assertOk()->assertSee('No dead tenements on record.');
    }

    public function test_free_plan_allows_one_watch_area(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->get('/watch-areas')->assertOk()->assertSee('My watch area');
        $area = ['name' => 'Kal east', 'latitude' => -30.8, 'longitude' => 121.5, 'radius_km' => 5, 'event_types' => ['death']];

        $page = fn (array $data) => Livewire::test('pages::watch-areas')->set($data)->call('save');
        $page($area)->assertHasNoErrors();
        $page($area)->assertHasErrors('name');
        $page([...$area, 'event_types' => ['application']])->assertHasErrors('event_types.0');

        $this->assertSame(1, $user->watchAreas()->count());
        $km2 = DB::scalar('select ST_Area(area::geography) / 1e6 from watch_areas');
        $this->assertGreaterThan(77, $km2);
        $this->assertLessThan(80, $km2);
    }

    public function test_alerts_match_events_in_the_watch_area_and_send_one_digest(): void
    {
        Mail::fake();
        $runId = self::seedRegister();
        $user = User::factory()->create();
        $user->watchAreas()->create(['name' => 'Kal east', 'latitude' => -30.795, 'longitude' => 121.525, 'radius_km' => 2, 'event_types' => ['death', 'release_scheduled']]);
        $far = User::factory()->create();
        $far->watchAreas()->create(['name' => 'Pilbara', 'latitude' => -21.0, 'longitude' => 119.0, 'radius_km' => 10, 'event_types' => WatchArea::FREE_EVENT_TYPES]);

        $result = app(AlertMatcher::class)->run($runId);

        $this->assertSame(['matched' => 1, 'emails' => 1], $result);
        Mail::assertSent(WatchAreaDigest::class, fn ($m) => $m->hasTo($user->email) && count($m->events) === 1
            && $m->events[0]['fmt_tenid'] === 'P 15/9');

        // Running again sends nothing new.
        $this->assertSame(['matched' => 0, 'emails' => 0], app(AlertMatcher::class)->run($runId));
    }

    public function test_ingest_hook_is_exempt_from_request_forgery_checks(): void
    {
        // PHPUnit skips CSRF, so check the exemption itself. It must name
        // PreventRequestForgery: excluding the ValidateCsrfToken subclass still
        // let the cached routes in production answer the ingest's curl with 419.
        $route = app('router')->getRoutes()->getByName('hooks.ingest');
        $this->assertContains(PreventRequestForgery::class, $route->excludedMiddleware());
    }

    public function test_ingest_hook_requires_the_token(): void
    {
        $runId = self::seedRegister();
        config(['services.ingest.token' => 'secret']);

        $this->postJson('/hooks/ingest-complete')->assertUnauthorized();
        $this->withToken('secret')->postJson('/hooks/ingest-complete', ['run_id' => $runId])
            ->assertOk()->assertJsonPath('run_id', $runId);
    }

    public function test_free_plan_rejects_event_types_sent_as_an_object(): void
    {
        Mail::fake();
        $runId = self::seedRegister();
        $user = User::factory()->create();
        $this->actingAs($user);
        Livewire::test('pages::watch-areas')->set([
            'name' => 'x', 'latitude' => -30.8, 'longitude' => 121.5, 'radius_km' => 5,
            'event_types' => ['application' => 'death'],
        ])->call('save')->assertHasErrors('event_types');

        // A row stored as an object (keys are not elements) still matches nothing.
        DB::insert("insert into watch_areas (user_id, name, latitude, longitude, radius_km, event_types, created_at, updated_at)
            values (?, 'x', -30.8, 121.5, 5, '{\"application\": \"death\"}', now(), now())", [$user->id]);
        $this->assertSame(['matched' => 0, 'emails' => 0], app(AlertMatcher::class)->run($runId));
    }

    public function test_release_summaries_are_in_awst(): void
    {
        $runId = self::seedRegister();
        DB::insert("insert into tenement_events (run_id, event_type, tenid, fmt_tenid, details, occurred_at, geom)
            values (?, 'release_scheduled', 'E  1500001', 'E 15/1',
                    jsonb_build_object('release_id', 'R1', 'release_at', timestamptz '2026-12-01 08:30+08'), '2026-12-01 08:30', ?::geometry)",
            [$runId, self::square(121.50, -30.80)]);

        $this->get('/activity?type=release_scheduled')->assertOk()->assertSee('R1 at 1 Dec 2026 08:30 AWST');
    }

    public function test_malformed_query_input_is_ignored_not_a_server_error(): void
    {
        self::seedRegister();
        foreach (['/activity?type[]=x', '/activity?type=nope', '/activity?page=99999999999999999999', '/activity?page=abc'] as $url) {
            $this->get($url)->assertOk();
        }
        $this->get('/tiles/tenements/99999999999999999999/0/0.mvt')->assertNotFound();
    }

    public function test_pages_render_tenements_with_no_type(): void
    {
        self::seedRegister();
        DB::update("update tenements set type = null, status = null where tenid = 'P  1500002'");
        $this->get('/holders/smith-john')->assertOk()->assertSee('P 15/2');
        // No type: the name comes from the type code.
        $this->get('/tenements/e-15-1')->assertOk()->assertSeeInOrder(['P 15/2', 'Prospecting licence']);
    }

    public function test_hook_without_a_run_id_alerts_on_every_recent_run(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        $user->watchAreas()->create(['name' => 'Kal east', 'latitude' => -30.795, 'longitude' => 121.525, 'radius_km' => 2, 'event_types' => ['death']]);
        DB::update("update watch_areas set created_at = now() - interval '2 days'");
        $first = self::seedRegister();   // has a death in the area; its hook call was missed
        DB::table('ingest_runs')->insertGetId(['status' => 'ok', 'finished_at' => now()]);  // next night: nothing
        DB::insert("insert into tenement_events (run_id, event_type, tenid, fmt_tenid, details, created_at, geom)
            values (?, 'death', 'P  1500077', 'P 15/77', '{}', now() - interval '3 days', ?::geometry)",
            [$first, self::square(121.52, -30.80)]);  // older than the watch area: not alerted
        config(['services.ingest.token' => 'secret']);

        $this->withToken('secret')->postJson('/hooks/ingest-complete')->assertOk()->assertJsonPath('emails', 1);
        Mail::assertSent(WatchAreaDigest::class, fn ($m) => array_column($m->events, 'fmt_tenid') === ['P 15/9']);
    }
}
