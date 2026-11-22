<?php

use App\Support\Format;
use App\Support\Register;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::map'), Title('WA tenement map')] class extends Component {
    public function with(): array
    {
        $events = Register::events([], 40);

        return [
            'totals' => Register::totals(),
            'events' => $events,
            'markers' => array_values(array_map(fn ($e) => [
                'id' => $e['id'], 'point' => $e['point'], 'colour' => Format::eventColour($e['type']),
            ], array_filter($events, fn ($e) => $e['point'] !== null))),
        ];
    }
}; ?>

@php
    $layerLabels = [
        'tenements' => __('Live tenements'),
        'pending' => __('Pending applications'),
        'releases' => __('Release pending (s65 surrender)'),
        'dead' => __('Died in the last 12 months'),
        'restrictions' => __('Closed or restricted land (s19, s57, FNAs)'),
    ];
@endphp

<div class="flex flex-1 flex-col md:flex-row"
     x-data="tenementMap({ markers: @js($markers) })"
     x-on:event-hover.window="hovered = $event.detail.id">
    <aside class="w-full overflow-y-auto border-b border-zinc-200 p-4 md:h-[calc(100vh-7.5rem)] md:w-96 md:border-e md:border-b-0 dark:border-zinc-700">
        <flux:heading size="lg" level="1">{{ __('Every WA mining tenement, live, pending and dead') }}</flux:heading>
        <flux:text class="mt-1">
            {{ number_format($totals['LIVE']) }} live and {{ number_format($totals['PENDING']) }} pending.
            {{ __('Free email alerts when a tenement near you is recorded dead or has ground scheduled for release.') }}
        </flux:text>

        <flux:checkbox.group :label="__('Layers')" class="mt-4">
            @foreach ($layerLabels as $key => $label)
                <flux:checkbox x-model="layers.{{ $key }}" :label="$label" />
            @endforeach
        </flux:checkbox.group>

        <div class="mt-3 flex flex-wrap gap-x-3 gap-y-1 text-xs">
            @foreach (Format::TYPE_NAMES as $code => $name)
                <span class="flex items-center gap-1">
                    <span class="inline-block size-3 rounded-sm" style="background: {{ Format::TYPE_COLOURS[$code] }}"></span>{{ $name }}
                </span>
            @endforeach
            <span class="flex items-center gap-1">
                <span class="inline-block size-3 rounded-sm" style="background: {{ Format::OTHER_COLOUR }}"></span>{{ __('Other') }}
            </span>
            <span class="text-zinc-500">{{ __('Dashed outline: pending') }}</span>
        </div>

        <flux:heading class="mt-6 mb-1">{{ __('Latest changes') }}</flux:heading>
        <x-event-list :events="$events" compact />
    </aside>
    <div wire:ignore class="min-h-[60vh] flex-1">
        <div x-ref="map" class="h-full min-h-[60vh] w-full"></div>
    </div>
</div>
