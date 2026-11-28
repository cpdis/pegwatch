<?php

namespace App\Presale;

use App\Mail\PresalePaidMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Pre-sale bookkeeping shared by the plans page and the Stripe webhook.
 * Statuses: reserved (no payment asked), checkout (sent to Stripe), paid,
 * refunded, expired (Stripe closed the session unpaid), failed (Stripe refused
 * to open one).
 */
final class Presales
{
    /** Paid Prospector places: what the counter shows and the cap checks. */
    public static function paidCount(): int
    {
        return DB::table('presales')->where('plan', 'prospector')->where('status', 'paid')->distinct()->count('email');
    }

    /**
     * Places spoken for: paid, plus checkouts opened in the last half hour that may
     * still be paid. Stripe closes an unpaid session after 24 hours, but a buyer who
     * has not paid in 30 minutes rarely will.
     */
    public static function heldCount(): int
    {
        return self::paidCount() + DB::table('presales')->where('plan', 'prospector')->where('status', 'checkout')
            ->where('created_at', '>=', now()->subMinutes(30))->count();
    }

    public static function soldOut(): bool
    {
        return self::heldCount() >= (int) config('pegwatch.presale.goal');
    }

    /**
     * Record a Checkout Session as paid once: the webhook and the thank-you page can
     * both see it, and only the first sends the receipt email. Returns true when the
     * row is paid.
     */
    public static function markPaid(array $session): bool
    {
        if (($session['payment_status'] ?? '') !== 'paid' || ! isset($session['id'])) {
            return false;
        }
        $changed = DB::table('presales')->where('stripe_session_id', $session['id'])
            ->whereNotIn('status', ['paid', 'refunded'])->update([
                'status' => 'paid',
                'amount_cents' => $session['amount_total'] ?? null,
                'currency' => isset($session['currency']) ? strtoupper($session['currency']) : null,
                'stripe_payment_intent' => is_string($session['payment_intent'] ?? null) ? $session['payment_intent'] : null,
                'paid_at' => now(),
                'updated_at' => now(),
            ]);
        $row = DB::table('presales')->where('stripe_session_id', $session['id'])->first();
        if ($changed && $row) {
            Mail::to($row->email)->send(new PresalePaidMail($row));
        }

        return $row?->status === 'paid';
    }
}
