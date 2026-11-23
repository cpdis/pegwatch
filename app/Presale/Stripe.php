<?php

namespace App\Presale;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The two Stripe calls the pre-sale needs, over plain HTTP: create a Checkout
 * Session, and verify a webhook's signature. No SDK, no stored card data.
 */
class Stripe
{
    public static function enabled(): bool
    {
        return config('services.stripe.secret') !== '' && config('services.stripe.presale_price') !== '';
    }

    /** @return array{id: string, url: string} */
    public function checkout(int $presaleId, string $email, string $successUrl, string $cancelUrl): array
    {
        $res = Http::asForm()->withToken(config('services.stripe.secret'))
            ->post('https://api.stripe.com/v1/checkout/sessions', [
                'mode' => 'payment',
                'line_items' => [['price' => config('services.stripe.presale_price'), 'quantity' => 1]],
                'customer_email' => $email,
                'client_reference_id' => (string) $presaleId,
                'metadata' => ['presale_id' => (string) $presaleId, 'plan' => 'prospector'],
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
            ]);
        if (! $res->successful() || ! $res->json('url')) {
            throw new RuntimeException('Stripe checkout failed: '.$res->status().' '.$res->json('error.message'));
        }

        return ['id' => $res->json('id'), 'url' => $res->json('url')];
    }

    /** Stripe-Signature: t=<unix>,v1=<hex hmac of "t.payload">. Rejects anything over 5 minutes old. */
    public static function verify(string $payload, string $header, string $secret, int $tolerance = 300): bool
    {
        if ($secret === '') {
            return false;
        }
        $parts = [];
        foreach (explode(',', $header) as $kv) {
            [$k, $v] = array_pad(explode('=', trim($kv), 2), 2, '');
            $parts[$k][] = $v;
        }
        $t = (int) ($parts['t'][0] ?? 0);
        if ($t === 0 || abs(time() - $t) > $tolerance) {
            return false;
        }
        $expected = hash_hmac('sha256', $t.'.'.$payload, $secret);
        foreach ($parts['v1'] ?? [] as $sig) {
            if (hash_equals($expected, $sig)) {
                return true;
            }
        }

        return false;
    }
}
