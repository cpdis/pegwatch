<?php

use App\Support\Register;
use Livewire\Attributes\Computed;
use Livewire\Component;

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
            $this->redirectRoute('tenements.show', ['slug' => $first['slug']], navigate: true);
        } elseif ($holder) {
            $this->redirectRoute('holders.show', ['slug' => $holder['slug']], navigate: true);
        }
    }
}; ?>

<div class="relative" x-data="{ open: false }" x-on:click.outside="open = false">
    <form wire:submit="go">
        <flux:input
            wire:model.live.debounce.250ms="q"
            x-on:focus="open = true"
            x-on:input="open = true"
            x-on:keydown.escape="open = false"
            icon="magnifying-glass"
            size="sm"
            :placeholder="__('Tenement (E 45/4894) or holder')"
            :aria-label="__('Search tenements and holders')"
        />
    </form>
    @php($r = $this->results)
    @if (count($r['tenements']) || count($r['holders']))
        <div x-show="open" x-cloak class="absolute z-30 mt-1 max-h-96 w-full overflow-auto rounded-lg border border-zinc-200 bg-white text-sm shadow-lg dark:border-zinc-700 dark:bg-zinc-900">
            @foreach ($r['tenements'] as $t)
                <a href="{{ route('tenements.show', $t['slug']) }}" wire:navigate class="block px-3 py-1.5 hover:bg-zinc-100 dark:hover:bg-zinc-800">
                    <span class="font-medium">{{ $t['fmt_tenid'] }}</span>
                    <span class="text-zinc-500">{{ strtolower($t['type'] ?? '') }} · {{ strtolower($t['status'] ?? '') }}</span>
                </a>
            @endforeach
            @foreach ($r['holders'] as $h)
                <a href="{{ route('holders.show', $h['slug']) }}" wire:navigate class="block px-3 py-1.5 hover:bg-zinc-100 dark:hover:bg-zinc-800">
                    {{ $h['name'] }} <span class="text-zinc-500">{{ $h['count'] }} tenements</span>
                </a>
            @endforeach
        </div>
    @endif
</div>
