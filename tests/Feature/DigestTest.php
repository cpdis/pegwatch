<?php

namespace Tests\Feature;

use App\Digest\WeeklyDigest;
use App\Mail\DigestConfirmMail;
use App\Mail\WeeklyDigestMail;
use App\Models\DigestSubscriber;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class DigestTest extends TestCase
{
    use RefreshDatabase;

    private static function square(float $x, float $y, float $w = 0.01): string
    {
        return sprintf('SRID=4326;MULTIPOLYGON(((%1$F %2$F,%3$F %2$F,%3$F %4$F,%1$F %4$F,%1$F %2$F)))', $x, $y, $x + $w, $y + $w);
    }

    private static function seedWeek(CarbonImmutable $at): void
    {
        $run = DB::table('ingest_runs')->insertGetId(['status' => 'ok', 'finished_at' => $at, 'tenements_extract' => $at, 'stale' => false]);
        $ev = fn (string $type, string $id, string $holders, string $details, float $x) => DB::insert(
            'insert into tenement_events (run_id, event_type, tenid, fmt_tenid, tenement_type, holders, details, geom, created_at)
             values (?, ?, ?, ?, ?, ?, ?::jsonb, ?::geometry, ?)',
            [$run, $type, $id, $id, 'EXPLORATION LICENCE', $holders, $details, self::square($x, -30.8), $at->subDay()]
        );
        $ev('death', 'E 15/10', '{"ACME GOLD PTY LTD"}', '{"kind":"ended","death_reason":"Surrendered","area_ha":300,"uncovered_share":0.5}', 121.5);
        $ev('death', 'E 15/11', '{"ACME GOLD PTY LTD"}', '{"kind":"ended","death_reason":"Expired","area_ha":1000,"uncovered_share":0.9}', 121.6);
        $ev('death', 'E 15/12', '{"ACME GOLD PTY LTD"}', '{"kind":"converted","converted_to":"M 15/1","area_ha":900,"uncovered_share":1}', 121.7);
        $ev('death', 'E 15/13', '{"ACME GOLD PTY LTD"}', '{"kind":"ended","death_reason":"Expired","area_ha":5,"uncovered_share":0.1}', 121.8);
        $ev('application', 'E 15/20', '{"SMITH, JOHN"}', '{"area_ha":50}', 121.9);
        $ev('application', 'E 15/21', '{"SMITH, JOHN"}', '{"area_ha":5000}', 122.0);
        $ev('application', 'E 15/22', '{"BETA PTY LTD"}', '{"area_ha":10, "reversed_by_run": 9}', 122.1);
        DB::insert(
            "insert into release_pending (tenid, rlesid, fmt_tenid, ten_type, release_at, geom) values
             ('E  1500030', 'R1', 'E 15/30', 'EXPLORATION LICENCE', ?, ?::geometry),
             ('E  1500031', 'R2', 'E 15/31', 'EXPLORATION LICENCE', ?, ?::geometry)",
            [$at->addDays(2), self::square(122.2, -30.8), $at->addDays(20), self::square(122.3, -30.8)]
        );
    }

    public function test_signup_is_double_opt_in_and_silent_about_existing_addresses(): void
    {
        Mail::fake();

        Livewire::test('digest-signup', ['source' => 'map'])
            ->set('email', ' Jane@Example.com ')->call('subscribe')
            ->assertHasNoErrors()->assertSet('done', true)->assertSee('Check your inbox');
        $s = DigestSubscriber::sole();
        $this->assertSame('jane@example.com', $s->email);
        $this->assertSame('map', $s->source);
        $this->assertNull($s->confirmed_at);
        Mail::assertSent(DigestConfirmMail::class, fn ($m) => $m->hasTo('jane@example.com'));

        // Opening the link does not confirm (mail scanners open links); the button does.
        $this->get($s->confirmUrl())->assertOk()->assertSee('Confirm my subscription');
        $this->assertNull($s->fresh()->confirmed_at);
        Livewire::test('pages::digest.confirm', ['token' => $s->token])->call('confirm')->assertSee('subscribed');
        $this->assertTrue($s->fresh()->isActive());

        // Signing up again while active sends nothing more and says the same thing.
        Livewire::test('digest-signup')->set('email', 'jane@example.com')->call('subscribe')->assertSee('Check your inbox');
        Mail::assertSentCount(1);

        $this->get('/digest/confirm/nope')->assertNotFound();
    }

    public function test_signup_rejects_bad_input_bots_and_floods(): void
    {
        Mail::fake();

        Livewire::test('digest-signup')->set('email', 'not an email')->call('subscribe')->assertHasErrors('email');
        Livewire::test('digest-signup')->set('email', 'bot@example.com')->set('website', 'x')->call('subscribe')->assertSet('done', true);
        $this->assertSame(0, DigestSubscriber::count());

        for ($i = 0; $i < 5; $i++) {
            Livewire::test('digest-signup')->set('email', "p$i@example.com")->call('subscribe')->assertHasNoErrors();
        }
        Livewire::test('digest-signup')->set('email', 'p9@example.com')->call('subscribe')->assertHasErrors('email');
        Mail::assertSentCount(5);
    }

    public function test_source_records_the_referrer_tag(): void
    {
        Mail::fake();
        $this->get('/digest?ref=Mining Facebook Group')->assertOk()->assertSee('Past issues');
        Livewire::withQueryParams(['ref' => 'Mining Facebook Group'])
            ->test('digest-signup', ['source' => 'digest-page'])->set('email', 'a@example.com')->call('subscribe');
        $this->assertSame('digest-page:mining-facebook-group', DigestSubscriber::sole()->source);
    }

    public function test_unsubscribe_by_page_and_by_one_click_post(): void
    {
        $a = DigestSubscriber::create(['email' => 'a@example.com']);
        $a->forceFill(['confirmed_at' => now()])->save();
        $b = DigestSubscriber::create(['email' => 'b@example.com']);
        $b->forceFill(['confirmed_at' => now()])->save();

        $this->get($a->unsubscribeUrl())->assertOk()->assertSee('Stop the weekly digest');
        $this->assertTrue($a->fresh()->isActive());
        Livewire::test('pages::digest.unsubscribe', ['token' => $a->token])->call('unsubscribe')->assertSee('unsubscribed');
        $this->assertFalse($a->fresh()->isActive());

        // The mail client's one-click POST carries no session or CSRF token.
        $this->withMiddleware(PreventRequestForgery::class);
        $this->post(route('digest.unsubscribe.one-click', ['token' => $b->token]), ['List-Unsubscribe' => 'One-Click'])->assertNoContent();
        $this->assertFalse($b->fresh()->isActive());
        $this->post(route('digest.unsubscribe.one-click', ['token' => 'nope']))->assertNoContent();
    }

    public function test_issue_content_ranks_open_ground_and_skips_reversed_events(): void
    {
        $now = CarbonImmutable::parse('2026-11-30 19:30', 'Australia/Perth');
        self::seedWeek($now);

        $c = app(WeeklyDigest::class)->content($now->subDays(7), $now);

        $this->assertSame(['E 15/11', 'E 15/10'], array_column($c['freed'], 'fmt_tenid'));
        $this->assertSame(900.0, $c['freed'][0]['uncovered_ha']);
        $this->assertSame('Expired', $c['freed'][0]['reason']);
        $this->assertSame(4, $c['counts']['death']);
        $this->assertSame(2, $c['counts']['application']);
        $this->assertSame(['E 15/21', 'E 15/20'], array_column($c['applications'], 'fmt_tenid'));
        $this->assertSame([['name' => 'SMITH, JOHN', 'slug' => 'smith-john', 'n' => 2]], $c['applicants']);
        // Every upcoming release, not only the next 7 days (G19).
        $this->assertSame(['E 15/30', 'E 15/31'], array_column($c['releases'], 'fmt_tenid'));
        $this->assertFalse($c['stale']);
        $this->assertSame(['kind' => 'application', 'fmt_tenid' => 'E 15/21'],
            ['kind' => WeeklyDigest::lead($c)['kind'], 'fmt_tenid' => WeeklyDigest::lead($c)['item']['fmt_tenid']]);
    }

    public function test_digest_goes_out_once_a_week_to_confirmed_subscribers_only(): void
    {
        Mail::fake();
        $digest = app(WeeklyDigest::class);
        $sunday = CarbonImmutable::parse('2026-11-29 19:30', 'Australia/Perth');
        $monday = CarbonImmutable::parse('2026-11-30 19:30', 'Australia/Perth');
        self::seedWeek($monday);

        $on = DigestSubscriber::create(['email' => 'on@example.com']);
        $on->forceFill(['confirmed_at' => $sunday])->save();
        DigestSubscriber::create(['email' => 'unconfirmed@example.com']);
        $off = DigestSubscriber::create(['email' => 'off@example.com']);
        $off->forceFill(['confirmed_at' => $sunday, 'unsubscribed_at' => $sunday])->save();

        $this->assertSame(['issue' => null, 'built' => false, 'sent' => 0, 'remaining' => 0], $digest->run($sunday));

        $r = $digest->run($monday);
        $this->assertSame(['issue' => '2026-W49', 'built' => true, 'sent' => 1, 'remaining' => 0], $r);
        Mail::assertSent(WeeklyDigestMail::class, 1);
        Mail::assertSent(WeeklyDigestMail::class, function (WeeklyDigestMail $m) use ($on) {
            $headers = $m->headers()->text;

            return $m->hasTo('on@example.com')
                && str_contains($m->envelope()->subject, '2 tenements freed ground')
                && $headers['List-Unsubscribe-Post'] === 'List-Unsubscribe=One-Click'
                && str_contains($headers['List-Unsubscribe'], $on->token);
        });

        // Tuesday's run: this week's issue is already out.
        $this->assertSame(['issue' => null, 'built' => false, 'sent' => 0, 'remaining' => 0], $digest->run($monday->addDay()));
        Mail::assertSent(WeeklyDigestMail::class, 1);

        // The next issue picks up where this one ended.
        $digest->run($monday->addWeek());
        $issues = DB::table('digest_issues')->orderBy('id')->get();
        $this->assertCount(2, $issues);
        $this->assertEquals(CarbonImmutable::parse($issues[0]->period_end), CarbonImmutable::parse($issues[1]->period_start));
    }

    public function test_a_failed_send_is_retried_by_the_next_call(): void
    {
        $monday = CarbonImmutable::parse('2026-11-30 19:30', 'Australia/Perth');
        $s = DigestSubscriber::create(['email' => 'on@example.com']);
        $s->forceFill(['confirmed_at' => $monday->subDay()])->save();

        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('SMTP down'));
        $r = app(WeeklyDigest::class)->run($monday);
        $this->assertSame(['sent' => 0, 'remaining' => 1], array_intersect_key($r, ['sent' => 0, 'remaining' => 0]));
        $this->assertNull(DB::table('digest_issues')->value('finished_at'));

        app()->forgetInstance('mail.manager');
        Mail::clearResolvedInstances();
        Mail::fake();
        $r = app(WeeklyDigest::class)->run($monday->addDay());
        $this->assertSame(1, $r['sent']);
        $this->assertNotNull(DB::table('digest_issues')->value('finished_at'));
    }

    public function test_issue_pages_render_and_the_mail_renders(): void
    {
        $monday = CarbonImmutable::parse('2026-11-30 19:30', 'Australia/Perth');
        self::seedWeek($monday);
        app(WeeklyDigest::class)->build($monday);

        $this->get('/digest')->assertOk()->assertSee('Week to 30 Nov 2026')->assertSee('2 tenements freed ground')
            ->assertDontSee('What an issue looks like');
        // The biggest item leads; hectares sit in their own right-aligned column; holders link.
        $this->get('/digest/2026-W49')->assertOk()
            ->assertSeeInOrder(['The week\'s biggest change', 'E 15/21', '5,000.0 ha', 'Freed ground', 'E 15/11', '900.0 ha', 'E 15/10',
                'Biggest new applications', 'E 15/21', 'SMITH, JOHN', 'Upcoming compulsory releases', 'E 15/30', 'E 15/31'])
            ->assertSee(route('holders.show', ['slug' => 'smith-john']), false)
            ->assertSee('text-end', false);
        $this->get('/digest/2026-W01')->assertNotFound();

        $s = DigestSubscriber::create(['email' => 'on@example.com']);
        $issue = DB::table('digest_issues')->first();
        $html = (new WeeklyDigestMail($issue->week, json_decode($issue->content, true), $s))->render();
        $this->assertStringContainsString('E 15/11', $html);
        $this->assertStringContainsString($s->unsubscribeUrl(), $html);
        // Every heading renders, including those straight after a table.
        $this->assertStringNotContainsString('## ', $html);
        $this->assertStringContainsString('Upcoming compulsory releases', $html);
    }

    public function test_ingest_hook_sends_the_digest_when_due(): void
    {
        Mail::fake();
        config(['services.ingest.token' => 'secret']);
        $this->travelTo(CarbonImmutable::parse('2026-11-30 19:30', 'Australia/Perth'));
        $s = DigestSubscriber::create(['email' => 'on@example.com']);
        $s->forceFill(['confirmed_at' => now()->subDay()])->save();

        $this->postJson('/hooks/ingest-complete', [], ['Authorization' => 'Bearer secret'])
            ->assertOk()->assertJsonPath('digest.issue', '2026-W49')->assertJsonPath('digest.sent', 1);
        Mail::assertSent(WeeklyDigestMail::class, 1);
    }

    public function test_map_shows_the_signup_to_guests(): void
    {
        self::seedWeek(CarbonImmutable::now());
        $this->get('/')->assertOk()->assertSee('Get the week&#039;s changes every Monday', false);
    }

    public function test_digest_page_shows_a_sample_before_the_first_issue(): void
    {
        $now = CarbonImmutable::now('Australia/Perth');
        self::seedWeek($now);
        // Known only from the register's dates: no diff event behind it.
        DB::insert(
            "insert into dead_tenements (tenid, ended_at, fmt_tenid, type, death_reason, geom)
             values ('E  1500040', ?, 'E 15/40', 'EXPLORATION LICENCE', 'Surrendered', ?::geometry)",
            [$now->subDays(2)->format('Y-m-d 00:00:00'), self::square(123.0, -30.8, 0.05)]
        );

        $c = app(WeeklyDigest::class)->sample();
        $this->assertTrue($c['sample']);
        $this->assertSame('E 15/40', $c['freed'][0]['fmt_tenid']);
        $this->assertSame('Surrendered', $c['freed'][0]['reason']);
        $this->assertSame(['E 15/30', 'E 15/31'], array_column($c['releases'], 'fmt_tenid'));

        $this->get('/digest')->assertOk()
            ->assertSeeInOrder(['What an issue looks like', 'Sample', 'Freed ground', 'E 15/40', 'Upcoming compulsory releases', 'E 15/31', 'Past issues', 'None yet']);
        $this->assertSame(0, DB::table('digest_issues')->count());
    }

    public function test_a_stale_week_sends_a_short_notice(): void
    {
        $monday = CarbonImmutable::parse('2026-11-30 19:30', 'Australia/Perth');
        $extract = $monday->subDays(10)->utc();
        DB::table('ingest_runs')->insert(['status' => 'ok', 'finished_at' => $extract, 'tenements_extract' => $extract, 'stale' => false]);
        DB::table('ingest_runs')->insert(['status' => 'ok', 'finished_at' => $monday->subDay()->utc(), 'tenements_extract' => $extract, 'stale' => true]);
        app(WeeklyDigest::class)->build($monday);

        $issue = DB::table('digest_issues')->first();
        $c = json_decode($issue->content, true);
        $this->assertTrue($c['stale']);
        $this->get('/digest/'.$issue->week)->assertOk()->assertSee('No new extract this week')
            ->assertSee('20 Nov 2026')->assertDontSee('Biggest new applications');

        $mail = new WeeklyDigestMail($issue->week, $c, DigestSubscriber::create(['email' => 'on@example.com']));
        $this->assertStringContainsString('no new register extract', $mail->envelope()->subject);
        $html = $mail->render();
        $this->assertStringContainsString('No new extract this week', $html);
        $this->assertStringNotContainsString('Biggest new applications', $html);
    }

    public function test_confirming_offers_a_watch_area(): void
    {
        $s = DigestSubscriber::create(['email' => 'jane@example.com']);
        Livewire::test('pages::digest.confirm', ['token' => $s->token])->call('confirm')
            ->assertSee('Set up a free watch area');
    }
}
