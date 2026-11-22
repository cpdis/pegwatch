<?php

use App\Support\Register;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * The search palette. Rendered once per page by the layout; header and
 * sidebar buttons open it with <flux:modal.trigger name="search">, and
 * Cmd/Ctrl+K opens it from anywhere. Results come from the server, so the
 * palette's own client-side filter is off.
 */
new class extends Component {
    public string $q = '';

    #[Computed]
    public function results(): array
    {
        return Register::search($this->q);
    }

    public function go(): void
    {
        $first = $this->results['tenements'][0] ?? null;
        $holder = $this->results['holders'][0] ?? null;
        if ($first) {
            $this->open('tenement', $first['slug']);
        } elseif ($holder) {
            $this->open('holder', $holder['slug']);
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
    @php($r = $this->results)
    <flux:command :filter="false" class="border-none shadow-lg">
        <flux:command.input
            wire:model.live.debounce.200ms="q"
            closable
            autofocus
            x-on:keydown.enter="$el.closest('[data-flux-command]').querySelector('[data-flux-command-item][data-active]') || $wire.go()"
            :placeholder="__('Tenement (E 45/4894) or holder')"
            :aria-label="__('Search tenements and holders')"
        />
        @if (mb_strlen(trim($q)) < 2)
            <div class="bg-white px-4 py-3 text-sm text-zinc-500 dark:bg-zinc-700 dark:text-zinc-400">
                {{ __('Type a tenement ID or part of a holder name.') }}
            </div>
        @else
        <flux:command.items class="max-h-96">
            @foreach ($r['tenements'] as $t)
                <flux:command.item icon="map-pin" wire:key="t-{{ $t['slug'] }}" wire:click="open('tenement', '{{ $t['slug'] }}')">
                    <span>{{ $t['fmt_tenid'] }}</span>
                    <span class="ms-2 font-normal text-zinc-500 dark:text-zinc-400">{{ strtolower($t['type'] ?? '') }} · {{ strtolower($t['status'] ?? '') }}</span>
                </flux:command.item>
            @endforeach
            @foreach ($r['holders'] as $h)
                <flux:command.item icon="building-office" wire:key="h-{{ $h['slug'] }}" wire:click="open('holder', '{{ $h['slug'] }}')">
                    <span class="truncate">{{ $h['name'] }}</span>
                    <span class="ms-auto ps-2 font-normal text-zinc-500 dark:text-zinc-400">{{ trans_choice(':n tenement|:n tenements', $h['count'], ['n' => $h['count']]) }}</span>
                </flux:command.item>
            @endforeach
        </flux:command.items>
        @endif
    </flux:command>
</flux:modal>
