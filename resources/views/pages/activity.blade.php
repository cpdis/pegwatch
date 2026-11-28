<?php

use App\Support\FeedFilter;
use App\Support\Format;
use App\Support\Register;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Activity')] class extends Component {
    public const PER_PAGE = 50;

    /** The change types with a chip; others still filter through the URL. */
    public const CHIPS = [
        'application' => 'Applications', 'grant' => 'Grants', 'death' => 'Deaths',
        'holder_change' => 'Holder changes', 'shrink' => 'Area cuts', 'release_scheduled' => 'Releases scheduled',
    ];

    /** Untyped: query strings are untrusted, and FeedFilter drops what does not parse. */
    #[Url]
    public $types = [];

    #[Url]
    public $kinds = [];

    #[Url]
    public $field = '';

    #[Url]
    public $holder = '';

    /** Date range picked in AWST, as ['start' => 'Y-m-d', 'end' => 'Y-m-d']. */
    #[Url]
    public $range = [];

    #[Url]
    public $page = 1;

    /** The old single-type parameter, folded into $types. */
    #[Url]
    public $type = null;

    public function mount(): void
    {
        if (is_string($this->type) && array_key_exists($this->type, Register::EVENT_LABELS)) {
            $this->types = [$this->type];
        }
        $this->type = null;
    }

    public function updated(string $name): void
    {
        if ($name !== 'page') {
            $this->page = 1;
        }
    }

    public function clearFilters(): void
    {
        $this->reset('types', 'kinds', 'field', 'holder', 'range', 'page');
    }

    public function with(): array
    {
        $filter = FeedFilter::from(['types' => $this->types, 'kinds' => $this->kinds, 'field' => $this->field,
            'holder' => $this->holder, 'range' => $this->range]);
        $page = is_numeric($this->page) ? min(10000, max(1, (int) $this->page)) : 1;
        $items = Register::feed($filter, self::PER_PAGE + 1, ($page - 1) * self::PER_PAGE);
        $events = array_slice($items, 0, self::PER_PAGE);

        // Counts for each day on this page, and for the summary strip.
        $days = $events ? Register::feedCounts($filter, substr(end($events)['at'], 0, 10), substr($events[0]['at'], 0, 10)) : [];
        $today = CarbonImmutable::now('Australia/Perth');
        [$from, $to] = [$filter['from'] ?? $today->subDays(6)->toDateString(), $filter['to'] ?? $today->toDateString()];
        $totals = [];
        foreach (Register::feedCounts($filter, $from, $to) as $byType) {
            foreach ($byType as $type => $n) {
                $totals[$type] = ($totals[$type] ?? 0) + $n;
            }
        }

        $markers = array_values(array_map(fn ($e) => [
            'id' => $e['key'], 'point' => $e['point'], 'colour' => Format::eventColour($e['type']),
        ], array_filter($events, fn ($e) => $e['point'] !== null)));
        $this->dispatch('feed-markers', markers: $markers);

        $query = array_filter(['types' => $filter['types'] ?? null, 'kinds' => $filter['kinds'] ?? null,
            'field' => $filter['field'] ?? null, 'holder' => $filter['holder'] ?? null,
            'range' => isset($filter['from']) || isset($filter['to']) ? array_filter(['start' => $filter['from'] ?? null, 'end' => $filter['to'] ?? null]) : null]);

        return [
            'events' => $events,
            'days' => $days,
            'hasMore' => count($items) > self::PER_PAGE,
            'pageNo' => $page,
            'filtered' => $filter !== [],
            'markers' => $markers,
            'totals' => $totals,
            'window' => isset($filter['from']) || isset($filter['to'])
                ? __(':from to :to', ['from' => Format::localDate($from), 'to' => Format::localDate($to)])
                : __('Last 7 days'),
            'rss' => route('activity.rss', $query),
            'fields' => collect(config('mineral_fields'))->map(fn ($f, $n) => $n.' '.($f['district'] ?? $f['field']))->all(),
        ];
    }
}; ?>

@php
    $stats = ['application' => __('Applications'), 'grant' => __('Grants'), 'death' => __('Deaths'), 'release_scheduled' => __('Releases scheduled')];
    $other = array_sum(array_diff_key($totals, $stats));
@endphp

