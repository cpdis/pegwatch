<?php

namespace Tests\Feature;

use App\Support\Register;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\TestCase;

class HolderPageTest extends TestCase
{
    use RefreshDatabase;

    /** A square tenement about 1 km across near Kalgoorlie. */
    private static function square(float $x, float $y = -30.80): string
    {
        return sprintf('SRID=4326;MULTIPOLYGON(((%1$F %2$F,%3$F %2$F,%3$F %4$F,%1$F %4$F,%1$F %2$F)))', $x, $y, $x + 0.01, $y + 0.01);
    }

    /**
     * ACME GOLD PTY LTD holds four tenements: one live past its expiry, one
     * live expiring in two months, one live for years, one pending. SMITH,
     * JOHN (a person) holds one pending tenement.
     */
    private static function seedRegister(): void
    {
        $rows = [
            ['E  1500001', 'E 15/1', 'EXPLORATION LICENCE', 'E', 'LIVE', 'ACME GOLD PTY LTD', 't', 1000, "now() - interval '5 years'", "now() - interval '3 months'"],
            ['E  1500002', 'E 15/2', 'EXPLORATION LICENCE', 'E', 'LIVE', 'ACME GOLD PTY LTD', 't', 500, "now() - interval '4 years'", "now() + interval '2 months'"],
            ['M  1500003', 'M 15/3', 'MINING LEASE', 'M', 'LIVE', 'ACME GOLD PTY LTD', 't', 9.5, "now() - interval '3 years'", "now() + interval '15 years'"],
            ['P  1500004', 'P 15/4', 'PROSPECTING LICENCE', 'P', 'PENDING', 'ACME GOLD PTY LTD', 't', 120, "now() - interval '2 months'", "'2999-12-31'"],
            ['P  1500005', 'P 15/5', 'PROSPECTING LICENCE', 'P', 'PENDING', 'SMITH, JOHN', 'f', 100, "now() - interval '1 month'", "'2999-12-31'"],
        ];
        foreach ($rows as $i => [$tenid, $fmt, $type, $code, $status, $holder, $co, $ha, $applied, $ends]) {
            DB::insert(
                "insert into tenements (tenid, fmt_tenid, type, type_code, status, holders, holder_is_company, area_ha, applied_at, ends_at, extract_at, geom)
                 values (?, ?, ?, ?, ?, array[?], array[?::boolean], ?, $applied, $ends, now(), ?::geometry)",
                [$tenid, $fmt, $type, $code, $status, $holder, $co, $ha, self::square(121.5 + $i * 0.02)]
            );
        }
    }

    /** Dead tenements for a holder, one per day back from yesterday. */
    private static function seedDead(string $holder, int $n, bool $company = true): void
    {
        DB::insert(
            "insert into dead_tenements (tenid, ended_at, fmt_tenid, type, death_reason, holders, holders_are_company, started_at, geom)
             select 'D'||g, now() - (g || ' days')::interval, 'E 16/'||g, 'EXPLORATION LICENCE', 'Withdrawn',
                    array[?], array[?::boolean], now() - ((g + 30) || ' days')::interval, ?::geometry
             from generate_series(1, ?) g",
            [$holder, $company ? 't' : 'f', self::square(122.0), $n]
        );
    }

    public function test_person_pages_are_noindex_and_company_pages_are_not(): void
    {
        self::seedRegister();

        $person = $this->get('/holders/smith-john')->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('<meta name="robots" content="noindex, nofollow">', false)
            ->assertSee('Shown by name only');
        // A person never gets the CSV export, not even behind the Pro lock.
        $person->assertDontSee('ref=export', false);

        $company = $this->get('/holders/acme-gold-pty-ltd')->assertOk()
            ->assertHeaderMissing('X-Robots-Tag')
            ->assertDontSee('name="robots"', false)
            ->assertSee('/pro?ref=export', false);
    }

    public function test_the_dead_count_is_the_true_count_beyond_the_list(): void
    {
        self::seedRegister();
        self::seedDead('ACME GOLD PTY LTD', 230);

        $this->assertSame(230, Register::holder('acme-gold-pty-ltd')['summary']['dead']);
        $this->get('/holders/acme-gold-pty-ltd')->assertOk()
            ->assertSee('230 dead tenements on record.')
            ->assertSee('Dead tenements on record (230)')
            ->assertSee('The latest 200 shown.');
    }

    public function test_the_map_gets_the_holders_tenements_as_a_feature_collection(): void
    {
        self::seedRegister();

        $h = Register::holder('acme-gold-pty-ltd');
        $this->assertSame('FeatureCollection', $h['focus']['type']);
        $this->assertSame(['E 15/1', 'E 15/2', 'M 15/3', 'P 15/4'], collect($h['focus']['features'])->pluck('properties.fmt_tenid')->sort()->values()->all());
        $feature = collect($h['focus']['features'])->firstWhere('properties.fmt_tenid', 'P 15/4');
        $this->assertSame('Feature', $feature['type']);
        $this->assertContains($feature['geometry']->type, ['Polygon', 'MultiPolygon']);
        $this->assertSame(['PENDING', 'P'], [$feature['properties']['status'], $feature['properties']['type_code']]);
        $this->assertEqualsWithDelta([121.5, -30.80, 121.57, -30.79], $h['bbox'], 1e-6);

        $html = $this->get('/holders/acme-gold-pty-ltd')->assertOk()->getContent();
        $this->assertStringContainsString('focus', $html);
        $this->assertStringNotContainsString('SMITH', $html);
    }

