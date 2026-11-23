<?php

namespace App\Mail;

use App\Models\DigestSubscriber;
use Carbon\CarbonImmutable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

class WeeklyDigestMail extends Mailable
{
    public function __construct(public string $week, public array $content, public DigestSubscriber $subscriber) {}

    public function envelope(): Envelope
    {
        $end = CarbonImmutable::parse($this->content['period_end'])->setTimezone('Australia/Perth');
        $freed = count($this->content['freed']);

        return new Envelope(subject: 'Pegwatch weekly: '.match (true) {
            $this->content['stale'] ?? false => 'no new register extract',
            $freed > 0 => $freed.' '.($freed === 1 ? 'tenement' : 'tenements').' freed ground',
            default => 'the WA register',
        }.', week to '.$end->format('j M'));
    }

    /** RFC 8058 one-click unsubscribe, which Gmail and Yahoo require of bulk senders. */
    public function headers(): Headers
    {
        return new Headers(text: [
            'List-Unsubscribe' => '<'.route('digest.unsubscribe.one-click', ['token' => $this->subscriber->token]).'>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.weekly-digest', with: [
            'c' => $this->content,
            'archiveUrl' => route('digest.issue', ['week' => $this->week]),
            'unsubscribeUrl' => $this->subscriber->unsubscribeUrl(),
        ]);
    }
}
