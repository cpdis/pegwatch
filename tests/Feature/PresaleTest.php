<?php

namespace Tests\Feature;

use App\Presale\Stripe;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class PresaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_without_stripe_the_presale_records_a_reservation(): void
    {
        config(['services.stripe.secret' => '', 'services.stripe.presale_price' => '']);
        Http::fake();

        $this->get('/pro')->assertOk()->assertSee('A$89')->assertSee('Reserve Prospector')->assertSee('What each plan includes');
        Livewire::test('pages::pro')->set('email', ' Pat@Example.com ')->call('reserve')
            ->assertHasNoErrors()->assertSee('Your place is reserved');

        $row = DB::table('presales')->sole();
        $this->assertSame(['prospector', 'pat@example.com', 'reserved'], [$row->plan, $row->email, $row->status]);
        Http::assertNothingSent();
    }

    public function test_team_interest_is_recorded_without_payment(): void
    {
        config(['services.stripe.secret' => 'sk_test_x', 'services.stripe.presale_price' => 'price_x']);
        Http::fake();

        Livewire::test('pages::pro')->call('choose', 'team')->set('email', 'c@example.com')->call('reserve')
            ->assertSee('we\'ll be in touch');
        $this->assertSame('team', DB::table('presales')->value('plan'));
        Http::assertNothingSent();
    }

    public function test_with_stripe_the_presale_goes_to_checkout_and_the_webhook_marks_it_paid(): void
    {
        config(['services.stripe.secret' => 'sk_test_x', 'services.stripe.presale_price' => 'price_x', 'services.stripe.webhook_secret' => 'whsec_x']);
        Http::fake(['api.stripe.com/*' => Http::response(['id' => 'cs_test_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_1'])]);

        Livewire::withQueryParams(['ref' => 'APLA'])->test('pages::pro')
            ->set('email', 'pat@example.com')->call('reserve')
            ->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_1');
        Http::assertSent(fn ($r) => $r['line_items'][0]['price'] === 'price_x' && $r['customer_email'] === 'pat@example.com'
            && $r['mode'] === 'payment' && $r->hasHeader('Authorization', 'Bearer sk_test_x'));
        $row = DB::table('presales')->sole();
        $this->assertSame(['checkout', 'cs_test_1', 'pro-page:apla'], [$row->status, $row->stripe_session_id, $row->source]);

        $payload = json_encode(['type' => 'checkout.session.completed', 'data' => ['object' => [
            'id' => 'cs_test_1', 'payment_status' => 'paid', 'amount_total' => 8900, 'currency' => 'aud',
        ]]]);
        $sign = fn (string $body, string $secret, int $t) => 't='.$t.',v1='.hash_hmac('sha256', $t.'.'.$body, $secret);

        $this->withMiddleware(PreventRequestForgery::class);
        $post = fn (string $sig) => $this->call('POST', '/hooks/stripe', [], [], [], ['HTTP_STRIPE_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'], $payload);

        $post($sign($payload, 'wrong', time()))->assertStatus(400);
        $post($sign($payload, 'whsec_x', time() - 3600))->assertStatus(400);
        $this->assertSame('checkout', DB::table('presales')->value('status'));

        $post($sign($payload, 'whsec_x', time()))->assertOk();
        $row = DB::table('presales')->sole();
        $this->assertSame(['paid', 8900, 'AUD'], [$row->status, (int) $row->amount_cents, $row->currency]);

        $this->get('/pro?thanks=paid')->assertOk()->assertSee('your pre-order is in');
    }

    public function test_a_stripe_error_falls_back_to_a_reservation(): void
    {
        config(['services.stripe.secret' => 'sk_test_x', 'services.stripe.presale_price' => 'price_x']);
        Http::fake(['api.stripe.com/*' => Http::response(['error' => ['message' => 'No such price']], 400)]);

        Livewire::test('pages::pro')->set('email', 'pat@example.com')->call('reserve')->assertSee('Your place is reserved');
        $this->assertSame('reserved', DB::table('presales')->value('status'));
    }

    public function test_signature_check_needs_a_secret(): void
    {
        $this->assertFalse(Stripe::verify('{}', 't='.time().',v1=x', ''));
    }
}
