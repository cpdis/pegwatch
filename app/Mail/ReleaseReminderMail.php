<?php

namespace App\Mail;

use App\Models\WatchArea;
use App\Support\Format;
use App\Support\Ics;
use App\Support\Slug;
use Carbon\CarbonImmutable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/** "Tomorrow at 8:30am AWST, E 15/30 releases": a reminder before a release in watched ground. */
class ReleaseReminderMail extends Mailable
{
    /** @param list<array{tenid: string, rlesid: string, fmt_tenid: string, ten_type: ?string, release_at: string, area_ha: ?string}> $releases */
    public function __construct(public WatchArea $area, public string $kind, public array $releases) {}

    public function envelope(): Envelope
    {
        $first = $this->releases[0];
        $at = self::awst($first['release_at']);
        $more = count($this->releases) > 1 ? ' and '.(count($this->releases) - 1).' more' : '';

        return new Envelope(subject: ($this->kind === 'hour' ? 'In 1 hour' : ucfirst(Format::relative($at->format('Y-m-d H:i:s'))))
            .': '.$first['fmt_tenid'].$more.' releases at '.$at->format('g:ia').' AWST ('.$this->area->name.')');
    }

    public function headers(): Headers
    {
        return new Headers(text: [
            'List-Unsubscribe' => '<'.$this->area->stopUrl().'>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.release-reminder', with: [
            'items' => array_map(fn ($r) => [
                'id' => $r['fmt_tenid'],
                'url' => url('/tenements/'.Slug::make($r['fmt_tenid'])),
                'meta' => Format::sentence($r['ten_type']),
                'when' => self::awst($r['release_at'])->format('D j M Y, g:ia').' AWST',
                'rlesid' => $r['rlesid'],
                'ha' => $r['area_ha'],
            ], $this->releases),
            'stopUrl' => $this->area->stopUrl(),
        ]);
    }

    public function attachments(): array
    {
        return array_map(fn ($r) => Attachment::fromData(
            fn () => Ics::release($r['fmt_tenid'], $r['rlesid'], self::awst($r['release_at'])->format('Y-m-d H:i:s'), url('/tenements/'.Slug::make($r['fmt_tenid']))),
            'release-'.Slug::make($r['fmt_tenid']).'.ics'
        )->withMime('text/calendar'), $this->releases);
    }

    private static function awst(string $ts): CarbonImmutable
    {
        return CarbonImmutable::parse($ts)->setTimezone('Australia/Perth');
    }
}
