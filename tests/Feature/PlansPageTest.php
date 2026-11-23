<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/** D2, G10: the plans page shows what Pro gets you and how far the pre-sale has come. */
class PlansPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.stripe.secret' => '', 'services.stripe.presale_price' => '', 'pegwatch.presale.goal' => 20]);
    }

    public function test_the_page_leads_with_an_alert_and_a_phone_and_answers_the_usual_questions(): void
    {
        $this->get('/pro')->assertOk()
            ->assertSeeInOrder(['<title>', 'Plans - Pegwatch', '</title>'], false)
            ->assertSee('An example alert email')->assertSee('Your watch on your phone')
            ->assertSee('Included')->assertDontSee('>Yes<', false)
            ->assertSee('When does my year start?')->assertSee('Can I get a refund?')
            ->assertSee('confirm in')->assertSee('Mining Registrar')
            ->assertSee(route('watch.start'), false)
            ->assertSee('20 places at the pre-sale price.');
    }

    public function test_the_goal_shows_places_taken_once_a_few_are(): void
    {
        foreach (range(1, 6) as $i) {
            DB::table('presales')->insert(['plan' => 'prospector', 'email' => "p$i@example.com", 'source' => 'pro-page', 'status' => 'reserved', 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->get('/pro')->assertSee('6 of 20 pre-sale places taken');
    }

    public function test_where_the_visitor_came_from_is_kept(): void
    {
        $this->get('/pro?ref=export')->assertOk();
        Livewire::withQueryParams(['ref' => 'export'])->test('pages::pro')->set('email', 'pat@example.com')->call('reserve');
        $this->assertSame('pro-page:export', DB::table('presales')->value('source'));
    }
}
