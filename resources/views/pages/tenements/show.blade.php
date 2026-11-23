<?php

use App\Support\Format;
use App\Support\Register;
use App\Support\TenementSummary;
use Livewire\Component;

new class extends Component {
    public string $slug;

    protected ?array $data = null;

    public function mount(string $slug): void
    {
        // Kept for this request's render, so the first page load queries once.
        abort_unless($this->data = Register::tenement($slug), 404);
        $this->slug = $slug;
    }

    public function render()
    {
        $data = $this->data ?? Register::tenement($this->slug);

        return $this->view(['data' => $data, 'run' => Register::latestRun()])->title($data['tenement']['fmt_tenid']);
    }
}; ?>

@php
    $t = $data['tenement'];
    $state = TenementSummary::state($t);
    $type = TenementSummary::typeName($t);
    $pageUrl = route('tenements.show', $t['slug']);
    $watchUrl = Route::has('watch.start') ? route('watch.start', ['tenement' => $t['slug']]) : null;
    $location = TenementSummary::locationLine($data);
    $sameHolderApplication = collect($data['overlaps'])->first(fn ($o) => $o['same_holder'] && $o['status'] === 'PENDING');
    $overlapNote = __('Lodgement order is from the register. Priority under the Mining Act can depend on marking out, which no dataset shows. Confirm with the Mining Registrar.');

    // The map: padded fit with a WA locator, release parcels hatched (b-interact draws them).
    $map = [
        'bounds' => $data['bbox'],
        'highlight' => $data['geometry'],
        'context' => [
            'locator' => true,
            'releases' => $data['releases'] ? ['type' => 'FeatureCollection', 'features' => array_map(fn ($r) => [
                'type' => 'Feature',
                'geometry' => $r['geometry'],
                'properties' => ['rlesid' => $r['rlesid'], 'release_at' => $r['release_local']],
            ], $data['releases'])] : null,
        ],
    ];

    // The home map centred here with freed ground on (dead tenements, T8).
    $freedUrl = null;
    if ($t['dead'] && ($data['freed'] ?? false) && $data['bbox']) {
        [$w, $s, $e, $n] = $data['bbox'];
        $zoom = max(8, min(14, (int) floor(log(360 * 600 / (512 * max($e - $w, $n - $s, 0.0001)), 2))));
        $freedUrl = url('/').'#map='.$zoom.'/'.round(($s + $n) / 2, 5).'/'.round(($w + $e) / 2, 5).'&l=tenements,pending,dead,freed';
    }
@endphp

<div class="space-y-10">
    {{-- What it is, who holds it, how old it is, where it is (Build spec 3). --}}
    <header class="space-y-3" x-data>
        <x-eyebrow :dot="Format::STATUS_COLOURS[Format::statusKey($t['status'])]">{{ TenementSummary::eyebrow($data) }}</x-eyebrow>

        <div class="flex flex-wrap items-center gap-x-2 gap-y-2">
            <flux:heading size="xl" level="1" class="!text-3xl !font-extrabold tracking-tight tabular-nums">{{ $t['fmt_tenid'] }}</flux:heading>
            <flux:button size="xs" variant="subtle" icon="clipboard-document" :tooltip="__('Copy ID')" :aria-label="__('Copy ID')"
                x-on:click="navigator.clipboard.writeText({{ Js::from($t['fmt_tenid']) }}); $flux.toast({{ Js::from(__('Copied :id', ['id' => $t['fmt_tenid']])) }})" />
            <x-status-badge :status="$t['status']" />
            @if ($data['releases'])
                <x-status-badge status="release" />
            @endif
            @if ($t['special'])
                <flux:badge size="sm" color="zinc">{{ __('Special prospecting licence (can sit inside a live exploration licence)') }}</flux:badge>
            @endif
        </div>

        {{-- Parts on unindented lines: Blade drops the newline after each directive, so no stray spaces. --}}
        <p class="max-w-3xl text-lg leading-snug text-pretty text-zinc-900 dark:text-zinc-100" data-test="headline">
