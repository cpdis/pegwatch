<?php

use App\Support\Format;
use App\Support\Register;
use Livewire\Component;

new class extends Component {
    public string $slug;

    public bool $showAll = false;

    public function mount(string $slug): void
    {
        abort_unless(Register::holder($slug), 404);
        $this->slug = $slug;
    }

    public function render()
    {
        $data = Register::holder($this->slug);

        return $this->view(['data' => $data])->title($data['holder']['name']);
    }
}; ?>

@php
    $months = $data['applications_by_month'];
    $max = max(1, ...array_column($months, 'n'));
    $none = array_sum(array_column($months, 'n')) === 0;
    $tenements = $showAll ? $data['tenements'] : array_slice($data['tenements'], 0, 50);
    $s = $data['summary'];
@endphp

<div>
    <flux:heading size="xl" level="1">{{ $data['holder']['name'] }}</flux:heading>
    <flux:text class="mt-1">
        {{ $s['live'] }} live, {{ $s['pending'] }} pending, {{ number_format($s['area_ha']) }} ha mapped. {{ $s['dead'] }} dead tenements on record.
        @unless ($data['holder']['is_company']) {{ __('Shown by name only, as on the public register.') }} @endunless
    </flux:text>

    <div class="mt-4 grid gap-6 md:grid-cols-5">
        <div class="md:col-span-3">
            <div wire:ignore x-data="tenementMap({ bounds: @js($data['bbox']), highlight: @js($data['geometry']) })" class="h-[420px] w-full overflow-hidden rounded-lg">
                <div x-ref="map" class="h-full w-full"></div>
            </div>
        </div>
        <div class="md:col-span-2">
            <flux:heading>{{ __('Applications per month (24 months)') }}</flux:heading>
            @if ($none)
                <flux:text>{{ __('None still live or pending.') }}</flux:text>
            @else
                <div class="mt-2 flex h-32 items-end gap-0.5" role="img" aria-label="{{ __('Applications per month') }}">
                    @foreach ($months as $m)
                        <div class="flex-1 rounded-t-sm bg-zinc-700/70 dark:bg-zinc-300/70" title="{{ $m['month'] }}: {{ $m['n'] }}"
                             style="height: {{ $m['n'] / $max * 100 }}%; min-height: {{ $m['n'] ? 2 : 0 }}px"></div>
                    @endforeach
                </div>
                <div class="flex justify-between text-xs text-zinc-500">
                    <span>{{ $months[0]['month'] }}</span><span>{{ end($months)['month'] }}</span>
                </div>
            @endif
            <flux:heading class="mt-6">{{ __('Recent changes') }}</flux:heading>
            <x-event-list :events="$data['events']" compact />
        </div>
    </div>

    <flux:heading class="mt-8">{{ __('Tenements') }} ({{ count($data['tenements']) }})</flux:heading>
    <flux:table class="mt-2">
        <flux:table.columns>
            <flux:table.column>{{ __('Tenement') }}</flux:table.column>
            <flux:table.column>{{ __('Type') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
            <flux:table.column>{{ __('Applied') }}</flux:table.column>
            <flux:table.column>{{ __('Expires') }}</flux:table.column>
            <flux:table.column align="end">ha</flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @foreach ($tenements as $t)
                <flux:table.row :key="$t['slug']">
                    <flux:table.cell><flux:link :href="route('tenements.show', $t['slug'])" wire:navigate>{{ $t['fmt_tenid'] }}</flux:link></flux:table.cell>
                    <flux:table.cell>{{ Format::lower($t['type']) }}</flux:table.cell>
                    <flux:table.cell>{{ Format::lower($t['status']) }}</flux:table.cell>
                    <flux:table.cell>{{ Format::localDate($t['applied_at']) }}</flux:table.cell>
                    <flux:table.cell>{{ Format::localDate($t['ends_at']) }}</flux:table.cell>
                    <flux:table.cell align="end">{{ $t['area_ha'] !== null ? number_format($t['area_ha'], 1) : '' }}</flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>
    @if (count($data['tenements']) > 50 && ! $showAll)
        <flux:button size="sm" variant="subtle" class="mt-2" wire:click="$set('showAll', true)">{{ __('Show all') }} {{ count($data['tenements']) }}</flux:button>
    @endif

    @if (count($data['dead']))
        <flux:heading class="mt-8">{{ __('Dead tenements on record') }}</flux:heading>
        <ul class="mt-1 columns-2 text-sm md:columns-3">
            @foreach ($data['dead'] as $d)
                <li>
                    <flux:link :href="route('tenements.show', $d['slug'])" wire:navigate>{{ $d['fmt_tenid'] }}</flux:link>
                    <span class="text-zinc-500">{{ Format::lower($d['reason']) }} {{ Format::localDate($d['ended_at']) }}</span>
                </li>
            @endforeach
        </ul>
    @endif
</div>
