<?php

namespace App\Mail;

use App\Models\WatchArea;
use App\Support\Format;
use App\Support\Ics;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * One alert email for one watch after a night with changes (E1): grouped by
 * urgency, a calendar file for each release (E3), and one-click unsubscribe.
 */
class WatchAreaDigest extends Mailable
{
    use Queueable, SerializesModels;

    /** Urgency order: a release has a time to be ready for, a death may leave ground, the rest is context. */
    public const GROUPS = [
        'releases' => ['release_scheduled', 'released', 'release_cancelled'],
        'deaths' => ['death'],
        'other' => [],
    ];

    public const GROUP_HEADINGS = ['releases' => 'Releases', 'deaths' => 'Tenements recorded dead', 'other' => 'Other changes'];

    /** Subject phrases, one and many, in subject order. */
    private const COUNTS = [
        'release_scheduled' => ['1 release scheduled', ':n releases scheduled'],
        'released' => ['1 release time passed', ':n release times passed'],
        'release_cancelled' => ['1 release cancelled', ':n releases cancelled'],
        'death' => ['1 tenement dead', ':n tenements dead'],
    ];

    /** @param list<array> $events presented events (Register::presentEvent) */
    public function __construct(public WatchArea $area, public array $events, public ?object $run, public bool $preview = false) {}

    public function envelope(): Envelope
    {
        $byType = array_count_values(array_column($this->events, 'type'));
        $parts = [];
        foreach (self::COUNTS as $type => [$one, $many]) {
            if ($n = $byType[$type] ?? 0) {
                $parts[] = $n === 1 ? $one : str_replace(':n', (string) $n, $many);
            }
        }
        $other = array_sum(array_diff_key($byType, self::COUNTS));
        if ($other) {
            $parts[] = $other === 1 ? '1 other change' : "$other other changes";
        }

        return new Envelope(subject: $this->area->name.': '.implode(', ', $parts));
    }

    /** RFC 8058 one-click unsubscribe, which Gmail and Yahoo require of bulk senders. */
    public function headers(): Headers
    {
        return new Headers(text: [
            'List-Unsubscribe' => '<'.$this->area->stopUrl().'>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.watch-area-digest', with: [
            'groups' => $this->groups(),
            'extract' => $this->run?->tenements_extract ? Format::awst($this->run->tenements_extract) : null,
            'mapUrl' => $this->area->mapUrl(),
            'stopUrl' => $this->area->stopUrl(),
            'describe' => $this->area->shape()->describe(),
        ]);
    }

    /** A calendar file for each release still to come. */
    public function attachments(): array
    {
        return array_values(array_filter(array_map(function (array $e) {
            $at = self::releaseAt($e);
            if ($e['type'] !== 'release_scheduled' || ! $at || $at->isPast()) {
                return null;
            }
            $ics = Ics::release($e['fmt_tenid'], (string) ($e['details']['release_id'] ?? ''), $at->format('Y-m-d H:i:s'), url('/tenements/'.$e['slug']));

            return Attachment::fromData(fn () => $ics, 'release-'.$e['slug'].'.ics')->withMime('text/calendar');
        }, $this->events)));
    }

    /**
     * The events as email items, by urgency group, empty groups dropped.
     *
     * @return array<string, list<array{id: string, url: string, meta: string, line: string, holders: string}>>
     */
    public function groups(): array
    {
        $out = [];
        foreach ($this->events as $e) {
            $group = 'other';
            foreach (self::GROUPS as $g => $types) {
                if (in_array($e['type'], $types, true)) {
                    $group = $g;
                    break;
                }
            }
            $names = array_column($e['holders'], 'name');
            $out[$group][] = [
                'id' => $e['fmt_tenid'],
                'url' => url('/tenements/'.$e['slug']),
                'meta' => implode(' · ', array_filter([self::typeName($e['tenement_type']), Format::field($e['fmt_tenid'])])),
                'line' => self::line($e),
                'holders' => implode(', ', array_slice($names, 0, 3)).(count($names) > 3 ? ' +'.(count($names) - 3) : ''),
            ];
        }

        return array_filter(array_merge(array_fill_keys(array_keys(self::GROUPS), []), $out));
    }

    /** "Exploration licence", whether the event carries the type's name or only its code ("E"). */
    public static function typeName(?string $type): string
    {
        return Format::TYPE_NAMES[strtoupper(trim((string) $type))] ?? Format::sentence($type);
    }

    /** What happened, in the register's terms. Never says ground "is open". */
    public static function line(array $e): string
    {
        $d = $e['details'];
        $at = self::releaseAt($e);
        $when = $at ? $at->format('D j M Y, g:ia').' AWST' : null;

        return match ($e['type']) {
            'release_scheduled' => trim('Part releases '.($when ?? 'at a time not yet published').($at ? ' ('.Format::relative($at->format('Y-m-d H:i:s')).')' : '').'. '
                .($d['release_id'] ?? '').(isset($d['area_ha']) ? ', '.number_format((float) $d['area_ha'], 1).' ha' : '').'. '
                .'Applications lodged in the first 15 minutes go to ballot.'),
            'released' => 'Release time passed: '.($when ?? 'unknown').'.',
            'release_cancelled' => 'Release '.($d['release_id'] ?? '').' cancelled.',
            'death' => self::deathLine($d, $e['occurred_at'] ?? null),
            default => trim($e['label'].'. '.$e['summary']),
        };
    }

    private static function deathLine(array $d, ?string $endedAt): string
    {
        $when = $endedAt ? ' on '.Format::localDate($endedAt) : '';
        $kind = $d['kind'] ?? 'ended';
        if ($kind === 'converted') {
            return 'Converted to '.($d['converted_to'] ?? 'another tenement').$when.'. The ground stays held.';
        }
        if ($kind === 'amalgamated') {
            return 'Amalgamated'.(isset($d['amalgamation_id']) ? ' ('.$d['amalgamation_id'].')' : '').$when.'. The ground stays held.';
        }
        $line = Format::sentence($d['death_reason'] ?? 'Recorded dead').$when.'.';
        if (isset($d['uncovered_share'], $d['area_ha'])) {
            $ha = (float) $d['area_ha'] * (float) $d['uncovered_share'];
            $line .= ' '.number_format($ha, $ha < 10 ? 1 : 0).' ha uncovered ('.Format::pct($d['uncovered_share']).' of '.number_format((float) $d['area_ha'], 1).' ha):'
                .' no other live or pending tenement over it on the register, apart from miscellaneous licences and general purpose leases.';
        } elseif (isset($d['uncovered_share'])) {
            $line .= ' '.Format::pct($d['uncovered_share']).' of its area has no other live or pending tenement over it on the register.';
        }

        return $line;
    }

    private static function releaseAt(array $e): ?CarbonImmutable
    {
        try {
            return isset($e['details']['release_at']) ? CarbonImmutable::parse($e['details']['release_at'])->setTimezone('Australia/Perth') : null;
        } catch (Throwable) {
            return null;
        }
    }
}