@foreach (TenementSummary::headline($data) as $part)
@if (is_array($part))<a href="{{ route('holders.show', $part['slug']) }}" wire:navigate class="font-semibold underline decoration-zinc-300 underline-offset-4 hover:decoration-zinc-900 dark:decoration-zinc-600 dark:hover:decoration-white">{{ $part['name'] }}</a>@else{{ $part }}@endif
@endforeach
        </p>

        {{-- Dead tenements answer "is any of it free today?" straight after the headline (T8). --}}
        @if ($t['dead'] && $data['uncovered'] !== null)
            <p class="max-w-3xl rounded-lg bg-zinc-100 px-3 py-2 text-sm dark:bg-zinc-900" data-test="dead-ground">
                @if ((float) $data['uncovered'] < 0.005)
                    {{ __('Live or pending tenements cover all of this ground on the register today.') }}
                @else
                    <strong class="tabular-nums">{{ (float) $data['uncovered'] > 0.995 ? __('All') : Format::pct($data['uncovered']) }}</strong>
                    {{ __('of this ground has no live or pending tenement over it on the register today.') }}
                @endif
                <span class="text-zinc-500 dark:text-zinc-400">{{ __('L and G licences and special prospecting licences are not counted.') }}</span>
                @if ($freedUrl)
                    <flux:link :href="$freedUrl">{{ __('See freed ground on the map') }}</flux:link>.
                @endif
                {{ __('Confirm in TENGRAPH and with the Mining Registrar before acting.') }}
            </p>
        @endif

        @if ($location)
            <p class="flex items-center gap-1 text-sm text-zinc-500 dark:text-zinc-400">
                <flux:icon.map-pin variant="micro" />{{ $location }}
            </p>
        @endif

        <div class="flex flex-wrap items-center gap-2 pt-1">
            @if ($watchUrl)
                <flux:button size="sm" variant="primary" icon="bell-alert" :href="$watchUrl" wire:navigate>{{ __('Watch this tenement') }}</flux:button>
            @endif
            <flux:button size="sm" icon-trailing="arrow-top-right-on-square" :href="Format::TENGRAPH_URL" target="_blank" rel="noreferrer">TENGRAPH</flux:button>
            <flux:button size="sm" icon="link" x-on:click="navigator.clipboard.writeText({{ Js::from($pageUrl) }}); $flux.toast({{ Js::from(__('Link copied')) }})">{{ __('Copy link') }}</flux:button>
            <flux:button size="sm" icon="share" style="display: none" x-show="'share' in navigator"
                x-on:click="navigator.share({ title: {{ Js::from($t['fmt_tenid']) }}, text: {{ Js::from(TenementSummary::headlineText($data)) }}, url: {{ Js::from($pageUrl) }} }).catch(() => {})">{{ __('Share') }}</flux:button>
            <x-pro-lock ref="export">{{ __('Export GPX / KML') }}</x-pro-lock>
        </div>
    </header>

    {{-- Key facts beside the map on desktop; on phones the map follows the facts and the callouts follow the map. --}}
    <div class="grid gap-6 md:grid-cols-5">
        <dl class="grid grid-cols-[6.5rem_minmax(0,1fr)] content-start gap-x-4 gap-y-2 text-sm md:col-span-2 wrap-anywhere" data-test="facts">
            <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Type') }}</dt>
            <dd>{{ $type }}</dd>

            <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Status') }}</dt>
            <dd class="flex flex-wrap items-center gap-2">
                <x-status-badge :status="$t['status']" />
                @if ($state === 'expired')
                    <span class="text-amber-700 dark:text-amber-400">{{ __('past expiry') }}</span>
                @elseif ($state === 'dead' && $t['dead']['reason'])
                    <span>{{ Format::sentence($t['dead']['reason']) }}</span>
                @endif
            </dd>

            @if ($field = TenementSummary::fieldLabel($t['fmt_tenid']))
                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Field') }}</dt>
                <dd>{{ $field }}</dd>
            @endif

            @if ($area = TenementSummary::areaFact($t))
                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Area') }}</dt>
                <dd class="tabular-nums">{{ $area }}</dd>
            @endif

            @if ($t['survey_status'])
                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Survey') }}</dt>
                <dd>{{ Format::sentence($t['survey_status']) }}</dd>
            @endif

            @if ($t['applied_at'])
                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Applied') }}</dt>
                <dd class="tabular-nums">{{ Format::when($t['applied_at']) }} <span class="text-zinc-500 dark:text-zinc-400">({{ Format::relative($t['applied_at']) }})</span></dd>
            @endif

            @if ($t['granted_at'])
                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Granted') }}</dt>
                <dd class="tabular-nums">{{ Format::localDate($t['granted_at']) }} <span class="text-zinc-500 dark:text-zinc-400">({{ TenementSummary::afterApplication($t['applied_at'], $t['granted_at']) }})</span></dd>
            @endif

            @if ($t['dead'])
                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Ended') }}</dt>
                <dd class="tabular-nums">{{ Format::when($t['dead']['ended_at']) }} <span class="text-zinc-500 dark:text-zinc-400">({{ Format::relative($t['dead']['ended_at']) }})</span></dd>
            @elseif ($t['ends_at'])
                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Expires') }}</dt>
                <dd @class(['tabular-nums', 'font-medium text-amber-700 dark:text-amber-400' => $state === 'expired' || TenementSummary::expiresSoon($t)]) data-test="expires">
                    {{ Format::localDate($t['ends_at']) }}
                    ({{ $state === 'expired' ? __('passed, still live on the register') : Format::relative($t['ends_at']) }})
                </dd>
            @endif

            <dt class="text-zinc-500 dark:text-zinc-400">{{ count($t['holders']) === 1 ? __('Holder') : __('Holders') }}</dt>
            <dd>
                @forelse ($t['holders'] as $h)
                    <div><flux:link :href="route('holders.show', $h['slug'])" wire:navigate>{{ $h['name'] }}</flux:link></div>
                @empty
                    {{ __('None recorded') }}
                @endforelse
            </dd>

            @if ($run?->tenements_extract)
                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Extract') }}</dt>
                <dd class="tabular-nums">{{ __('Register extract of :when AWST', ['when' => Format::awst($run->tenements_extract)]) }}</dd>
            @endif
        </dl>

        <div class="md:col-span-3 md:row-span-2 md:row-start-1 md:col-start-3">
            <div wire:ignore x-data="tenementMap({{ Js::from($map) }})" class="h-80 w-full overflow-hidden rounded-lg md:sticky md:top-4 md:h-[480px]">
                <div x-ref="map" class="h-full w-full"></div>
            </div>
        </div>

        {{-- What may happen next, and what limits the ground. --}}
        <div class="space-y-3 md:col-span-2 empty:hidden">
            @foreach ($data['releases'] as $r)
                <flux:callout color="fuchsia" icon="calendar-days" data-test="release">
                    <flux:callout.heading>{{ __('Part of this licence is due for compulsory release') }}</flux:callout.heading>
                    <flux:callout.text>
                        {{ $r['rlesid'] }}: {{ $r['ha'] !== null ? TenementSummary::hectares($r['ha']).' ' : '' }}{{ __('releases :when AWST', ['when' => \Carbon\CarbonImmutable::parse($r['release_local'])->format('D j M Y, g:ia')]) }}
                        <strong>({{ Format::relative($r['release_local']) }})</strong>.
                        {{ __('Applications lodged in the first 15 minutes go to ballot. Confirm in TENGRAPH and with the Mining Registrar.') }}
                    </flux:callout.text>
                    <x-slot name="actions">
                        <flux:button size="sm" icon="calendar" :href="route('tenements.release-ics', $t['slug'])">{{ __('Add to calendar') }}</flux:button>
                    </x-slot>
                </flux:callout>
            @endforeach

            @if ($state === 'expired')
                <flux:callout color="amber" icon="clock" data-test="past-expiry">
                    <flux:callout.heading>{{ __('Past expiry, still live') }}</flux:callout.heading>
                    <flux:callout.text>
                        {{ __('The expiry date passed on :date, but the register still lists it as live.', ['date' => Format::localDate($t['ends_at'])]) }}
                        @if ($sameHolderApplication)
                            {{ __('A :type application by the same holder,', ['type' => lcfirst(TenementSummary::typeName($sameHolderApplication))]) }}
                            <flux:link :href="route('tenements.show', $sameHolderApplication['slug'])" wire:navigate>{{ $sameHolderApplication['fmt_tenid'] }}</flux:link>{{ $sameHolderApplication['applied_at'] ? ', '.__('lodged :date', ['date' => Format::localDate($sameHolderApplication['applied_at'])]) : '' }},
                            {{ __('covers :share of it.', ['share' => Format::pct($sameHolderApplication['share'])]) }}
                        @endif
                    </flux:callout.text>
                </flux:callout>
            @endif

            @if ($data['restrictions'])
                <flux:callout icon="no-symbol" data-test="restrictions">
                    <flux:callout.heading>{{ __('Land restrictions over this ground') }}</flux:callout.heading>
                    <flux:callout.text>
                        <ul class="space-y-1">
                            @foreach ($data['restrictions'] as $r)
                                <li>
                                    <span class="font-medium">{{ TenementSummary::RESTRICTION_KINDS[$r['kind']] ?? Format::sentence($r['kind']) }}{{ $r['land_id'] ? ' '.$r['land_id'] : '' }}</span>
                                    · {{ TenementSummary::restrictionText($r) }}
                                    · {{ __(':share of this ground', ['share' => Format::pct($r['share'])]) }}
                                </li>
                            @endforeach
                        </ul>
                    </flux:callout.text>
                </flux:callout>
            @endif

            @foreach ($data['actions'] as $a)
                <flux:callout icon="arrows-pointing-in" data-test="pending-action">
                    <flux:callout.text>
                        {{ $a['kind'] === 'amalgamation' ? __('Amalgamation application :id pending', ['id' => $a['id']]) : __('Restoration application :id pending', ['id' => $a['id']]) }}{{ $a['lodged_at'] ? ', '.__('lodged :date', ['date' => Format::awst($a['lodged_at'], false)]) : '' }}.
                    </flux:callout.text>
                </flux:callout>
            @endforeach

            @if ($data['wait'])
                @php($w = $data['wait'])
                <flux:callout icon="clock" data-test="wait">
                    <flux:callout.text>
                        {{ $w['scope'] === 'field'
                            ? __(':types in :field granted since 2020 took a median of :months months (:n grants).', ['types' => Str::plural($type), 'field' => TenementSummary::fieldPhrase($t['fmt_tenid']), 'months' => $w['median_months'], 'n' => number_format($w['grants'])])
                            : __(':types granted across WA since 2020 took a median of :months months (:n grants).', ['types' => Str::plural($type), 'months' => $w['median_months'], 'n' => number_format($w['grants'])]) }}
                        {{ __('This one has waited :months.', ['months' => trans_choice(':count month|:count months', $w['waited_months'])]) }}
                        <span class="opacity-75">{{ __('This describes past grants, not a forecast.') }}</span>
                    </flux:callout.text>
                </flux:callout>
            @endif
        </div>
    </div>

    {{-- Overlaps (O3), open; for a dead tenement, the tenements over its ground now. --}}
    <section class="space-y-3" data-test="overlaps">
        <flux:heading size="lg" level="2">
            {{ $t['dead'] ? __('Tenements over this ground now') : __('Overlapping tenements') }}
            <span class="font-normal text-zinc-500">({{ count($data['overlaps']) }})</span>
        </flux:heading>
        @if ($data['overlaps'])
            <div class="hidden grid-cols-[7rem_minmax(0,1.1fr)_minmax(0,1.6fr)_7.5rem_minmax(0,1.6fr)] gap-x-4 border-b border-zinc-200 pb-1 text-xs font-medium text-zinc-500 sm:grid dark:border-zinc-700 dark:text-zinc-400">
                <div>{{ __('Tenement') }}</div><div>{{ __('Type and status') }}</div><div>{{ __('Holder') }}</div><div class="text-right">{{ $t['dead'] ? __('Share of it') : __('Share of this one') }}</div><div>{{ __('Lodged') }}</div>
            </div>
            <ul class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @foreach ($data['overlaps'] as $o)
                    <li wire:key="overlap-{{ $o['slug'] }}" class="grid grid-cols-[minmax(0,1fr)_auto] gap-x-4 gap-y-0.5 py-2 text-sm sm:grid-cols-[7rem_minmax(0,1.1fr)_minmax(0,1.6fr)_7.5rem_minmax(0,1.6fr)] sm:items-baseline">
                        <div><flux:link :href="route('tenements.show', $o['slug'])" wire:navigate class="font-medium tabular-nums">{{ $o['fmt_tenid'] }}</flux:link></div>
                        <div class="flex flex-wrap items-center gap-1.5 max-sm:col-span-2">
                            <span>{{ TenementSummary::typeName($o) }}</span>
                            @if ($o['status'])
                                <x-status-badge :status="$o['status']" />
                            @endif
                            @if ($o['can_overlap'])
                                <flux:badge size="sm" color="sky" inset="top bottom">{{ __('Can overlap') }}</flux:badge>
                            @endif
                        </div>
                        <div class="wrap-anywhere max-sm:col-span-2">
                            {{ collect($o['holders'])->pluck('name')->implode('; ') ?: __('None recorded') }}
                            @if ($o['same_holder'])
                                <flux:badge size="sm" color="zinc" inset="top bottom">{{ __('Same holder') }}</flux:badge>
                            @endif
                        </div>
                        <div class="text-right tabular-nums max-sm:col-start-2 max-sm:row-start-1">{{ Format::pct($o['share']) }}<span class="sm:hidden"> {{ $t['dead'] ? __('of it') : __('of this one') }}</span></div>
                        <div class="text-zinc-600 tabular-nums max-sm:col-span-2 dark:text-zinc-300">
                            {{ Format::localDate($o['applied_at']) }}@if ($rel = TenementSummary::lodgedRelative($o['applied_at'], $t['applied_at']))<span class="text-zinc-500 dark:text-zinc-400"> · {{ $rel }}</span>@endif
                        </div>
                    </li>
                @endforeach
            </ul>
            <flux:text class="text-xs">{{ $overlapNote }}</flux:text>
        @else
            <flux:text class="text-sm">
                {{ $t['dead'] ? __('No live or pending tenement covers any of this ground on the register today.') : __('No other live or pending tenement covers any of this ground.') }}
            </flux:text>
        @endif
    </section>

    <div class="grid gap-10 lg:grid-cols-2">
        {{-- Lifecycle (T5): applied, granted, changes seen, release, expiry or end, today. --}}
        <section class="space-y-3" data-test="lifecycle">
            <flux:heading size="lg" level="2">{{ __('Lifecycle') }}</flux:heading>
            <flux:timeline>
                @foreach (TenementSummary::lifecycle($data) as $item)
                    <flux:timeline.item :status="['past' => 'complete', 'today' => 'current', 'future' => 'incomplete'][$item['when']]">
                        <flux:timeline.indicator>
                            @if ($item['when'] === 'today')
                                <flux:icon.map-pin variant="micro" />
                            @endif
                        </flux:timeline.indicator>
                        <flux:timeline.content>
                            <div class="text-sm font-medium">{{ $item['label'] }}</div>
                            <div class="text-xs text-zinc-500 tabular-nums dark:text-zinc-400">{{ $item['detail'] }}</div>
                        </flux:timeline.content>
                    </flux:timeline.item>
                @endforeach
            </flux:timeline>
        </section>

        {{-- Changes Pegwatch saw in the nightly diffs (e-activity writes the empty states). --}}
        <section class="space-y-3" data-test="history">
            <flux:heading size="lg" level="2">{{ __('Changes seen') }}</flux:heading>
            <x-event-list :events="$data['events']" context="tenement" :subject="$t['fmt_tenid']" />
        </section>
    </div>

    <div class="grid gap-10 lg:grid-cols-2">
        {{-- Holder context (T6). --}}
        <section class="space-y-3" data-test="holder-context">
            <flux:heading size="lg" level="2">{{ count($t['holders']) === 1 ? __('Holder') : __('Holders') }}</flux:heading>
            <ul class="space-y-1 text-sm">
                @foreach ($t['holders'] as $h)
                    @php($c = $data['holder_context']['counts'][$h['name']] ?? null)
                    <li>
                        <flux:link :href="route('holders.show', $h['slug'])" wire:navigate class="font-medium">{{ $h['name'] }}</flux:link>
                        <span class="text-zinc-500 tabular-nums dark:text-zinc-400">
                            @if ($c)
                                · {{ trans_choice(':count tenement|:count tenements', $c['total']) }} · {{ __(':count pending', ['count' => $c['pending']]) }}
                            @else
                                · {{ __('no live or pending tenements') }}
                            @endif
                        </span>
                    </li>
                @endforeach
            </ul>
            @if ($data['holder_context']['nearby'])
                <div class="text-sm">
                    <div class="mb-1 text-zinc-500 dark:text-zinc-400">
                        {{ __('Their other tenements within 20 km') }}
                        ({{ $data['holder_context']['nearby_total'] }})
                    </div>
                    <ul class="flex flex-wrap gap-x-3 gap-y-1">
                        @foreach ($data['holder_context']['nearby'] as $n)
                            <li class="tabular-nums">
                                <flux:link :href="route('tenements.show', $n['slug'])" wire:navigate>{{ $n['fmt_tenid'] }}</flux:link>
                                <span class="text-zinc-500 dark:text-zinc-400">{{ Format::lower($n['status']) }}, {{ $n['km'] < 0.1 ? __('adjoining') : $n['km'].' km' }}</span>
                            </li>
                        @endforeach
                        @if ($data['holder_context']['nearby_total'] > count($data['holder_context']['nearby']))
                            <li class="text-zinc-500 dark:text-zinc-400">{{ __('and :n more', ['n' => $data['holder_context']['nearby_total'] - count($data['holder_context']['nearby'])]) }}</li>
                        @endif
                    </ul>
                </div>
            @endif
        </section>

        {{-- Neighbours within 100 m (O7), grouped by holder, newest application first. --}}
        <section class="space-y-3" data-test="neighbours">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <flux:heading size="lg" level="2">{{ __('Neighbours within 100 m') }}</flux:heading>
                <x-pro-lock ref="neighbours">{{ __('Watch my neighbours') }}</x-pro-lock>
            </div>
            @forelse ($data['neighbours'] as $g)
                <div class="text-sm">
                    <div class="font-medium wrap-anywhere">
                        {{ collect($g['holders'])->pluck('name')->implode('; ') ?: __('No holder recorded') }}
                        @if ($g['same_holder'])
                            <flux:badge size="sm" color="zinc" inset="top bottom">{{ __('Same holder') }}</flux:badge>
                        @endif
                    </div>
                    <ul class="flex flex-wrap gap-x-3 gap-y-0.5">
                        @foreach ($g['tenements'] as $n)
                            <li class="tabular-nums">
                                <flux:link :href="route('tenements.show', $n['slug'])" wire:navigate>{{ $n['fmt_tenid'] }}</flux:link>
                                <span class="text-zinc-500 dark:text-zinc-400">{{ Format::lower($n['status']) }}{{ $n['applied_at'] ? ', '.__('applied :date', ['date' => Format::localDate($n['applied_at'])]) : '' }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @empty
                <flux:text class="text-sm">{{ __('No other live or pending tenement within 100 m.') }}</flux:text>
            @endforelse
        </section>
    </div>

    {{-- Ground history (T4): dead tenements that covered at least 10% of this ground. --}}
    <section class="space-y-3" data-test="held-before">
        <flux:heading size="lg" level="2">{{ __('Held before') }} <span class="font-normal text-zinc-500">({{ count($data['held_before']) }})</span></flux:heading>
        @if ($data['held_before'])
            <ul class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @foreach ($data['held_before'] as $d)
                    <li wire:key="held-{{ $d['slug'] }}" class="grid grid-cols-[minmax(0,1fr)_auto] gap-x-4 gap-y-0.5 py-2 text-sm sm:grid-cols-[7rem_minmax(0,1.1fr)_minmax(0,1.6fr)_7.5rem_minmax(0,1.6fr)] sm:items-baseline">
                        <div><flux:link :href="route('tenements.show', $d['slug'])" wire:navigate class="font-medium tabular-nums">{{ $d['fmt_tenid'] }}</flux:link></div>
                        <div class="max-sm:col-span-2">{{ TenementSummary::typeName($d) }}</div>
                        <div class="wrap-anywhere max-sm:col-span-2">{{ collect($d['holders'])->pluck('name')->implode('; ') ?: __('None recorded') }}</div>
                        <div class="text-right tabular-nums max-sm:col-start-2 max-sm:row-start-1">{{ Format::pct($d['share']) }}<span class="sm:hidden"> {{ __('of this ground') }}</span></div>
                        <div class="text-zinc-600 tabular-nums max-sm:col-span-2 dark:text-zinc-300">
                            {{ collect([$d['started_at'] ? Format::localDate($d['started_at']) : null, Format::localDate($d['ended_at'])])->filter()->implode(' to ') }}
                            <span class="text-zinc-500 dark:text-zinc-400">· {{ Format::sentence($d['reason']) ?: __('Ended') }}</span>
                        </div>
                    </li>
                @endforeach
            </ul>
        @else
            <flux:text class="text-sm">{{ __('No dead tenement on record covered 10% or more of this ground.') }}</flux:text>
        @endif
    </section>
</div>
