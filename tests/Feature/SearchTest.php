<?php

namespace Tests\Feature;

use App\Support\Register;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
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
        return array_map(fn ($r) => $r['fmt_tenid'] ?? $r['name'], $results);
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
