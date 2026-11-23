<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WatchArea;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/** W3, O15, W4, G10: the watch page after a watch exists. */
class WatchAreasPageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        DB::insert(
            "insert into tenements (tenid, fmt_tenid, type, type_code, status, holders, holder_is_company, area_ha, applied_at, geom)
             values ('P  1500002', 'P 15/2', 'PROSPECTING LICENCE', 'P', 'PENDING', '{\"SMITH, JOHN\"}', '{f}', 100, '2026-11-01',
                     'SRID=4326;MULTIPOLYGON(((121.5 -30.8,121.51 -30.8,121.51 -30.79,121.5 -30.79,121.5 -30.8)))'::geometry)"
        );
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    private function area(array $attrs = [], bool $confirmed = true): WatchArea
    {
        $area = $this->user->watchAreas()->create([
            'name' => 'Kal east', 'latitude' => -30.8, 'longitude' => 121.5, 'radius_km' => 5,
            'event_types' => WatchArea::FREE_EVENT_TYPES, ...$attrs,
        ]);
        if ($confirmed) {
            $area->confirm();
        }

        return $area->fresh();
    }

    public function test_with_no_watch_the_page_offers_the_watch_panel(): void
    {
        $this->get('/watch-areas')->assertOk()->assertSee('Watch an area')->assertSee('data-watch-panel', false)
            ->assertSee('None yet. Your first comes after the next extract')->assertDontSee('Add a second area');
    }

    public function test_a_watch_shows_what_is_inside_and_the_pro_locks(): void
    {
        $this->area();

        $this->get('/watch-areas')->assertOk()
            ->assertSee('Area · 5 km circle')->assertSee('Watching')
            ->assertSee('Tenements inside Kal east')->assertSee('P 15/2')->assertSee('SMITH, JOHN')
            ->assertSee('In the last 30 days: 0 deaths, 0 applications.')
            ->assertSee('Add a second area')->assertSee('/pro?ref=second-area', false)
            ->assertSee('Export GPX / KML')->assertSee('/pro?ref=export', false)
            ->assertSee('/pro?ref=alert-types', false)
            ->assertSee('Preview the email')
            ->assertSee('Next check:');
    }

    public function test_radius_presets_and_moving_the_centre_reshape_the_stored_area(): void
    {
        $area = $this->area();
        $km2 = fn () => (float) DB::scalar('select ST_Area(area::geography) / 1e6 from watch_areas where id = ?', [$area->id]);

        Livewire::test('pages::watch-areas')->call('setRadius', $area->id, 25)->assertDispatched('watch-area-moved');
        $this->assertSame(25.0, $area->fresh()->radius_km);
        $this->assertEqualsWithDelta(M_PI * 625, $km2(), 15);

        Livewire::test('pages::watch-areas')->call('moveCentre', $area->id, 121.6, -30.7);
        $this->assertSame([121.6, -30.7, 25.0], [$area->fresh()->longitude, $area->fresh()->latitude, $area->fresh()->radius_km]);

        // The draw tool's edits arrive as watch-shape details.
        Livewire::test('pages::watch-areas')->call('drawn', $area->id, ['kind' => 'circle', 'center' => [121.55, -30.75], 'radiusKm' => 12]);
        $this->assertSame([121.55, -30.75, 12.0], [$area->fresh()->longitude, $area->fresh()->latitude, $area->fresh()->radius_km]);
        $this->assertEqualsWithDelta(M_PI * 144, $km2(), 4);

        // Junk changes nothing.
        Livewire::test('pages::watch-areas')->call('moveCentre', $area->id, 0, 0);
        $this->assertSame(121.55, $area->fresh()->longitude);
    }

    public function test_alert_choices_can_be_changed_but_not_all_turned_off(): void
    {
        $area = $this->area();
        $page = Livewire::test('pages::watch-areas');

        $page->call('toggleAlert', $area->id, 'release');
        $this->assertSame(['death'], $area->fresh()->event_types);
        $page->call('toggleAlert', $area->id, 'death');
        $this->assertSame(['death'], $area->fresh()->event_types);
        $page->call('toggleCovered', $area->id);
        $this->assertTrue($area->fresh()->include_covered_deaths);
    }

    public function test_alerts_can_be_stopped_and_resumed_and_a_pending_watch_confirmed_here(): void
    {
        $area = $this->area();
        Livewire::test('pages::watch-areas')->call('setStopped', $area->id, true)->assertSee('You stopped alerts for this watch.');
        $this->assertNotNull($area->fresh()->unsubscribed_at);
        Livewire::test('pages::watch-areas')->call('setStopped', $area->id, false);
        $this->assertNull($area->fresh()->unsubscribed_at);

        $pending = $this->area(['name' => 'Boulder'], confirmed: false);
        $this->get('/watch-areas')->assertSee('Not watching yet: we emailed you a link to confirm it.');
        Livewire::test('pages::watch-areas')->call('confirm', $pending->id)->assertRedirect(route('watch-areas.index'));
        $this->assertSame(['Boulder'], $this->user->watchAreas()->pluck('name')->all());
    }

    public function test_the_alert_history_lists_sent_emails(): void
    {
        $area = $this->area();
        $run = DB::table('ingest_runs')->insertGetId(['status' => 'ok', 'finished_at' => now()]);
        $event = DB::table('tenement_events')->insertGetId(['run_id' => $run, 'event_type' => 'death', 'tenid' => 'P  1500009', 'fmt_tenid' => 'P 15/9']);
        DB::table('alert_deliveries')->insert(['watch_area_id' => $area->id, 'tenement_event_id' => $event, 'sent_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $this->get('/watch-areas')->assertSee('Alert emails')->assertSee('P 15/9')->assertDontSee('None yet.');
    }

    public function test_another_users_watch_cannot_be_changed(): void
    {
        $theirs = User::factory()->create()->watchAreas()->create([
            'name' => 'Theirs', 'latitude' => -30.8, 'longitude' => 121.5, 'radius_km' => 5, 'event_types' => ['death'],
        ]);

        Livewire::test('pages::watch-areas')->call('delete', $theirs->id)->assertNotFound();
        Livewire::test('pages::watch-areas')->call('rename', $theirs->id, 'Mine now')->assertNotFound();
        $this->assertSame('Theirs', $theirs->fresh()->name);
    }

    public function test_a_watch_can_be_renamed_and_deleted(): void
    {
        $area = $this->area();
        Livewire::test('pages::watch-areas')->call('rename', $area->id, '  Boulder  ');
        $this->assertSame('Boulder', $area->fresh()->name);
        Livewire::test('pages::watch-areas')->call('rename', $area->id, '');
        $this->assertSame('Boulder', $area->fresh()->name);

        Livewire::test('pages::watch-areas')->call('delete', $area->id)->assertRedirect(route('watch-areas.index'));
        $this->assertDatabaseCount('watch_areas', 0);
    }
}
