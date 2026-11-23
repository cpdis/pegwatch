<?php

namespace Tests\Feature;

use App\Alerts\AlertMatcher;
use App\Alerts\ReleaseReminders;
use App\Mail\ReleaseReminderMail;
use App\Mail\WatchAreaDigest;
use App\Models\User;
use App\Models\WatchArea;
use App\Support\Format;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** G15, E1, E3, T7, H7: which changes reach a watch, and what the email says. */
class AlertsTest extends TestCase
{
    use RefreshDatabase;

    private int $run;

    private static function square(float $x, float $y, float $w = 0.01): string
    {
        return sprintf('SRID=4326;MULTIPOLYGON(((%1$F %2$F,%3$F %2$F,%3$F %4$F,%1$F %4$F,%1$F %2$F)))', $x, $y, $x + $w, $y + $w);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->run = DB::table('ingest_runs')->insertGetId([
            'status' => 'ok', 'finished_at' => now(), 'tenements_extract' => '2026-11-22 20:11:00+00', 'stale' => false,
        ]);
        DB::insert(
            "insert into tenements (tenid, fmt_tenid, type, type_code, status, holders, holder_is_company, area_ha, geom)
             values ('E  2600001', 'E 26/1', 'EXPLORATION LICENCE', 'E', 'LIVE', '{\"ACME GOLD PTY LTD\"}', '{t}', 100, ?::geometry)",
            [self::square(121.50, -30.80)]
        );
    }

    private function event(string $type, string $id, array $details, float $x = 121.52, string $holders = '{"JONES, MARY"}', string $tenType = 'PROSPECTING LICENCE'): void
    {
        DB::insert(
            'insert into tenement_events (run_id, event_type, tenid, fmt_tenid, tenement_type, holders, occurred_at, details, geom)
             values (?, ?, ?, ?, ?, ?, ?, ?::jsonb, ?::geometry)',
            [$this->run, $type, $id, $id, $tenType, $holders, $type === 'death' ? '2026-11-16' : null, json_encode($details), self::square($x, -30.80)]
        );
    }

    private function watch(array $attrs = [], ?User $user = null): WatchArea
    {
        $area = ($user ?? User::factory()->create())->watchAreas()->create([
            'name' => 'Kal east', 'latitude' => -30.795, 'longitude' => 121.525, 'radius_km' => 3,
            'event_types' => WatchArea::FREE_EVENT_TYPES, ...$attrs,
        ]);
        $area->confirm();
        // Watches match changes found after they were made; this one predates tonight's run.
        $area->forceFill(['created_at' => now()->subHour()])->save();

        return $area->fresh();
    }

    /** @return list<string> tenement IDs emailed to the watch, in email order */
    private function alerted(WatchArea $area): array
    {
        $ids = [];
        Mail::assertSent(WatchAreaDigest::class, function (WatchAreaDigest $m) use ($area, &$ids) {
            if ($m->area->id === $area->id) {
                $ids = array_merge(...array_values(array_map(fn ($items) => array_column($items, 'id'), $m->groups())));
            }

            return true;
        });

        return $ids;
    }

    /** Names of the attached .ics files that hold a calendar event. */
    private static function calendarFiles($mailable): array
    {
        return array_values(array_map(fn ($a) => $a->as, array_filter($mailable->attachments(),
            fn ($a) => $a->mime === 'text/calendar' && str_contains($a->attachWith(fn () => '', fn ($data) => $data()), 'BEGIN:VEVENT'))));
    }

    private function deaths(): void
    {
        $this->event('death', 'P 26/10', ['kind' => 'ended', 'death_reason' => 'SURRENDERED', 'area_ha' => 120.4, 'uncovered_share' => 0.93]);
        $this->event('death', 'P 26/11', ['kind' => 'ended', 'death_reason' => 'Expired', 'area_ha' => 5, 'uncovered_share' => 0.1]);
        $this->event('death', 'P 26/12', ['kind' => 'converted', 'converted_to' => 'M 26/1', 'area_ha' => 100, 'uncovered_share' => 1]);
        $this->event('death', 'P 26/13', ['kind' => 'amalgamated', 'amalgamation_id' => 'A1 into E 26/1', 'area_ha' => 100, 'uncovered_share' => 1]);
    }

