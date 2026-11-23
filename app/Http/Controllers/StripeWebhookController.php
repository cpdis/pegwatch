<?php

namespace App\Http\Controllers;

use App\Presale\Stripe;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/** Marks a pre-sale paid when Stripe says its Checkout Session completed. */
class StripeWebhookController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $payload = $request->getContent();
        abort_unless(Stripe::verify($payload, (string) $request->header('Stripe-Signature'), (string) config('services.stripe.webhook_secret')), 400);

        $event = json_decode($payload, true);
        $session = $event['data']['object'] ?? [];
        $paid = in_array($event['type'] ?? '', ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true)
            && ($session['payment_status'] ?? '') === 'paid';

        if ($paid && isset($session['id'])) {
            DB::table('presales')->where('stripe_session_id', $session['id'])->where('status', '!=', 'refunded')->update([
                'status' => 'paid',
                'amount_cents' => $session['amount_total'] ?? null,
                'currency' => isset($session['currency']) ? strtoupper($session['currency']) : null,
                'paid_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return response('', 200);
    }
}
