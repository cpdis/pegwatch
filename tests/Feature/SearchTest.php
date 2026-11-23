<?php

namespace Tests\Feature;

use App\Support\Register;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\TestCase;

class SearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('search:s:'.session()->getId());
        DB::insert(
            "insert into tenements (tenid, fmt_tenid, type, type_code, status, holders, holder_is_company, extract_at) values
               ('E  1500001', 'E 15/1', 'EXPLORATION LICENCE', 'E', 'LIVE', '{\"ACME GOLD PTY LTD\"}', '{t}', now()),
               ('E  1500002', 'E 15/2', 'EXPLORATION LICENCE', 'E', 'LIVE', '{\"ACME GOLD PTY. LTD.\"}', '{t}', now()),
               ('P  1500003', 'P 15/3', 'PROSPECTING LICENCE', 'P', 'PENDING', '{\"SMITH, JOHN\",\"E15 RESOURCES LTD\"}', '{f,t}', now())"
        );
        DB::insert(
            "insert into dead_tenements (tenid, ended_at, fmt_tenid, type, death_reason, holders, holders_are_company) values
               ('M  7700077', '2026-10-01', 'M 77/77', 'MINING LEASE', 'Surrendered', '{\"OLDCO NL\"}', '{t}'),
               ('M  7700077', '2020-01-01', 'M 77/77', 'MINING LEASE', 'Forfeited', '{\"OLDCO NL\"}', '{t}'),
               ('E  1500001', '2024-05-01', 'E 15/1', 'EXPLORATION LICENCE', 'Expired', '{\"ACME GOLD PTY LTD\"}', '{t}')"
        );
    }

    private static function labels(array $results): array
    {
        return array_map(fn ($r) => $r['fmt_tenid'] ?? $r['name'] ?? $r['label'], $results);
    }

    public function test_dead_tenements_are_found_once_and_ids_that_are_live_again_are_not_listed_as_dead(): void
    {
        $r = Register::search('m77/77');
        $this->assertSame(['M 77/77'], self::labels($r));
        $this->assertSame(['DEAD', 'Surrendered'], [$r[0]['status'], $r[0]['death_reason']]);

        $this->assertSame(['E 15/1'], self::labels(Register::search('E 15/1')));
        $this->assertSame('LIVE', Register::search('E 15/1')[0]['status']);
        $this->get('/tenements/m-77-77')->assertOk()->assertSee('Surrendered');
    }

    public function test_holder_names_that_share_a_page_are_one_result_found_whatever_the_punctuation(): void
    {
        foreach (['acme', 'Acme Gold Pty. Ltd.', 'ACME GOLD PTY LTD'] as $q) {
            $r = Register::search($q);
            $this->assertCount(1, $r, $q);
            $this->assertSame(['holder', 'acme-gold-pty-ltd', 2], [$r[0]['kind'], $r[0]['slug'], $r[0]['live']], $q);
        }
        $this->assertSame(['SMITH, JOHN'], self::labels(Register::search('smith john')));
        $this->assertSame(['OLDCO NL'], self::labels(Register::search('oldco')));
        $this->assertSame(0, Register::search('oldco')[0]['live']);
        // Two characters: a prefix of a live holder's name.
        $this->assertSame(['SMITH, JOHN'], self::labels(Register::search('sm')));
        $this->assertSame([], Register::search('ld'));
    }

    public function test_an_exact_holder_name_ranks_first_and_enter_opens_the_first_row(): void
    {
        DB::insert(
            "insert into tenements (tenid, fmt_tenid, type, status, holders, holder_is_company, extract_at)
             select 'X'||g, 'E 16/'||g, 'EXPLORATION LICENCE', 'LIVE', '{\"BIG ACME GOLD PTY LTD\"}', '{t}', now()
             from generate_series(1, 5) g"
        );
        $this->assertSame(['ACME GOLD PTY LTD', 'BIG ACME GOLD PTY LTD'], self::labels(Register::search('acme gold pty ltd')));
        $this->assertSame(['BIG ACME GOLD PTY LTD', 'ACME GOLD PTY LTD'], self::labels(Register::search('gold')));

        // A query shaped like a tenement ID lists tenements first; any other
        // lists holders first. Enter follows the list.
        $this->assertSame(['E 15/1', 'E 15/2', 'E15 RESOURCES LTD'], self::labels(Register::search('e15')));
        Livewire::test('search')->set('q', 'e15')->call('go')->assertRedirect('/tenements/e-15-1');
        Livewire::test('search')->set('q', 'e15 res')->call('go')->assertRedirect('/holders/e15-resources-ltd');
        Livewire::test('search')->set('q', 'acme')->call('go')->assertRedirect('/holders/acme-gold-pty-ltd');
        Livewire::test('search')->set('q', 'nothing here')->call('go')->assertNoRedirect();
    }

    public function test_an_id_with_a_letter_suffix_is_found_ahead_of_longer_ids(): void
    {
        // Nine live IDs start with the same digits; the dead P 15/2688-S is the one asked for.
        DB::insert(
            "insert into tenements (tenid, fmt_tenid, type, status, holders, holder_is_company, extract_at)
             select 'P'||g, 'P 15/2688'||g, 'PROSPECTING LICENCE', 'LIVE', '{\"ACME GOLD PTY LTD\"}', '{t}', now()
             from generate_series(1, 9) g"
        );
        DB::insert(
            "insert into dead_tenements (tenid, ended_at, fmt_tenid, type, death_reason, holders, holders_are_company) values
               ('P  152688S', '2026-11-16', 'P 15/2688-S', 'PROSPECTING LICENCE', 'Withdrawn', '{\"SMITH, JOHN\"}', '{f}')"
        );

        $r = Register::search('p 15/2688');
        $this->assertSame(['P 15/2688-S', 'DEAD', 'Withdrawn'], [$r[0]['fmt_tenid'], $r[0]['status'], $r[0]['death_reason']]);
        $this->assertSame('P 15/26881', $r[1]['fmt_tenid']);
    }

    public function test_ids_of_the_typed_shape_list_before_ids_that_only_share_characters(): void
    {
        DB::insert(
            "insert into tenements (tenid, fmt_tenid, type, type_code, status, holders, holder_is_company, area_ha, extract_at) values
               ('M  261SA', 'M 261SA', 'MINING LEASE S.A.', 'AM', 'LIVE', '{\"ACME GOLD PTY LTD\"}', '{t}', 5000, now()),
               ('M  2600150', 'M 26/150', 'MINING LEASE', 'M', 'LIVE', '{\"ACME GOLD PTY LTD\"}', '{t}', 9.44, now())"
        );
        DB::insert(
            "insert into dead_tenements (tenid, ended_at, fmt_tenid, type, death_reason, holders, holders_are_company, geom) values
               ('M  2600012', '2025-01-01', 'M 26/12', 'MINING LEASE', 'Surrendered', '{\"OLDCO NL\"}', '{t}',
                'SRID=4326;MULTIPOLYGON(((121.5 -30.8,121.51 -30.8,121.51 -30.79,121.5 -30.79,121.5 -30.8)))'::geometry)"
        );

        // Live before dead within a rank; the slash puts M 261SA last.
        $r = Register::search('m 26/1');
        $this->assertSame(['M 26/150', 'M 26/12', 'M 261SA'], self::labels($r));
        // Each row carries what the palette shows: type, status, area and field.
        $this->assertSame(['MINING LEASE', 'LIVE', 9.44, 'East Coolgardie'], [$r[0]['type'], $r[0]['status'], $r[0]['area_ha'], $r[0]['field']]);
        $this->assertEqualsWithDelta(106, $r[1]['area_ha'], 1);

        $html = Livewire::test('search')->set('q', 'm 26/1')->html();
        TestResponse::fromBaseResponse(response($html))
            ->assertSeeInOrder(['M 26/150', 'Live', 'Mining lease', 'East Coolgardie', '9.4 ha', 'M 26/12', 'Dead', 'Surrendered', '106 ha', 'M 261SA']);
    }

    public function test_holder_rows_show_live_and_pending_counts_and_whether_a_person(): void
    {
        $r = Register::search('smith');
        $this->assertSame(['SMITH, JOHN', 0, 1, false], [$r[0]['name'], $r[0]['live'], $r[0]['pending'], $r[0]['is_company']]);
        $this->assertSame([2, 0, true], [Register::search('acme')[0]['live'], Register::search('acme')[0]['pending'], Register::search('acme')[0]['is_company']]);

        Livewire::test('search')->set('q', 'acme')->assertSee('2 live · 0 pending')
            ->set('q', 'oldco')->assertSee('Dead only');
    }

    public function test_companies_sharing_a_name_are_one_group_row_that_opens_the_name_match(): void
    {
        DB::insert(
            "insert into tenements (tenid, fmt_tenid, type, status, holders, holder_is_company, extract_at) values
               ('E  1500009', 'E 15/9', 'EXPLORATION LICENCE', 'LIVE', '{\"ACME GOLD (WEST) PTY LTD\",\"ACME GOLD PTY LTD\"}', '{t,t}', now())"
        );

        $r = Register::search('acme');
        $this->assertSame(['group', 'acme', 'ACME', 2, 3], [$r[0]['kind'], $r[0]['slug'], $r[0]['label'], $r[0]['entities'], $r[0]['tenements']]);
        $this->assertSame(['ACME GOLD PTY LTD', 'ACME GOLD (WEST) PTY LTD'], self::labels(array_slice($r, 1)));
        // An exact holder name still comes first.
        $this->assertSame(['holder', 'group'], array_column(array_slice(Register::search('acme gold pty ltd'), 0, 2), 'kind'));

        Livewire::test('search')->set('q', 'acme')->assertSee('Entities matching “ACME”')->assertSee('Name match')
            ->call('go')->assertRedirect('/holders/matching/acme');
    }

    public function test_places_lead_for_a_town_name_and_fly_the_map_there(): void
    {
        // Before h-data ships the places table, search works without it.
        $this->assertSame([], array_filter(Register::search('leonora'), fn ($r) => $r['kind'] === 'place'));

        DB::statement('create table if not exists places (id serial primary key, name text, kind text, lga text, geom geometry(Point, 4326))');
        DB::insert(
            "insert into places (id, name, kind, lga, geom) values
               (901, 'Leonora', 'town', 'Shire of Leonora', 'SRID=4326;POINT(121.33 -28.884)'),
               (902, 'Leonora North', 'locality', 'Shire of Leonora', 'SRID=4326;POINT(121.35 -28.8)'),
               (903, 'Acme Creek', 'locality', null, 'SRID=4326;POINT(120 -29)')"
        );
        DB::insert(
            "insert into tenements (tenid, fmt_tenid, type, status, holders, holder_is_company, extract_at) values
               ('E  1500010', 'E 15/10', 'EXPLORATION LICENCE', 'LIVE', '{\"LEONORA GOLD PTY LTD\"}', '{t}', now())"
        );

        $this->assertSame(['Leonora', 'Leonora North', 'LEONORA GOLD PTY LTD'], self::labels(Register::search('leonora')));
        $this->assertSame([121.33, -28.884], Register::search('leonora')[0]['center']);
        // Three letters could be a company as easily as a town: holders first.
        $this->assertSame('ACME GOLD PTY LTD', self::labels(Register::search('acm'))[0]);
        $this->assertSame('Acme Creek', self::labels(Register::search('acme c'))[0]);

        Livewire::test('search')->set('q', 'leonora')->assertSee('Leonora')->assertSee('Town · Shire of Leonora')
            ->call('go')->assertNoRedirect()->assertSet('q', '')
            ->assertDispatched('place-chosen', center: [121.33, -28.884], zoom: 12);
    }

    public function test_the_palette_says_when_nothing_matches_and_how_to_move(): void
    {
        Livewire::test('search')->assertSee('Type a tenement ID, part of a holder name, or a town.')->assertSee('M 27/518')
            ->set('q', 'zzqx')->assertSee('Nothing matches “zzqx”.')->assertSee('Northern Star')
            ->assertSee('to move')->assertSee('to open')->assertSee('to close');
    }

    public function test_hostile_input_returns_without_error(): void
    {
        foreach (['%%', '__', '\\\\', 'a\\cme', "'; drop table tenements; --", str_repeat('x', 5000), "ab\0cd", "\xff\xfe", '-/-', '  '] as $q) {
            $this->assertIsArray(Register::search($q));
        }
        // Wildcards are text, not patterns.
        $this->assertSame([], Register::search('%%'));
        $this->assertSame([], Register::search('a_me'));
    }

    public function test_search_is_rate_limited_per_session(): void
    {
        $c = Livewire::test('search');
        for ($i = 0; $i < 60; $i++) {
            $c->set('q', "acme $i");
        }
        $c->set('q', 'acme')->assertSet('limited', true)->assertSee('Too many searches');
        RateLimiter::clear('search:s:'.session()->getId());
        $c->set('q', 'acmeg')->assertSet('limited', false)->assertDontSee('Too many searches');
    }

    public function test_the_palette_opens_without_animation(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));
        $this->assertMatchesRegularExpression("/dialog\\[data-modal='search'\\][^{]*\\{\\s*transition: none;/", $css);
        $this->assertStringContainsString('data-modal="search"', $this->get('/activity')->getContent());
    }
}
