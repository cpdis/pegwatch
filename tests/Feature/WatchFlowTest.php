<?php

namespace Tests\Feature;

use App\Mail\WatchConfirmMail;
use App\Models\User;
use App\Models\WatchArea;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/** W1, T7, H7: from "I care about this ground" to a confirmed watch. */
class WatchFlowTest extends TestCase
{
    use RefreshDatabase;

    private const KAL = ['kind' => 'circle', 'center' => [121.5, -30.8], 'radiusKm' => 5];

    private static function square(float $x, float $y, float $w = 0.01): string
    {
        return sprintf('SRID=4326;MULTIPOLYGON(((%1$F %2$F,%3$F %2$F,%3$F %4$F,%1$F %4$F,%1$F %2$F)))', $x, $y, $x + $w, $y + $w);
    }

    protected function setUp(): void
    {
        parent::setUp();
        DB::insert(
            "insert into tenements (tenid, fmt_tenid, type, type_code, status, holders, holder_is_company, area_ha, geom)
             values ('E  1500001', 'E 15/1', 'EXPLORATION LICENCE', 'E', 'LIVE', '{\"ACME GOLD PTY LTD\"}', '{t}', 100, ?::geometry),
                    ('P  1500002', 'P 15/2', 'PROSPECTING LICENCE', 'P', 'PENDING', '{\"SMITH, JOHN\"}', '{f}', 100, ?::geometry)",
            [self::square(121.50, -30.80), self::square(121.505, -30.80)]
        );
    }

    /** The guest half of the flow: a shape and an email, and the link the email carries. */
    private function guestAsks(string $email, array $shape = self::KAL, string $name = 'Kal east'): string
    {
        Mail::fake();
        Livewire::test('watch-this-area')
            ->call('setShape', $shape)
            ->assertSee('Inside today:')
            ->set('name', $name)->set('email', $email)
            ->call('submit')
            ->assertHasNoErrors()->assertSee('Check your inbox');

        $url = null;
        Mail::assertSent(WatchConfirmMail::class, function (WatchConfirmMail $m) use (&$url, $email) {
            $url = $m->area->confirmUrl();

            return $m->hasTo(strtolower(trim($email)));
        });

        return $url;
    }

    public function test_a_guest_goes_from_a_shape_and_an_email_to_a_confirmed_watch(): void
    {
        $url = $this->guestAsks('Pat@Example.com ');

        $user = User::sole();
        $area = WatchArea::sole();
        $this->assertSame('pat@example.com', $user->email);
        $this->assertFalse($user->hasPassword());
        $this->assertNull($area->confirmed_at);
        $this->assertSame(['death', 'release_scheduled', 'released'], $area->event_types);
        $this->assertFalse($area->include_covered_deaths);

        // A mail scanner opens the link: a page with a button, nothing confirmed, no one signed in.
        $this->get($url)->assertOk()->assertSee('Start watching Kal east?')->assertSee('Confirm and start watching');
        $this->assertNull($area->fresh()->confirmed_at);
        $this->assertGuest();

        $this->post($url)->assertRedirect(route('watch-areas.index'))
            ->assertSessionHas('status', 'Watching Kal east. Your first email comes after the next extract with changes.');
        $this->assertNotNull($area->fresh()->confirmed_at);
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->assertAuthenticatedAs($user);

        // The circle is stored as a polygon of about pi * 5^2 km2.
        $km2 = DB::scalar('select ST_Area(area::geography) / 1e6 from watch_areas');
        $this->assertEqualsWithDelta(78.5, $km2, 1.5);
    }

    public function test_a_used_or_expired_or_forged_link_signs_no_one_in(): void
    {
        $url = $this->guestAsks('pat@example.com');
        $this->post($url);
        $this->post(route('logout'));

        // Used: no second sign-in from the same link.
        $this->post($url)->assertRedirect(route('watch-areas.index'));
        $this->assertGuest();
        $this->get($url)->assertSee('You already watch Kal east');

        $fresh = $this->guestAsks('sam@example.com', name: 'Sam');
        $this->post(str_replace('signature=', 'signature=0', $fresh))->assertSee('This link has expired');
        $this->travel(WatchArea::CONFIRM_DAYS + 1)->days();
        $this->get($fresh)->assertSee('This link has expired');
        $this->post($fresh)->assertSee('This link has expired');
        $this->assertGuest();
        $this->assertNull(WatchArea::where('name', 'Sam')->value('confirmed_at'));
    }