    public function test_conversions_amalgamations_and_covered_deaths_are_skipped_unless_opted_in(): void
    {
        Mail::fake();
        $this->deaths();
        $default = $this->watch();
        $all = $this->watch(['name' => 'Everything', 'include_covered_deaths' => true]);

        app(AlertMatcher::class)->run($this->run);

        $this->assertSame(['P 26/10'], $this->alerted($default));
        $this->assertEqualsCanonicalizing(['P 26/10', 'P 26/11', 'P 26/12', 'P 26/13'], $this->alerted($all));
    }

    public function test_unconfirmed_and_stopped_watches_get_nothing(): void
    {
        Mail::fake();
        $this->deaths();
        $pending = User::factory()->create()->watchAreas()->create([
            'name' => 'Pending', 'latitude' => -30.795, 'longitude' => 121.525, 'radius_km' => 3, 'event_types' => ['death'],
        ]);
        $stopped = $this->watch(['name' => 'Stopped']);
        $stopped->forceFill(['unsubscribed_at' => now()])->save();

        $this->assertSame(['matched' => 0, 'emails' => 0], app(AlertMatcher::class)->run($this->run));
        $this->assertNull($pending->confirmed_at);
    }

    public function test_holder_and_tenement_watches_match_their_own_changes(): void
    {
        Mail::fake();
        $this->event('death', 'P 26/20', ['kind' => 'ended', 'area_ha' => 50, 'uncovered_share' => 1], x: 125.0, holders: '{"Acme Gold Pty. Ltd."}');
        $this->event('death', 'P 26/21', ['kind' => 'ended', 'area_ha' => 50, 'uncovered_share' => 1], x: 125.1, holders: '{"OTHER PTY LTD"}');
        // 1.5 km east of E 26/1's outline, and 20 km east of it.
        $this->event('death', 'P 26/22', ['kind' => 'ended', 'area_ha' => 50, 'uncovered_share' => 1], x: 121.525);
        $this->event('death', 'P 26/23', ['kind' => 'ended', 'area_ha' => 50, 'uncovered_share' => 1], x: 121.72);

        $holder = $this->watch(['kind' => 'holder', 'name' => 'ACME GOLD PTY LTD', 'latitude' => null, 'longitude' => null, 'radius_km' => null, 'holder_slug' => 'acme-gold-pty-ltd']);
        $tenement = $this->watch(['kind' => 'tenement', 'name' => 'E 26/1', 'latitude' => null, 'longitude' => null, 'radius_km' => null, 'tenid' => 'E  2600001']);
        $this->assertNull(DB::scalar('select area from watch_areas where id = ?', [$holder->id]));

        app(AlertMatcher::class)->run($this->run);

        $this->assertSame(['P 26/20'], $this->alerted($holder));
        $this->assertSame(['P 26/22'], $this->alerted($tenement));
    }

    public function test_the_alert_email_leads_with_releases_and_says_what_was_left_uncovered(): void
    {
        Mail::fake();
        $this->deaths();
        $releaseAt = now()->addDays(9)->setTimezone('Australia/Perth')->setTime(8, 30);
        $this->event('release_scheduled', 'E 26/1', ['release_id' => 'R7', 'release_at' => $releaseAt->toIso8601String(), 'area_ha' => 12.3],
            // Some events carry only the type code; the email still names it.
            x: 121.50, tenType: 'E', holders: '{"ACME GOLD PTY LTD"}');
        $area = $this->watch();

        app(AlertMatcher::class)->run($this->run);

        Mail::assertSent(WatchAreaDigest::class, function (WatchAreaDigest $m) use ($area, $releaseAt) {
            $html = $m->render();
            $this->assertSame('Kal east: 1 release scheduled, 1 tenement dead', $m->envelope()->subject);
            $this->assertSame(['releases', 'deaths'], array_keys($m->groups()));
            $this->assertStringContainsString('Part releases '.$releaseAt->format('D j M Y').', 8:30am AWST (in 9 days)', $html);
            $this->assertStringContainsString('Surrendered on 16 Nov 2026. 112 ha uncovered (93% of 120.4 ha)', $html);
            $this->assertStringContainsString('Exploration licence', $html);
            $this->assertStringNotContainsString('EXPLORATION LICENCE', $html);
            $this->assertStringContainsString('/#map=', $html);
            $this->assertStringContainsString(e(Format::TENGRAPH_URL), $html);
            $this->assertStringContainsString(e($area->stopUrl()), $html);
            $this->assertStringNotContainsString(' is open', $html);

            $this->assertSame(['release-e-26-1.ics'], self::calendarFiles($m));

            return $m->hasTo($area->user->email);
        });
    }

