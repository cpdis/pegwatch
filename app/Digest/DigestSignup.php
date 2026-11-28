<?php

namespace App\Digest;

use App\Mail\DigestConfirmMail;
use App\Models\DigestSubscriber;
use App\Support\ClientIp;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Asking for the weekly digest, shared by the sign-up box, the API and the
 * MCP server. Double opt-in: only the confirmation goes out until its link is
 * pressed, and the answer is the same whether or not the address is already
 * subscribed.
 */
final class DigestSignup
{
    /** False when the flood limits (5 per IP each 10 minutes, 3 per address a day) say no. */
    public static function request(string $email, string $source): bool
    {
        $ipKey = 'digest-signup:ip:'.ClientIp::of();
        $emailKey = 'digest-signup:email:'.sha1($email);
        if (RateLimiter::tooManyAttempts($ipKey, 5) || RateLimiter::tooManyAttempts($emailKey, 3)) {
            return false;
        }
        RateLimiter::hit($ipKey, 600);
        RateLimiter::hit($emailKey, 86400);

        $subscriber = DigestSubscriber::firstOrCreate(['email' => $email], ['source' => $source]);
        if (! $subscriber->isActive()) {
            if ($subscriber->unsubscribed_at !== null) {
                // Coming back after unsubscribing: confirm again before sending anything.
                $subscriber->forceFill(['confirmed_at' => null, 'unsubscribed_at' => null, 'source' => $source])->save();
            }
            Mail::to($email)->send(new DigestConfirmMail($subscriber));
        }

        return true;
    }
}
