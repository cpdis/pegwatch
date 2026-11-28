<?php

use App\Support\Format;
use App\Support\Register;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * The search palette. Rendered once per page by the layout; header and
 * sidebar buttons open it with <flux:modal.trigger name="search">, and
 * Cmd/Ctrl+K opens it from anywhere. Results come from the server, so the
 * palette's own client-side filter is off.
 */
new class extends Component {
    /** Searches allowed per minute, per session and per client IP. */
    public const PER_SESSION = 60;

    public const PER_IP = 300;

    /** Queries the empty and no-match states offer. */
    public const EXAMPLES = ['M 27/518', 'Northern Star', 'Leonora'];

    public string $q = '';

    public bool $limited = false;

    #[Computed]
    public function results(): array
    {
        $this->limited = false;
        if (mb_strlen(trim($this->q)) < 2) {
            return [];
        }

        // Livewire requests need a session (CSRF), so the session limit
        // can't be dodged by dropping cookies; the IP limit catches
        // someone minting sessions. Behind Laravel Cloud's Cloudflare edge
        // the client address is in CF-Connecting-IP.
        $ip = \App\Support\ClientIp::of();
        foreach (['search:s:'.session()->getId() => self::PER_SESSION, 'search:ip:'.$ip => self::PER_IP] as $key => $max) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                $this->limited = true;

                return [];
            }
        }
        RateLimiter::hit('search:s:'.session()->getId());
        RateLimiter::hit('search:ip:'.$ip);

        return Register::search($this->q);
    }

    /** Enter with no row highlighted opens the first row, as listed. */
    public function go(): void
    {
        if ($first = $this->results[0] ?? null) {
            $this->open($first['kind'], $first['slug']);
        }
    }

    /**
     * A place is not a page: the browser flies the map there when the map
     * page is open, else opens the map at it (see place-chosen below).
     */
    public function open(string $kind, string $slug): void
    {
        $this->reset('q');
        if ($kind === 'place') {
            if ($center = Register::place((int) $slug)) {
                $this->dispatch('place-chosen', center: $center, zoom: 12);
            }

            return;
        }
        $route = match ($kind) {
            'holder' => 'holders.show',
            'group' => 'holders.matching',
            default => 'tenements.show',
        };
        $this->redirectRoute($route, ['slug' => $slug], navigate: true);
    }
}; ?>

