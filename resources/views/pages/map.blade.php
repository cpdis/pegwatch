<?php

use App\Support\Format;
use App\Support\Register;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The home map and its panel: a live view of what is in sight. The map
 * (resources/js/map) sends map-moved; the panel answers with counts and lists
 * for that view, and sends map-filter, map-layers, map-select and map-fly
 * back (contracts.md). On phones the panel is a bottom sheet (resources/js/panel.js).
 */
new #[Layout('layouts::map'), Title('WA tenement map')] class extends Component {
    public const APPLIED = ['30d' => 'Last 30 days', '3m' => 'Last 3 months', '12m' => 'Last 12 months', 'older' => 'Over 2 years ago'];

    public const EXPIRES = ['3' => 'Within 3 months', '6' => 'Within 6 months', '12' => 'Within 12 months'];

    /** Size presets: [min ha, max ha, label]. */
    public const SIZES = [
        'lt10' => [null, 10, 'Under 10 ha'], '10-200' => [10, 200, '10 to 200 ha'],
        '200-1000' => [200, 1000, '200 to 1,000 ha'], 'gt1000' => [1000, null, 'Over 1,000 ha'],
    ];

    public const STATUSES = ['LIVE' => 'Live', 'PENDING' => 'Pending'];

    /** The view [w, s, e, n] the lists follow; null while the view holds all of WA. */
    public ?array $bounds = null;

    public string $tab = 'changes';

    /** Tenement types shown: the legend's toggles and the Type chip. "other" is every other type code. */
    public array $kinds = [...Register::KINDS, 'other'];

    public array $statuses = ['LIVE', 'PENDING'];

    public string $applied = '';

    public string $expires = '';

    public string $size = '';

    /** Called by the panel on each map-moved while "Update as I move the map" is on. */
    public function moved(mixed $bounds): void
    {
        if (! is_array($bounds) || count($bounds) !== 4 || array_filter($bounds, fn ($v) => ! is_numeric($v))) {
            return;
        }
        [$w, $s, $e, $n] = array_map('floatval', array_values($bounds));
        $this->bounds = $w <= 113 && $s <= -35 && $e >= 129 && $n >= -14 ? null
            : [round(max(-180, $w), 4), round(max(-90, $s), 4), round(min(180, $e), 4), round(min(90, $n), 4)];
    }

    public function updated(string $name): void
    {
        if (in_array(explode('.', $name)[0], ['kinds', 'statuses', 'applied', 'expires', 'size'], true)) {
            $this->dispatch('map-filter', ...$this->mapFilter());
        }
    }

    public function clearFilters(): void
    {
        $this->reset('kinds', 'statuses', 'applied', 'expires', 'size');
        $this->dispatch('map-filter', ...$this->mapFilter());
    }

    /** The panel's filter for Register::countsInBounds() and tenementsInBounds(). */
    private function panelFilter(): array
    {
        $kinds = array_values(array_intersect([...Register::KINDS, 'other'], $this->kinds));
        $statuses = array_values(array_intersect(array_keys(self::STATUSES), $this->statuses));
        $today = CarbonImmutable::now('Australia/Perth');
        [$from, $to] = match ($this->applied) {
            '30d' => [$today->subDays(30)->toDateString(), null],
            '3m' => [$today->subMonths(3)->toDateString(), null],
            '12m' => [$today->subMonths(12)->toDateString(), null],
            'older' => [null, $today->subYears(2)->toDateString()],
            default => [null, null],
        };
        [$min, $max] = self::SIZES[$this->size] ?? [null, null];

        // An empty choice shows nothing; "none" matches no type or status.
        return [
            'kinds' => count($kinds) === count(Register::KINDS) + 1 ? [] : ($kinds ?: ['none']),
            'statuses' => count($statuses) === 2 ? [] : ($statuses ?: ['none']),
            'appliedFrom' => $from, 'appliedTo' => $to,
            'expiresWithinMonths' => array_key_exists($this->expires, self::EXPIRES) ? (int) $this->expires : null,
            'minHa' => $min, 'maxHa' => $max,
        ];
    }

    /** The same filter as contracts.md's map-filter detail: type codes, with "other" spelled out. */
    private function mapFilter(): array
    {
        $f = $this->panelFilter();
        $types = null;
        if ($f['kinds']) {
            $types = array_values(array_intersect($f['kinds'], Register::KINDS));
            if (in_array('other', $f['kinds'], true)) {
                $types = [...$types, ...Register::otherKinds()];
            }
        }

        return [
            'types' => $types,
            'statuses' => $f['statuses'] ? array_values(array_intersect($f['statuses'], array_keys(self::STATUSES))) : null,
            'appliedFrom' => $f['appliedFrom'], 'appliedTo' => $f['appliedTo'],
            'expiresWithinMonths' => $f['expiresWithinMonths'],
            'minHa' => $f['minHa'], 'maxHa' => $f['maxHa'],
        ];
    }

    public function with(): array
    {
        $filter = $this->panelFilter();
        $tab = in_array($this->tab, ['changes', 'tenements', 'releases'], true) ? $this->tab : 'changes';
        $deadSince = Register::deadHistoryStart();

        return [
            'counts' => Register::countsInBounds($this->bounds, $filter),
            'tabName' => $tab,
            'changes' => $tab === 'changes' ? Register::feed(array_filter(['bbox' => $this->bounds, 'kinds' => $filter['kinds']]), 30) : [],
            'tenements' => $tab === 'tenements' ? Register::tenementsInBounds($this->bounds, $filter, 40) : [],
            'releases' => $tab === 'releases' ? Register::upcomingReleases($this->bounds, 30) : [],
            'filtered' => array_filter($filter) !== [],
            // The dead layer is labelled from the real start of the history until it covers 12 months.
            'deadLabel' => $deadSince && CarbonImmutable::parse($deadSince)->gt(now()->subMonths(12))
                ? __('Died since :date', ['date' => Format::localDate($deadSince)])
                : __('Died in the last 12 months'),
            'extract' => Register::latestRun()?->tenements_extract,
        ];
    }
}; ?>