<div class="mx-auto max-w-7xl">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <x-eyebrow>{{ __('Activity') }}</x-eyebrow>
            <flux:heading size="xl" level="1" class="mt-1">{{ __('What changed on the register') }}</flux:heading>
            <flux:text class="mt-1 max-w-2xl">{{ __('Changes Pegwatch saw between one night\'s extract and the next, what the register\'s own dates say happened, and applications in the department\'s Mining Notices that the extract does not have yet. Newest first.') }}</flux:text>
        </div>
        <flux:button size="sm" variant="ghost" icon="rss" :href="$rss" target="_blank">{{ __('RSS for this view') }}</flux:button>
    </div>

    {{-- The summary strip: counts for the picked dates, or the last 7 days. --}}
    <section class="mt-5" aria-label="{{ __('Counts') }}">
        <flux:text class="text-xs font-medium text-zinc-500">{{ $window }}</flux:text>
        <dl class="mt-1 grid grid-cols-2 gap-2 sm:grid-cols-5">
            @foreach ($stats as $type => $label)
                <div class="rounded-lg border border-zinc-200 px-3 py-2 dark:border-zinc-700">
                    <dt class="flex items-center gap-1.5 text-xs text-zinc-500 dark:text-zinc-400">
                        <span class="size-2 rounded-full dark:ring-1 dark:ring-white/60" style="background: {{ Format::eventColour($type) }}"></span>{{ $label }}
                    </dt>
                    <dd class="text-2xl font-semibold tabular-nums">{{ number_format($totals[$type] ?? 0) }}</dd>
                </div>
            @endforeach
            <div class="rounded-lg border border-zinc-200 px-3 py-2 dark:border-zinc-700">
                <dt class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Other changes') }}</dt>
                <dd class="text-2xl font-semibold tabular-nums">{{ number_format($other) }}</dd>
            </div>
        </dl>
    </section>

    <section class="mt-5 space-y-3" aria-label="{{ __('Filters') }}">
        <flux:checkbox.group wire:model.live="types" variant="pills" :label="__('Change')" class="flex flex-wrap gap-2">
            @foreach ($this::CHIPS as $key => $label)
                <flux:checkbox :value="$key" :label="__($label)" class="max-sm:min-h-11 max-sm:px-3" />
            @endforeach
        </flux:checkbox.group>
        <flux:checkbox.group wire:model.live="kinds" variant="pills" :label="__('Tenement type')" class="flex flex-wrap gap-2">
            @foreach (Format::TYPE_NAMES as $code => $name)
                <flux:checkbox :value="$code" :label="__($name)" class="max-sm:min-h-11 max-sm:px-3" />
            @endforeach
            <flux:checkbox value="other" :label="__('Other')" class="max-sm:min-h-11 max-sm:px-3" />
        </flux:checkbox.group>
        <div class="grid gap-3 sm:grid-cols-3">
            <flux:select wire:model.live="field" variant="listbox" searchable clearable :label="__('Mineral field')" :placeholder="__('Any field')">
                @foreach ($fields as $n => $label)
                    <flux:select.option :value="$n">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:input wire:model.live.debounce.400ms="holder" :label="__('Holder')" :placeholder="__('Any holder')" icon="magnifying-glass" clearable />
            <flux:date-picker mode="range" wire:model.live="range" :label="__('Dates')" :placeholder="__('Any date')"
                              start-day="1" max="today" with-presets presets="last7Days last30Days thisMonth lastMonth" clearable />
        </div>
        @if ($filtered)
            <flux:button size="sm" variant="subtle" icon="x-mark" wire:click="clearFilters">{{ __('Clear filters') }}</flux:button>
        @endif
    </section>

    <div class="mt-4 grid gap-6 lg:grid-cols-[minmax(0,1fr)_26rem]" x-data="{ showMap: false }">
        <div class="min-w-0">
            <div class="lg:hidden">
                <flux:button size="sm" icon="map" x-on:click="showMap = ! showMap" x-text="showMap ? @js(__('Hide the map')) : @js(__('Show these on a map'))">{{ __('Show these on a map') }}</flux:button>
            </div>
            <x-event-list :events="$events" context="activity" :filtered="$filtered" :days="$days" class="mt-2" />

            <div class="mt-4 flex gap-2">
                @if ($pageNo > 1)
                    <flux:button size="sm" icon="arrow-left" wire:click="$set('page', {{ $pageNo - 1 }})">{{ __('Newer') }}</flux:button>
                @endif
                @if ($hasMore)
                    <flux:button size="sm" icon-trailing="arrow-right" wire:click="$set('page', {{ $pageNo + 1 }})">{{ __('Older') }}</flux:button>
                @endif
            </div>
        </div>

        {{-- A map of the rows on this page. The rows send event-hover; the markers listen. --}}
        <aside class="order-first lg:order-none" x-bind:class="showMap ? '' : 'max-lg:hidden'" aria-label="{{ __('Map of these changes') }}">
            <div wire:ignore class="h-72 overflow-hidden rounded-lg border border-zinc-200 lg:sticky lg:top-4 lg:h-[calc(100dvh-10rem)] dark:border-zinc-700">
                <div class="h-full w-full"
                     x-data="tenementMap({ markers: @js($markers), layers: { tenements: false, pending: false, releases: false } })"
                     x-on:event-hover.window="hovered = $event.detail.id"
                     x-on:feed-markers.window="markers = $event.detail.markers; sync()">
                    <div x-ref="map" class="h-full w-full"></div>
                </div>
            </div>
            <flux:text class="mt-1 text-xs">{{ __('Changes on this page with a place on the map. Mining Notices have none until the register has them.') }}</flux:text>
        </aside>
    </div>
</div>
