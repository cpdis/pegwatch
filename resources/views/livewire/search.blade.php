<?php

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
        $ip = request()->header('CF-Connecting-IP') ?: request()->ip();
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

    public function open(string $kind, string $slug): void
    {
        $this->reset('q');
        $route = $kind === 'holder' ? 'holders.show' : 'tenements.show';
        $this->redirectRoute($route, ['slug' => $slug], navigate: true);
    }
}; ?>

<flux:modal name="search" variant="bare" class="w-[calc(100%-2rem)] max-w-[34rem] my-[12vh] max-h-screen overflow-y-hidden">
    @php($results = $this->results)
    <flux:command :filter="false" class="border-none shadow-lg">
        <flux:command.input
            wire:model.live.debounce.200ms="q"
            closable
            autofocus
            maxlength="{{ Register::SEARCH_MAX_LENGTH }}"
            x-on:keydown.enter="$el.closest('[data-flux-command]').querySelector('[data-flux-command-item][data-active]') || $wire.go()"
            :placeholder="__('Tenement (E 45/4894) or holder')"
            :aria-label="__('Search tenements and holders')"
        />
        @if ($this->limited)
            <div class="bg-white px-4 py-3 text-sm text-zinc-500 dark:bg-zinc-700 dark:text-zinc-400">
                {{ __('Too many searches. Try again in a minute.') }}
            </div>
        @elseif (mb_strlen(trim($q)) < 2)
            <div class="bg-white px-4 py-3 text-sm text-zinc-500 dark:bg-zinc-700 dark:text-zinc-400">
                {{ __('Type a tenement ID or part of a holder name.') }}
            </div>
        @else
        <flux:command.items class="max-h-96">
            @foreach ($results as $r)
                @if ($r['kind'] === 'tenement')
                    <flux:command.item icon="map-pin" wire:key="t-{{ $r['slug'] }}" wire:click="open('tenement', '{{ $r['slug'] }}')">
                        <span>{{ $r['fmt_tenid'] }}</span>
                        <span class="ms-2 font-normal text-zinc-500 dark:text-zinc-400">{{ strtolower($r['type'] ?? '') }} · {{ $r['status'] === 'DEAD' ? __('dead').($r['death_reason'] ? ' ('.strtolower($r['death_reason']).')' : '') : strtolower($r['status'] ?? '') }}</span>
                    </flux:command.item>
                @else
                    <flux:command.item icon="building-office" wire:key="h-{{ $r['slug'] }}" wire:click="open('holder', '{{ $r['slug'] }}')">
                        <span class="truncate">{{ $r['name'] }}</span>
                        <span class="ms-auto ps-2 font-normal text-zinc-500 dark:text-zinc-400">{{ $r['live'] ? trans_choice(':n tenement|:n tenements', $r['live'], ['n' => $r['live']]) : __('dead tenements only') }}</span>
                    </flux:command.item>
                @endif
            @endforeach
        </flux:command.items>
        @endif
    </flux:command>
</flux:modal>
