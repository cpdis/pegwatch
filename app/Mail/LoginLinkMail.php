<?php

namespace App\Mail;

use App\Auth\MagicLink;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class LoginLinkMail extends Mailable
{
    public function __construct(public string $url, public string $email) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your Pegwatch login link');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.login-link', with: ['minutes' => MagicLink::LOGIN_MINUTES]);
    }
}
