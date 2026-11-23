<?php

namespace App\Mail;

use App\Models\WatchArea;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** The one email a new watch sends before it is confirmed. */
class WatchConfirmMail extends Mailable
{
    public function __construct(public WatchArea $area) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Confirm your Pegwatch watch: '.$this->area->name);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.watch-confirm', with: [
            'confirmUrl' => $this->area->confirmUrl(),
            'describe' => $this->area->shape()->describe(),
            'days' => WatchArea::CONFIRM_DAYS,
        ]);
    }
}