    public function test_a_holder_with_nothing_live_maps_their_dead_tenements_and_shows_no_empty_table(): void
    {
        self::seedDead('OLDCO NL', 3);

        $h = Register::holder('oldco-nl');
        $this->assertSame(['DEAD', 'DEAD', 'DEAD'], collect($h['focus']['features'])->pluck('properties.status')->all());
        $this->assertTrue($h['focus_dead']);
        $this->assertNotNull($h['bbox']);

        $this->get('/holders/oldco-nl')->assertOk()
            ->assertSee('the map shows their latest 3 dead tenements')
            ->assertSee('Nothing live or pending on the register.')
            ->assertDontSee('Live and pending tenements')
            ->assertDontSee('data-phone-rows', false);
    }

    public function test_the_table_lists_past_expiry_then_expiring_then_the_rest_with_phone_rows(): void
    {
        self::seedRegister();

        $this->get('/holders/acme-gold-pty-ltd')->assertOk()
            ->assertSeeInOrder(['Live and pending tenements (4)',
                'Past expiry, still live', 'E 15/1', 'Expiring in 12 months', 'E 15/2', 'All other tenements', 'P 15/4', 'M 15/3',
                'data-phone-rows', 'Past expiry, still live', 'E 15/1', 'Expiring in 12 months', 'E 15/2'], false)
            ->assertSee('still live');
    }

    public function test_the_table_filters_and_sorts_without_losing_the_groups(): void
    {
        self::seedRegister();

        $ids = fn ($c) => collect($c->instance()->table['groups'])->flatMap(fn ($g) => array_column($g['rows'], 'fmt_tenid'))->all();
        $c = Livewire::test('pages::holders.show', ['slug' => 'acme-gold-pty-ltd']);
        $this->assertSame(['E 15/1', 'E 15/2', 'P 15/4', 'M 15/3'], $ids($c));

        $c->set('status', 'PENDING');
        $this->assertSame(['P 15/4'], $ids($c));

        $c->set('status', '')->set('type', 'E');
        $this->assertSame(['E 15/1', 'E 15/2'], $ids($c));

        $c->set('type', '')->call('sortBy', 'area');
        $this->assertSame(['E 15/1', 'E 15/2', 'M 15/3', 'P 15/4'], $ids($c));
        $c->call('sortBy', 'area');
        $this->assertSame(['E 15/1', 'E 15/2', 'P 15/4', 'M 15/3'], $ids($c));
        $this->assertSame('desc', $c->get('dir'));
    }

    public function test_the_summary_counts_types_expiries_and_recent_applications(): void
    {
        self::seedRegister();
        self::seedDead('ACME GOLD PTY LTD', 2);

        $h = Register::holder('acme-gold-pty-ltd');
        $this->assertSame(['live' => 3, 'pending' => 1, 'area_ha' => 1630.0, 'expiring_90d' => 1, 'dead' => 2, 'applied_12m' => 3], $h['summary']);
        $this->assertSame([['E', 2, 0, 1500.0], ['M', 1, 0, 9.5], ['P', 0, 1, 120.0]],
            array_map(fn ($t) => [$t['type_code'], $t['live'], $t['pending'], $t['area_ha']], $h['by_type']));

        // In 24 months: P 15/4 and the two dead ones were applied for, and those two died.
        $this->assertCount(24, $h['activity_by_month']);
        $this->assertSame(now('Australia/Perth')->format('Y-m'), end($h['activity_by_month'])['month']);
        $this->assertSame(3, array_sum(array_column($h['activity_by_month'], 'applications')));
        $this->assertSame(2, array_sum(array_column($h['activity_by_month'], 'deaths')));

        $this->get('/holders/acme-gold-pty-ltd')->assertOk()
            ->assertSee('3 live, 1 pending, 1,630 ha mapped.')
            ->assertSee('3 applications in the last 12 months.')
            ->assertSeeInOrder(['Exploration licence', 'Mining lease', 'Prospecting licence', 'Expiring in 90 days'])
            ->assertSee('Applications and deaths per month');
    }

    public function test_watch_this_holder_appears_once_the_watch_flow_exists(): void
    {
        self::seedRegister();
        $this->get('/holders/acme-gold-pty-ltd')->assertDontSee('Watch this holder');

        Route::get('watch', fn () => 'ok')->name('watch.start');
        app('router')->getRoutes()->refreshNameLookups();

        $this->get('/holders/acme-gold-pty-ltd')->assertSee('Watch this holder')
            ->assertSee(route('watch.start', ['holder' => 'acme-gold-pty-ltd']), false);
    }

    public function test_unknown_and_malformed_slugs_are_not_found(): void
    {
        self::seedRegister();
        foreach (['/holders/nobody', '/holders/ACME-GOLD-PTY-LTD', '/holders/acme%25', '/holders/acme_gold'] as $url) {
            $this->get($url)->assertNotFound();
        }
    }
}
