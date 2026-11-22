<?php

namespace App\Mail;

use App\Models\DigestSubscriber;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class DigestConfirmMail extends Mailable
{
    public function __construct(public DigestSubscriber $subscriber) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Confirm your Pegwatch weekly digest');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.digest-confirm', with: [
            'confirmUrl' => $this->subscriber->confirmUrl(),
        ]);
    }
}
