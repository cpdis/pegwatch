<?php

namespace Tests\Feature;

use App\Mail\PresalePaidMail;
use App\Presale\Stripe;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
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
        Mail::fake();

        Livewire::withQueryParams(['ref' => 'APLA'])->test('pages::pro')
            ->set('email', 'pat@example.com')->call('reserve')
            ->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_1');
        Http::assertSent(fn ($r) => $r['line_items'][0]['price'] === 'price_x' && $r['customer_email'] === 'pat@example.com'
            && $r['mode'] === 'payment' && $r->hasHeader('Authorization', 'Bearer sk_test_x')
            && str_ends_with($r['success_url'], 'thanks=paid&session_id={CHECKOUT_SESSION_ID}'));
        $row = DB::table('presales')->sole();
        $this->assertSame(['checkout', 'cs_test_1', 'pro-page:apla'], [$row->status, $row->stripe_session_id, $row->source]);

        $payload = json_encode(['type' => 'checkout.session.completed', 'data' => ['object' => [
            'id' => 'cs_test_1', 'payment_status' => 'paid', 'amount_total' => 8900, 'currency' => 'aud', 'payment_intent' => 'pi_1',
        ]]]);
        $sign = fn (string $body, string $secret, int $t) => 't='.$t.',v1='.hash_hmac('sha256', $t.'.'.$body, $secret);

        $this->withMiddleware(PreventRequestForgery::class);
        $post = fn (string $sig) => $this->call('POST', '/hooks/stripe', [], [], [], ['HTTP_STRIPE_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'], $payload);

        $post($sign($payload, 'wrong', time()))->assertStatus(400);
        $post($sign($payload, 'whsec_x', time() - 3600))->assertStatus(400);
        $this->assertSame('checkout', DB::table('presales')->value('status'));

        $post($sign($payload, 'whsec_x', time()))->assertOk();
        $row = DB::table('presales')->sole();
        $this->assertSame(['paid', 8900, 'AUD', 'pi_1'], [$row->status, (int) $row->amount_cents, $row->currency, $row->stripe_payment_intent]);
        // One receipt email, however often Stripe repeats the event.
        $post($sign($payload, 'whsec_x', time()))->assertOk();
        Mail::assertSent(PresalePaidMail::class, 1);

        $this->get('/pro?thanks=paid&session_id=cs_test_1')->assertOk()->assertSee('your pre-order is in');
        // A bare or unknown success URL never claims a payment.
        $this->get('/pro?thanks=paid')->assertOk()->assertDontSee('your pre-order is in')->assertSee('could not find that payment');

        // A full refund in the Stripe dashboard frees the place.
        $refund = json_encode(['type' => 'charge.refunded', 'data' => ['object' => ['payment_intent' => 'pi_1', 'refunded' => true]]]);
        $this->call('POST', '/hooks/stripe', [], [], [], ['HTTP_STRIPE_SIGNATURE' => $sign($refund, 'whsec_x', time()), 'CONTENT_TYPE' => 'application/json'], $refund)->assertOk();
        $this->assertSame('refunded', DB::table('presales')->value('status'));
    }

    public function test_a_stripe_error_says_so_and_holds_no_place(): void
    {
        config(['services.stripe.secret' => 'sk_test_x', 'services.stripe.presale_price' => 'price_x']);
        Http::fake(['api.stripe.com/*' => Http::response(['error' => ['message' => 'No such price']], 400)]);

        Livewire::test('pages::pro')->set('email', 'pat@example.com')->call('reserve')
            ->assertHasErrors('email')->assertDontSee('Your place is reserved');
        $this->assertSame('failed', DB::table('presales')->value('status'));
    }

    public function test_the_thank_you_page_asks_stripe_when_the_webhook_is_late(): void
    {
        config(['services.stripe.secret' => 'sk_test_x', 'services.stripe.presale_price' => 'price_x']);
        Mail::fake();
        DB::table('presales')->insert(['plan' => 'prospector', 'email' => 'pat@example.com', 'status' => 'checkout',
            'stripe_session_id' => 'cs_test_2', 'created_at' => now(), 'updated_at' => now()]);
        Http::fake(['api.stripe.com/v1/checkout/sessions/cs_test_2' => Http::response(['id' => 'cs_test_2', 'payment_status' => 'paid', 'amount_total' => 8900, 'currency' => 'aud'])]);

        $this->get('/pro?thanks=paid&session_id=cs_test_2')->assertOk()->assertSee('your pre-order is in');
        $this->assertSame('paid', DB::table('presales')->value('status'));
        Mail::assertSent(PresalePaidMail::class, 1);
    }

    public function test_only_paid_orders_count_and_a_full_presale_takes_names_instead(): void
    {
        config(['services.stripe.secret' => 'sk_test_x', 'services.stripe.presale_price' => 'price_x', 'pegwatch.presale.goal' => 2]);
        Http::fake();
        foreach (['a', 'b'] as $who) {
            DB::table('presales')->insert(['plan' => 'prospector', 'email' => "$who@example.com", 'status' => 'paid', 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('presales')->insert(['plan' => 'prospector', 'email' => 'c@example.com', 'status' => 'reserved', 'created_at' => now(), 'updated_at' => now()]);

        $this->get('/pro')->assertOk()->assertSee('All 2 pre-sale places are taken');
        Livewire::test('pages::pro')->set('email', 'd@example.com')->call('reserve')->assertSee('on the list');
        $this->assertSame('reserved', DB::table('presales')->where('email', 'd@example.com')->value('status'));
        Http::assertNothingSent();
    }

    public function test_signature_check_needs_a_secret(): void
    {
        $this->assertFalse(Stripe::verify('{}', 't='.time().',v1=x', ''));
    }
}
