<?php

namespace App\Http\Controllers;

use App\Presale\Presales;
use App\Presale\Stripe;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Keeps pre-sales in step with Stripe. Subscribe the endpoint to:
 * checkout.session.completed, checkout.session.async_payment_succeeded,
 * checkout.session.expired and charge.refunded.
 */
class StripeWebhookController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $payload = $request->getContent();
        abort_unless(Stripe::verify($payload, (string) $request->header('Stripe-Signature'), (string) config('services.stripe.webhook_secret')), 400);

        $event = json_decode($payload, true);
        $object = $event['data']['object'] ?? [];

        match ($event['type'] ?? '') {
            'checkout.session.completed', 'checkout.session.async_payment_succeeded' => Presales::markPaid($object),
            // Stripe closed the session unpaid: the place is free again.
            'checkout.session.expired' => isset($object['id']) && DB::table('presales')
                ->where('stripe_session_id', $object['id'])->where('status', 'checkout')
                ->update(['status' => 'expired', 'updated_at' => now()]),
            // A full refund made in the Stripe dashboard. A partial one keeps the place.
            'charge.refunded' => ($object['refunded'] ?? false) && is_string($object['payment_intent'] ?? null) && DB::table('presales')
                ->where('stripe_payment_intent', $object['payment_intent'])
                ->update(['status' => 'refunded', 'updated_at' => now()]),
            default => null,
        };

        return response('', 200);
    }
}