@php
    $chip = 'inline-flex items-center gap-1 rounded-full border px-3 text-xs font-medium whitespace-nowrap min-h-8 max-sm:min-h-11';
    $chipOff = 'border-zinc-200 bg-white text-zinc-700 hover:border-zinc-400 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-200';
    $chipOn = 'border-zinc-900 bg-zinc-900 text-white dark:border-white dark:bg-white dark:text-zinc-900';
    $allKinds = count($kinds) === count(Register::KINDS) + 1;
    $kindNames = Format::TYPE_NAMES + ['other' => __('Other')];
    $layerRows = [
        'releases' => [__('Release due (compulsory surrender)'), Format::STATUS_COLOURS['release'], null],
        'freed' => [__('Freed ground'), '#16a34a', __('Part of a recent death with no live or pending tenement over it on the register.')],
        'dead' => [$deadLabel, Format::STATUS_COLOURS['dead'], null],
        'restrictions' => [__('Closed or restricted land (s19, s57, FNAs)'), Format::STATUS_COLOURS['restricted'], null],
        'norecord' => [__('No recorded tenement'), '#a3a3a3', __('No live or pending tenement on the register as at :date. A screening aid, not a sign the ground can be applied for.', ['date' => Format::awst($extract, false)])],
        'grid' => [__('One-minute blocks'), '#737373', __('The graticular blocks exploration licences are applied for by, from zoom 11.')],
    ];
@endphp