<flux:modal name="search" variant="bare" class="w-[calc(100%-2rem)] max-w-[34rem] my-[12vh] max-h-screen overflow-y-hidden"
    x-on:place-chosen.window="
        $flux.modal('search').close();
        const [lng, lat] = $event.detail.center;
        location.pathname === '/watch' && document.querySelector('[x-data^=tenementMap]')
            ? $dispatch('watch-start', { center: [lng, lat], radiusKm: 25 })
            : location.pathname === '/' && document.querySelector('[x-data^=tenementMap]')
            ? $dispatch('map-fly', { center: [lng, lat], zoom: $event.detail.zoom })
            : window.location.assign('/#map=' + $event.detail.zoom + '/' + lat + '/' + lng);
    ">
    @php($results = $this->results)
    <flux:command :filter="false" class="border-none shadow-lg">
        <flux:command.input
            wire:model.live.debounce.200ms="q"
            closable
            autofocus
            maxlength="{{ Register::SEARCH_MAX_LENGTH }}"
            x-on:keydown.enter="$el.closest('[data-flux-command]').querySelector('[data-flux-command-item][data-active]') || $wire.go()"
            :placeholder="__('Tenement, holder or town')"
            :aria-label="__('Search tenements, holders and places')"
        />
        @if ($this->limited)
            <div class="bg-white px-4 py-3 text-sm text-zinc-500 dark:bg-zinc-700 dark:text-zinc-400">
                {{ __('Too many searches. Try again in a minute.') }}
            </div>
        @elseif (mb_strlen(trim($q)) < 2 || $results === [])
            <div class="bg-white px-4 py-3 text-sm text-zinc-500 dark:bg-zinc-700 dark:text-zinc-400" data-search-hint>
                @if (mb_strlen(trim($q)) < 2)
                    {{ __('Type a tenement ID, part of a holder name, or a town.') }}
                @else
                    <span class="font-medium text-zinc-800 dark:text-white">{{ __('Nothing matches “:q”.', ['q' => trim($q)]) }}</span>
                    {{ __('Try an ID, a holder or a town:') }}
                @endif
                <span class="mt-2 flex flex-wrap gap-1.5">
                    @foreach ($this::EXAMPLES as $example)
                        <flux:button size="xs" variant="filled" wire:click="$set('q', @js($example))">{{ $example }}</flux:button>
                    @endforeach
                </span>
            </div>
        @else
        <flux:command.items class="max-h-96">
            @foreach ($results as $r)
                @switch($r['kind'])
                    @case('place')
                        <flux:command.item icon="map" class="!h-auto min-h-10" wire:key="p-{{ $r['slug'] }}" wire:click="open('place', '{{ $r['slug'] }}')">
                            <span class="min-w-0 truncate">{{ $r['name'] }}</span>
                            <span class="ms-2 min-w-0 truncate font-normal text-zinc-500 dark:text-zinc-400">{{ implode(' · ', array_filter([Format::sentence($r['place_kind']), $r['lga']])) }}</span>
                            <span class="ms-auto shrink-0 ps-2 text-xs font-normal text-zinc-500 dark:text-zinc-400">{{ __('Show on map') }}</span>
                        </flux:command.item>
                        @break
                    @case('group')
                        <flux:command.item icon="rectangle-stack" class="!h-auto min-h-10 py-2" wire:key="g-{{ $r['slug'] }}" wire:click="open('group', '{{ $r['slug'] }}')">
                            <span class="min-w-0">
                                <span class="block truncate">{{ __('Entities matching “:name”', ['name' => $r['label']]) }}</span>
                                <span class="block truncate text-xs font-normal text-zinc-500 tabular-nums dark:text-zinc-400">
                                    {{ __('Name match') }} · {{ trans_choice(':n company|:n companies', $r['entities'], ['n' => $r['entities']]) }} · {{ trans_choice(':n tenement|:n tenements', $r['tenements'], ['n' => number_format($r['tenements'])]) }}
                                </span>
                            </span>
                        </flux:command.item>
                        @break
                    @case('holder')
                        @php($counts = $r['live'] + $r['pending'] === 0 ? __('Dead only') : number_format($r['live']).' '.__('live').' · '.number_format($r['pending']).' '.__('pending'))
                        <flux:command.item :icon="$r['is_company'] ? 'building-office' : 'user'" class="max-sm:!h-auto max-sm:py-2" wire:key="h-{{ $r['slug'] }}" wire:click="open('holder', '{{ $r['slug'] }}')">
                            {{-- Phones put the counts under the name, so neither wraps nor squeezes the other. --}}
                            <span class="min-w-0">
                                <span class="block truncate">{{ $r['name'] }}</span>
                                <span class="block text-xs font-normal whitespace-nowrap text-zinc-500 tabular-nums sm:hidden dark:text-zinc-400">{{ $counts }}</span>
                            </span>
                            <span class="ms-auto shrink-0 ps-2 text-xs font-normal whitespace-nowrap text-zinc-500 tabular-nums max-sm:hidden dark:text-zinc-400">{{ $counts }}</span>
                        </flux:command.item>
                        @break
                    @default
                        <flux:command.item icon="map-pin" class="!h-auto min-h-10 py-2" wire:key="t-{{ $r['slug'] }}" wire:click="open('tenement', '{{ $r['slug'] }}')">
                            <span class="min-w-0">
                                <span class="flex items-center gap-2">
                                    <span class="whitespace-nowrap">{{ $r['fmt_tenid'] }}</span>
                                    <x-status-badge :status="$r['status']" />
                                </span>
                                <span class="block truncate text-xs font-normal text-zinc-500 dark:text-zinc-400">{{ implode(' · ', array_filter([
                                    Format::sentence($r['type']) ?: __('Unknown type'), $r['field'], $r['status'] === 'DEAD' ? Format::sentence($r['death_reason']) : null,
                                ])) }}</span>
                            </span>
                            @if ($r['area_ha'] !== null)
                                <span class="ms-auto shrink-0 ps-2 text-xs font-normal whitespace-nowrap text-zinc-500 tabular-nums dark:text-zinc-400">{{ number_format($r['area_ha'], $r['area_ha'] < 10 ? 1 : 0) }} ha</span>
                            @endif
                        </flux:command.item>
                @endswitch
            @endforeach
        </flux:command.items>
        @endif
        <div class="flex gap-4 border-t border-zinc-200 bg-zinc-50 px-4 py-2 text-xs text-zinc-500 max-sm:hidden dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-400" data-search-keys>
            <span><kbd class="font-sans">↑</kbd> <kbd class="font-sans">↓</kbd> {{ __('to move') }}</span>
            <span><kbd class="font-sans">↵</kbd> {{ __('to open') }}</span>
            <span><kbd class="font-sans">esc</kbd> {{ __('to close') }}</span>
        </div>
    </flux:command>
</flux:modal>
