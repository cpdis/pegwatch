<?php

namespace App\Mail;

use App\Models\WatchArea;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WatchAreaDigest extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public WatchArea $area, public array $events, public ?object $run) {}

    public function envelope(): Envelope
    {
        $n = count($this->events);

        return new Envelope(subject: "Pegwatch: {$n} ".($n === 1 ? 'change' : 'changes')." in {$this->area->name}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.watch-area-digest');
    }
}