<div class="relative flex flex-1 flex-col sm:flex-row"
     x-data="mapPanel({ layers: { tenements: true, pending: true, releases: true, freed: true, dead: false, restrictions: false, norecord: false, grid: false } })"
     x-on:map-moved.window.debounce.250ms="moved($event.detail)">

    {{-- Side panel on wider screens; on phones a bottom sheet that peeks with counts and filters. --}}
    <aside x-ref="sheet"
           class="z-20 flex flex-col bg-white max-sm:fixed max-sm:inset-x-0 max-sm:bottom-0 max-sm:rounded-t-2xl max-sm:shadow-[0_-8px_30px_rgba(0,0,0,0.18)] max-sm:transition-[height] sm:h-[calc(100dvh-7.5rem)] sm:w-80 sm:shrink-0 lg:w-96 sm:border-e sm:border-zinc-200 dark:bg-zinc-900 sm:dark:border-zinc-700"
           x-bind:class="{ 'max-sm:transition-none': dragging, 'max-sm:h-[12.5rem]': sheet === 'peek', 'max-sm:h-[55dvh]': sheet === 'half', 'max-sm:h-[calc(100dvh-4.5rem)]': sheet === 'full' }"
           x-bind:style="dragHeight ? `height: ${dragHeight}px` : ''"
           aria-label="{{ __('What is in view') }}">
        <button type="button" class="flex h-11 w-full shrink-0 touch-none items-center justify-center sm:hidden"
                x-on:pointerdown="startDrag($event)" x-on:pointermove="onDrag($event)" x-on:pointerup="endDrag($event)" x-on:pointercancel="endDrag($event)"
                x-on:click="toggleSheet()" x-bind:aria-expanded="sheet !== 'peek'" aria-label="{{ __('Show more of the panel') }}">
            <span class="h-1.5 w-10 rounded-full bg-zinc-300 dark:bg-zinc-600"></span>
        </button>

        <div class="min-h-0 flex-1 overflow-y-auto px-4 pb-4 sm:pt-4" x-bind:class="sheet === 'peek' && 'max-sm:overflow-hidden'">
            {{-- f-accounts: the email-first watch panel; it fills from the map's draw tool. --}}
            <div class="mb-5"><livewire:watch-this-area compact /></div>

            <flux:heading size="lg" level="1" class="max-sm:hidden">{{ __('Every WA mining tenement') }}</flux:heading>
            <p class="text-sm tabular-nums sm:mt-1" wire:loading.class="opacity-60">
                <span class="font-semibold">{{ number_format($counts['tenements']) }}</span> {{ trans_choice('tenement in view|tenements in view', $counts['tenements']) }}
                · <span class="font-semibold">{{ number_format($counts['changed_week']) }}</span> {{ __('changed this week') }}
            </p>

            {{-- Chip bar (M5): every chip sends map-filter through the server, so the counts and the map agree. --}}
            <div class="-mx-4 mt-2 flex gap-1.5 overflow-x-auto px-4 pb-1 sm:flex-wrap sm:overflow-visible">
                @php($typeLabel = $allKinds ? __('Type') : (count($kinds) === 1 ? $kindNames[$kinds[0]] : __('Type: :n', ['n' => count($kinds)])))
                <flux:dropdown>
                    <button type="button" @class([$chip, $allKinds ? $chipOff : $chipOn])>{{ $typeLabel }} <flux:icon.chevron-down variant="micro" /></button>
                    <flux:menu>
                        <flux:menu.checkbox.group wire:model.live="kinds">
                            @foreach ($kindNames as $code => $name)
                                <flux:menu.checkbox :value="$code">{{ $name }}</flux:menu.checkbox>
                            @endforeach
                        </flux:menu.checkbox.group>
                    </flux:menu>
                </flux:dropdown>
                @php($statusOn = count($statuses) !== 2)
                <flux:dropdown>
                    <button type="button" @class([$chip, $statusOn ? $chipOn : $chipOff])>{{ $statusOn ? (count($statuses) === 1 ? __($this::STATUSES[$statuses[0]] ?? 'Status') : __('No status')) : __('Status') }} <flux:icon.chevron-down variant="micro" /></button>
                    <flux:menu>
                        <flux:menu.checkbox.group wire:model.live="statuses">
                            @foreach ($this::STATUSES as $key => $label)
                                <flux:menu.checkbox :value="$key">{{ __($label) }}</flux:menu.checkbox>
                            @endforeach
                        </flux:menu.checkbox.group>
                    </flux:menu>
                </flux:dropdown>
                @foreach ([['applied', __('Applied'), $this::APPLIED, __('Any time')], ['expires', __('Expires'), $this::EXPIRES, __('Any time')]] as [$prop, $label, $options, $any])
                    <flux:dropdown>
                        <button type="button" @class([$chip, $$prop !== '' ? $chipOn : $chipOff])>{{ $$prop !== '' ? $label.': '.Str::lower(__($options[$$prop])) : $label }} <flux:icon.chevron-down variant="micro" /></button>
                        <flux:menu>
                            <flux:menu.radio.group wire:model.live="{{ $prop }}">
                                <flux:menu.radio value="">{{ $any }}</flux:menu.radio>
                                @foreach ($options as $key => $text)
                                    <flux:menu.radio :value="(string) $key">{{ __($text) }}</flux:menu.radio>
                                @endforeach
                            </flux:menu.radio.group>
                        </flux:menu>
                    </flux:dropdown>
                @endforeach
                <flux:dropdown>
                    <button type="button" @class([$chip, $size !== '' ? $chipOn : $chipOff])>{{ $size !== '' ? __($this::SIZES[$size][2]) : __('Size') }} <flux:icon.chevron-down variant="micro" /></button>
                    <flux:menu>
                        <flux:menu.radio.group wire:model.live="size">
                            <flux:menu.radio value="">{{ __('Any size') }}</flux:menu.radio>
                            @foreach ($this::SIZES as $key => [, , $text])
                                <flux:menu.radio :value="$key">{{ __($text) }}</flux:menu.radio>
                            @endforeach
                        </flux:menu.radio.group>
                    </flux:menu>
                </flux:dropdown>
                @if ($filtered)
                    <button type="button" class="{{ $chip }} border-transparent text-zinc-500 hover:text-zinc-900 dark:hover:text-white" wire:click="clearFilters">{{ __('Clear') }}</button>
                @endif
            </div>

            {{-- Legend (M6): what the colours mean, with counts in view; types toggle with the Type chip. --}}
            <div class="mt-3 rounded-lg border border-zinc-200 dark:border-zinc-700" x-data="{ open: false }">
                <button type="button" class="flex min-h-10 w-full items-center gap-3 px-3 py-1.5 text-start text-xs max-sm:min-h-11" x-on:click="open = ! open" x-bind:aria-expanded="open">
                    <span class="flex flex-1 flex-wrap gap-x-3 gap-y-1">
                        @foreach (['live' => $counts['by_status']['LIVE'], 'pending' => $counts['by_status']['PENDING'], 'release' => $counts['releases']] as $key => $n)
                            <span class="flex items-center gap-1.5 whitespace-nowrap tabular-nums">
                                <span class="size-2.5 rounded-full" style="background: {{ Format::STATUS_COLOURS[$key] }}"></span>{{ __(Format::STATUS_LABELS[$key]) }} <span class="text-zinc-500 dark:text-zinc-400">{{ number_format($n) }}</span>
                            </span>
                        @endforeach
                    </span>
                    <span class="flex shrink-0 items-center gap-1 font-medium">{{ __('Key and layers') }}<flux:icon.chevron-down variant="micro" x-bind:class="open && 'rotate-180'" /></span>
                </button>
                <div x-show="open" x-collapse x-cloak class="border-t border-zinc-200 px-3 pt-2 pb-3 dark:border-zinc-700">
                    <flux:radio.group x-model="colour" variant="segmented" size="sm" :label="__('Colour ground by')">
                        <flux:radio value="status" :label="__('Status')" />
                        <flux:radio value="type" :label="__('Type')" />
                    </flux:radio.group>
                    <flux:text class="mt-1 text-xs" x-text="colour === 'status' ? @js(__('Fill by status, outline by type. Pending ground is hatched.')) : @js(__('Fill and outline by type. Pending ground is dashed.'))"></flux:text>

                    <flux:checkbox.group wire:model.live="kinds" class="mt-3">
                        <flux:label class="text-xs">{{ __('Types in view') }}</flux:label>
                        <div class="mt-1 space-y-0.5">
                            @foreach ($kindNames as $code => $name)
                                <div class="flex min-h-8 items-center gap-2">
                                    <flux:field variant="inline" class="flex-1">
                                        <flux:checkbox :value="$code" class="max-sm:self-center" />
                                        <flux:label class="max-sm:min-h-11 max-sm:items-center">{{ $name }}</flux:label>
                                    </flux:field>
                                    <span class="ms-auto text-xs tabular-nums text-zinc-500 dark:text-zinc-400">{{ number_format($counts['by_kind'][$code] ?? 0) }}</span>
                                    <span class="size-3 shrink-0 rounded-sm border-2" style="border-color: {{ Format::TYPE_COLOURS[$code] ?? Format::OTHER_COLOUR }}"></span>
                                </div>
                            @endforeach
                        </div>
                    </flux:checkbox.group>

                    <flux:label class="mt-4 text-xs">{{ __('Layers') }}</flux:label>
                    <div class="mt-1 space-y-1">
                        @foreach ($layerRows as $key => [$label, $colour, $hint])
                            <div class="flex min-h-8 items-start gap-2">
                                <flux:field variant="inline" class="flex-1">
                                    <flux:checkbox x-model="layers.{{ $key }}" class="max-sm:mt-3" />
                                    <flux:label class="max-sm:min-h-11 max-sm:items-center">{{ $label }}</flux:label>
                                    @if ($hint)<flux:description>{{ $hint }}</flux:description>@endif
                                </flux:field>
                                <span class="ms-auto mt-1 size-3 shrink-0 rounded-sm opacity-70" style="background: {{ $colour }}"></span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- The list synced to the map (M4). --}}
            <div class="mt-4 flex items-center justify-between gap-2">
                <flux:tabs wire:model.live="tab" variant="segmented" size="sm" class="max-sm:h-[50px] max-sm:w-full">
                    <flux:tab name="changes">{{ __('Changes') }}</flux:tab>
                    <flux:tab name="tenements">{{ __('Tenements') }}</flux:tab>
                    <flux:tab name="releases">{{ __('Releases') }} <span class="tabular-nums text-zinc-500">{{ $counts['releases'] }}</span></flux:tab>
                </flux:tabs>
            </div>
            {{-- Fields written out so the whole row is the touch target on phones. --}}
            <flux:field variant="inline" class="mt-2">
                <flux:checkbox x-model="follow" class="max-sm:self-center" />
                <flux:label class="max-sm:min-h-11 max-sm:items-center">{{ __('Update as I move the map') }}</flux:label>
            </flux:field>

            <div class="mt-2" wire:loading.class="opacity-60" wire:target="moved, tab, kinds, statuses, applied, expires, size">
                @if ($tabName === 'changes')
                    <x-event-list :events="$changes" context="map" compact :filtered="$bounds !== null || ! $allKinds" />
                    @if (count($changes))
                        <flux:button size="sm" variant="subtle" class="mt-2" :href="route('activity')" wire:navigate icon-trailing="arrow-right">{{ __('Every change') }}</flux:button>
                        @guest
                            {{-- G8: the digest sign-up sits under the list, not above the layers. --}}
                            <div class="mt-4 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                                <flux:heading size="sm">{{ __('Get the week\'s changes every Monday') }}</flux:heading>
                                <flux:text class="mt-1 mb-2 text-xs">{{ __('Freed ground, the biggest new applications and the releases coming up.') }}
                                    <flux:link :href="route('digest')" wire:navigate>{{ __('See an issue') }}</flux:link></flux:text>
                                <livewire:digest-signup source="map" compact />
                            </div>
                        @endguest
                    @endif
                @elseif ($tabName === 'tenements')
                    @forelse ($tenements as $t)
                        <div wire:key="t-{{ $t['slug'] }}" class="flex items-start gap-2 border-b border-zinc-200 py-2.5 dark:border-zinc-700"
                             x-on:mouseenter="$dispatch('map-select', { tenid: @js($t['tenid']) })" x-on:mouseleave="$dispatch('map-select', { tenid: null })">
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2 text-sm">
                                    <flux:link :href="route('tenements.show', $t['slug'])" wire:navigate class="font-medium tabular-nums">{{ $t['fmt_tenid'] }}</flux:link>
                                    <x-status-badge :status="$t['status']" />
                                </div>
                                <div class="text-xs text-zinc-500 dark:text-zinc-400">
                                    {{ implode(' · ', array_filter([Format::sentence($t['type']), Format::field($t['fmt_tenid']), $t['area_ha'] !== null ? number_format($t['area_ha'], $t['area_ha'] < 10 ? 1 : 0).' ha' : null])) }}
                                </div>
                                <div class="truncate text-xs text-zinc-500 dark:text-zinc-400">
                                    {{ $t['holders'][0]['name'] ?? '' }}@if (count($t['holders']) > 1) +{{ count($t['holders']) - 1 }}@endif
                                    · {{ $t['status'] === 'PENDING' ? __('Applied :when', ['when' => Format::relative($t['applied_at'])]) : __('Expires :date', ['date' => Format::localDate($t['ends_at'])]) }}
                                </div>
                            </div>
                            @if ($t['bbox'])
                                <flux:button size="sm" variant="ghost" icon="viewfinder-circle" class="max-sm:min-h-11 max-sm:min-w-11" x-on:click="fly({{ json_encode($t['bbox']) }})" :aria-label="__('Show :id on the map', ['id' => $t['fmt_tenid']])" />
                            @endif
                        </div>
                    @empty
                        <x-event-list :events="[]" context="map" filtered />
                    @endforelse
                    @if (count($tenements) === 40)
                        <flux:text class="mt-2 text-xs">{{ __('The 40 newest applications in view. Zoom in or filter to see the rest.') }}</flux:text>
                    @endif
                @else
                    @forelse ($releases as $r)
                        @php($at = CarbonImmutable::parse($r['release_at'])->setTimezone('Australia/Perth'))
                        <div wire:key="r-{{ $r['slug'] }}-{{ $r['rlesid'] }}" class="flex items-start gap-2 border-b border-zinc-200 py-2.5 dark:border-zinc-700"
                             x-on:mouseenter="$dispatch('map-select', { tenid: @js($r['tenid']) })" x-on:mouseleave="$dispatch('map-select', { tenid: null })">
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2 text-sm">
                                    <flux:link :href="route('tenements.show', $r['slug'])" wire:navigate class="font-medium tabular-nums">{{ $r['fmt_tenid'] }}</flux:link>
                                    <x-status-badge status="release" />
                                </div>
                                <div class="text-xs text-zinc-700 tabular-nums dark:text-zinc-200">
                                    {{ __('Releases :when AWST', ['when' => $at->format('D j M, g:ia')]) }} · {{ Format::relative($at->format('Y-m-d H:i:s')) }}
                                </div>
                                <div class="truncate text-xs text-zinc-500 dark:text-zinc-400">
                                    {{ number_format($r['area_ha'], 1) }} ha · {{ $r['holders'][0]['name'] ?? '' }}
                                    @if (Route::has('tenements.release-ics'))
                                        · <a class="underline" href="{{ route('tenements.release-ics', $r['slug']) }}">{{ __('Add to calendar') }}</a>
                                    @endif
                                </div>
                            </div>
                            @if ($r['bbox'])
                                <flux:button size="sm" variant="ghost" icon="viewfinder-circle" class="max-sm:min-h-11 max-sm:min-w-11" x-on:click="fly({{ json_encode($r['bbox']) }})" :aria-label="__('Show :id on the map', ['id' => $r['fmt_tenid']])" />
                            @endif
                        </div>
                    @empty
                        <flux:text class="text-sm">{{ $bounds ? __('No compulsory release is scheduled in this part of the map.') : __('No compulsory release is scheduled on the register.') }}</flux:text>
                    @endforelse
                @endif
            </div>

            <flux:text class="mt-6 text-xs sm:hidden">
                {{ __('A screening aid, not advice. Confirm in') }} <a class="underline" href="{{ Format::TENGRAPH_URL }}" target="_blank" rel="noreferrer">TENGRAPH</a> {{ __('and with the Mining Registrar.') }}
            </flux:text>
        </div>
    </aside>

    <div class="relative h-[calc(100dvh-3.5rem)] sm:h-auto sm:flex-1">
        <div wire:ignore class="h-full w-full">
            <div x-data="tenementMap({})" class="h-full w-full">
                <div x-ref="map" class="h-full w-full"></div>
            </div>
        </div>
        {{-- PH1: on phones the search sits on the map. --}}
        <flux:modal.trigger name="search" class="absolute inset-x-3 top-3 me-12 sm:hidden">
            <button type="button" class="flex min-h-11 w-full items-center gap-2 rounded-full bg-white px-4 text-sm text-zinc-500 shadow-lg dark:bg-zinc-800 dark:text-zinc-300">
                <flux:icon.magnifying-glass variant="mini" />{{ __('Tenement, holder or place') }}
            </button>
        </flux:modal.trigger>
    </div>
</div>