    public function test_alert_emails_carry_one_click_unsubscribe_that_needs_no_login(): void
    {
        $area = $this->watch();
        $headers = (new WatchAreaDigest($area, [], null))->headers()->text;
        $this->assertSame('List-Unsubscribe=One-Click', $headers['List-Unsubscribe-Post']);
        $this->assertSame('<'.$area->stopUrl().'>', $headers['List-Unsubscribe']);

        // The mail client POSTs with no session or CSRF token.
        $route = app('router')->getRoutes()->getByName('watch-areas.stop.one-click');
        $this->assertContains(PreventRequestForgery::class, $route->excludedMiddleware());
        DB::table('alert_deliveries')->insert(['watch_area_id' => $area->id, 'tenement_event_id' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->post($area->stopUrl(), ['List-Unsubscribe' => 'One-Click'])->assertNoContent();
        $this->assertNotNull($area->fresh()->unsubscribed_at);
        $this->assertDatabaseCount('alert_deliveries', 0);
        $this->post($area->stopUrl(), ['List-Unsubscribe' => 'One-Click'])->assertNoContent();
        $this->assertGuest();

        // The link in the email body shows a page and the button turns alerts back on.
        $this->get($area->stopUrl())->assertOk()->assertSee('Alerts for Kal east are stopped')->assertSee('Turn alerts back on');
        $this->post($area->stopUrl(), ['resume' => '1'])->assertRedirect($area->stopUrl());
        $this->assertNull($area->fresh()->unsubscribed_at);

        // Opening the link changes nothing; a forged one is refused.
        $this->get($area->stopUrl())->assertSee('Stop alerts for Kal east?');
        $this->assertNull($area->fresh()->unsubscribed_at);
        $this->post(route('watch-areas.stop.one-click', ['watchArea' => $area->id]))->assertNotFound();
    }

    public function test_release_reminders_go_out_a_day_and_an_hour_before_once_each(): void
    {
        Mail::fake();
        $area = $this->watch(['event_types' => ['release_scheduled', 'released']]);
        DB::insert(
            "insert into release_pending (tenid, rlesid, fmt_tenid, ten_type, release_at, geom)
             values ('E  2600001', 'R1', 'E 26/1', 'EXPLORATION LICENCE', ?, ?::geometry),
                    ('E  2600009', 'R9', 'E 26/9', 'EXPLORATION LICENCE', ?, ?::geometry)",
            [now()->addHours(20), self::square(121.50, -30.80), now()->addHours(20), self::square(125.0, -30.80)]
        );
        $reminders = app(ReleaseReminders::class);

        $this->assertSame(1, $reminders->run());
        $this->assertSame(0, $reminders->run());
        Mail::assertSent(ReleaseReminderMail::class, fn ($m) => $m->kind === 'day' && $m->hasTo($area->user->email)
            && array_column($m->releases, 'fmt_tenid') === ['E 26/1']
            && str_contains($m->envelope()->subject, 'E 26/1 releases at')
            && self::calendarFiles($m) === ['release-e-26-1.ics']);

        $this->travelTo(now()->addHours(19)->addMinutes(30));
        $this->assertSame(1, $reminders->run());
        Mail::assertSent(ReleaseReminderMail::class, fn ($m) => $m->kind === 'hour' && str_starts_with($m->envelope()->subject, 'In 1 hour: E 26/1'));
        $this->assertSame(0, $reminders->run());
    }

    public function test_the_email_preview_shows_the_last_30_days_to_the_owner_only(): void
    {
        $this->deaths();
        $area = $this->watch();

        $this->actingAs($area->user)->get(route('watch-areas.preview', $area->id))->assertOk()
            ->assertSee('Preview: what this email would have said about the last 30 days')->assertSee('P 26/10')->assertDontSee('P 26/12');
        $this->actingAs(User::factory()->create())->get(route('watch-areas.preview', $area->id))->assertNotFound();
    }

    public function test_history_lists_the_emails_sent(): void
    {
        Mail::fake();
        $this->deaths();
        $area = $this->watch();
        app(AlertMatcher::class)->run($this->run);

        $history = AlertMatcher::history($area);
        $this->assertCount(1, $history);
        $this->assertSame(['P 26/10'], $history[0]['tenements']);
    }
}