    public function test_an_existing_account_gets_the_watch_and_the_free_limit_replaces_with_consent(): void
    {
        $user = User::factory()->create(['email' => 'pat@example.com']);
        $old = $user->watchAreas()->create(['name' => 'Boulder', 'latitude' => -30.78, 'longitude' => 121.49, 'radius_km' => 5, 'event_types' => ['death']]);
        $old->confirm();

        $url = $this->guestAsks('pat@example.com', name: 'Kal east');
        $this->assertSame(2, $user->watchAreas()->count());

        $this->get($url)->assertSee('The free plan watches one area. Confirming replaces Boulder.')
            ->assertSee('Replace Boulder with Kal east')->assertSee('/pro?ref=second-area', false);
        $this->post($url)->assertRedirect(route('watch-areas.index'));

        $this->assertSame(['Kal east'], $user->watchAreas()->pluck('name')->all());
        $this->assertAuthenticatedAs($user);
    }

    public function test_a_second_ask_before_confirming_replaces_the_first(): void
    {
        $first = $this->guestAsks('pat@example.com', name: 'First');
        $this->guestAsks('pat@example.com', name: 'Second');

        $this->assertSame(['Second'], WatchArea::pluck('name')->all());
        $this->get($first)->assertSee('This link has expired');
    }

    public function test_a_signed_in_user_starts_watching_at_once_and_meets_the_free_limit(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test('watch-this-area')->call('setShape', self::KAL)->set('name', 'Kal east')
            ->assertDontSee('Email address')
            ->call('submit')->assertRedirect(route('watch-areas.index'));
        $this->assertNotNull($user->watchAreas()->sole()->confirmed_at);
        Mail::assertNothingSent();

        // The free plan has one area: a second one offers to replace it, or Pro.
        $second = Livewire::test('watch-this-area')->call('setShape', ['kind' => 'circle', 'center' => [121.6, -30.7], 'radiusKm' => 10])
            ->set('name', 'Kanowna')->call('submit')
            ->assertSee('The free plan watches one area')->assertSee('Replace Kal east')->assertSee('/pro?ref=second-area', false);
        $this->assertSame(['Kal east'], $user->watchAreas()->pluck('name')->all());
        $second->call('submit', true)->assertRedirect(route('watch-areas.index'));
        $this->assertSame(['Kanowna'], $user->watchAreas()->pluck('name')->all());

        // A holder watch has its own free slot.
        Livewire::test('watch-this-area', ['holder' => 'acme-gold-pty-ltd'])->assertSee('Holds today:')
            ->call('submit')->assertRedirect(route('watch-areas.index'));
        $this->assertEqualsCanonicalizing(['Kanowna', 'ACME GOLD PTY LTD'], $user->watchAreas()->pluck('name')->all());
    }

    public function test_watch_starts_from_a_tenement_or_holder_in_the_url(): void
    {
        $this->get('/watch?tenement=e-15-1')->assertOk()->assertSee('Watch E 15/1')->assertSee('E 15/1 and 2 km around it');
        $this->get('/watch?holder=smith-john')->assertOk()->assertSee('Watch SMITH, JOHN');
        $this->get('/watch?tenement=nope-1')->assertOk()->assertSee('Nothing on the register by that name');
        $this->get('/watch')->assertOk()->assertSee('Pick the ground on the map');

        Mail::fake();
        Livewire::test('watch-this-area', ['tenement' => 'e-15-1'])->set('email', 'pat@example.com')->call('submit')->assertHasNoErrors();
        $area = WatchArea::sole();
        $this->assertSame(['tenement', 'E  1500001', 'E 15/1'], [$area->kind, $area->tenid, $area->name]);
        // The outline (about 1 km square) plus 2 km all round: roughly (1 + 4)^2 km2, less the rounded corners.
        $km2 = DB::scalar('select ST_Area(area::geography) / 1e6 from watch_areas');
        $this->assertGreaterThan(18, $km2);
        $this->assertLessThan(25, $km2);
    }

