<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Sent once when a Prospector pre-order is paid: what happens next and how to get a refund. */
class PresalePaidMail extends Mailable
{
    public function __construct(public object $presale) {}

    public function envelope(): Envelope
    {
        $support = config('pegwatch.business.support_email');

        // Replies go to support, so "reply to this email" for a refund reaches a person.
        return new Envelope(subject: 'Your Pegwatch Prospector pre-order is in', replyTo: $support ? [$support] : []);
    }

    public function content(): Content
    {
        $cents = (int) ($this->presale->amount_cents ?? 0);

        return new Content(markdown: 'mail.presale-paid', with: [
            'amount' => $cents ? ($this->presale->currency === 'AUD' ? 'A$' : ($this->presale->currency ?? '').' ').number_format($cents / 100, 2) : null,
            'support' => config('pegwatch.business.support_email'),
            'business' => config('pegwatch.business'),
        ]);
    }
}