    public function test_the_panel_takes_draw_tool_shapes_and_ignores_junk(): void
    {
        Livewire::test('watch-this-area')
            ->call('setShape', ['kind' => 'circle', 'center' => [0, 0], 'radiusKm' => 5])->assertSet('shape', null)
            ->call('setShape', 'nonsense')->assertSet('shape', null)
            ->call('setShape', ['kind' => 'circle', 'center' => [121.5, -30.8], 'radiusKm' => 500])
            ->assertSee('50 km around -30.8000, 121.5000')
            ->call('setRadius', 10)->assertSee('10 km around')->assertDispatched('watch-start')
            ->call('setShape', ['kind' => 'tenement', 'slug' => 'p-15-2', 'fmt_tenid' => 'P 15/2'])
            ->assertSee('P 15/2 and 2 km around it')->assertSet('name', 'P 15/2');
    }

    public function test_submit_needs_a_valid_email_and_an_alert_and_rejects_pro_types(): void
    {
        Mail::fake();
        $panel = fn () => Livewire::test('watch-this-area')->call('setShape', self::KAL)->set('email', 'pat@example.com');
        $panel()->set('email', 'nope')->call('submit')->assertHasErrors('email');
        $panel()->set('alerts', [])->call('submit')->assertHasErrors('alerts');
        $panel()->set('alerts', ['application'])->call('submit')->assertHasErrors('alerts.0');
        $panel()->set('alerts', ['application' => 'death'])->call('submit')->assertHasErrors('alerts');
        Livewire::test('watch-this-area')->set('email', 'pat@example.com')->call('submit')->assertHasErrors('shape');
        Mail::assertNothingSent();
        $this->assertDatabaseCount('watch_areas', 0);
    }

    public function test_bots_and_floods_get_no_email(): void
    {
        Mail::fake();
        Livewire::test('watch-this-area')->call('setShape', self::KAL)->set('email', 'bot@example.com')->set('website', 'x')
            ->call('submit')->assertSee('Check your inbox');
        $this->assertDatabaseCount('users', 0);

        for ($i = 0; $i < 3; $i++) {
            Livewire::test('watch-this-area')->call('setShape', self::KAL)->set('email', 'pat@example.com')->call('submit')->assertHasNoErrors();
        }
        Livewire::test('watch-this-area')->call('setShape', self::KAL)->set('email', 'pat@example.com')->call('submit')->assertHasErrors('email');
        Mail::assertSent(WatchConfirmMail::class, 3);
    }

    public function test_opting_in_to_covered_deaths_is_stored(): void
    {
        Mail::fake();
        Livewire::test('watch-this-area')->call('setShape', self::KAL)->set('email', 'pat@example.com')
            ->set('include_covered_deaths', true)->set('alerts', ['release'])->call('submit');
        $area = WatchArea::sole();
        $this->assertTrue($area->include_covered_deaths);
        $this->assertSame(['release_scheduled', 'released'], $area->event_types);
    }

    public function test_unconfirmed_watches_and_accounts_nobody_confirmed_are_pruned(): void
    {
        $this->guestAsks('gone@example.com', name: 'Gone');
        $keep = User::factory()->create();
        $this->travel(WatchArea::CONFIRM_DAYS + 2)->days();
        $this->guestAsks('new@example.com', name: 'New');

        Artisan::call('model:prune');

        $this->assertSame(['New'], WatchArea::pluck('name')->all());
        $this->assertEqualsCanonicalizing([$keep->email, 'new@example.com'], User::pluck('email')->all());
    }

    public function test_a_link_only_account_that_changes_its_email_is_not_pruned(): void
    {
        // Link-only (no password), a month old, with a confirmed watch.
        $user = User::factory()->withoutPassword()->create(['email' => 'pat@example.com', 'created_at' => now()->subDays(30)]);
        $area = $user->watchAreas()->create(['name' => 'Leonora', 'kind' => 'circle', 'latitude' => -28.88, 'longitude' => 121.33, 'radius_km' => 10, 'event_types' => ['death']]);
        $area->forceFill(['created_at' => now()->subDays(30), 'confirmed_at' => now()->subDays(30)])->save();

        // A new address is unverified until a link to it is pressed.
        Livewire::actingAs($user)->test('pages::settings.profile')
            ->set('email', 'pat@newmail.example')->call('updateProfileInformation')->assertHasNoErrors();
        $this->travel(WatchArea::CONFIRM_DAYS + 2)->days();
        Artisan::call('model:prune');

        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertDatabaseHas('watch_areas', ['id' => $area->id]);
    }

    public function test_the_map_page_carries_the_watch_panel(): void
    {
        $this->get('/')->assertOk()->assertSee('data-watch-panel', false)->assertSee('Watch this area');
    }
}
