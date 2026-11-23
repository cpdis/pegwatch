<?php

use App\Alerts\WatchShape;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Start a watch (route watch.start): a circle picked on the map, or a
 * tenement (`?tenement=m-27-518`) or holder (`?holder=<slug>`) from its page.
 */
new #[Title('Watch an area')] class extends Component {
    #[Url]
    public ?string $tenement = null;

    #[Url]
    public ?string $holder = null;

    public function with(): array
    {
        $shape = $this->tenement !== null ? WatchShape::tenement($this->tenement)
            : ($this->holder !== null ? WatchShape::holder($this->holder) : null);

        return ['shape' => $shape, 'bounds' => $shape?->bounds(), 'highlight' => $shape?->geometry()];
    }
}; ?>

<div class="grid gap-6 lg:grid-cols-[minmax(0,26rem)_1fr]">
    <div class="order-2 lg:order-1">
        <x-eyebrow dot="#ffd400">{{ __('Free email alerts') }}</x-eyebrow>
        <flux:heading size="xl" level="1" class="mt-1">
            @if ($shape?->kind === 'tenement')
                {{ __('Watch :id', ['id' => $shape->label]) }}
            @elseif ($shape?->kind === 'holder')
                {{ __('Watch :name', ['name' => $shape->label]) }}
            @else
                {{ __('Watch an area') }}
            @endif
        </flux:heading>
        <flux:text class="mt-1 mb-5">
            @if ($shape?->kind === 'holder')
                {{ __('An email after any night when one of their tenements is recorded dead or has ground scheduled for release.') }}
            @else
                {{ __('An email after any night when a tenement here is recorded dead or has ground scheduled for release.') }}
            @endif
        </flux:text>
        @if (($tenement !== null || $holder !== null) && ! $shape)
            <flux:callout class="mb-5" icon="exclamation-triangle" color="amber" :heading="__('Nothing on the register by that name')">
                <flux:callout.text>{{ __('Pick an area on the map instead, or search for the tenement or holder.') }}</flux:callout.text>
            </flux:callout>
        @endif
        <livewire:watch-this-area :tenement="$shape?->kind === 'tenement' ? $tenement : null" :holder="$shape?->kind === 'holder' ? $holder : null" />
    </div>

    {{-- Without the draw tool, a click picks the centre and the circle previews here; once the draw tool reports a shape it takes over. --}}
    <div class="order-1 h-[45dvh] overflow-hidden rounded-lg lg:order-2 lg:h-[calc(100dvh-12rem)]" x-data="{ drawn: false }">
        <div wire:ignore class="h-full w-full"
             x-data="tenementMap({
                 draw: @js($shape === null),
                 pick: @js($shape === null),
                 bounds: @js($bounds),
                 highlight: @js($highlight),
             })"
             x-on:watch-shape.window="drawn = true; if ($event.detail.kind !== 'circle') circle = null"
             x-on:watch-start.window="if (! drawn && $event.detail.center) { circle = { center: $event.detail.center, radiusKm: $event.detail.radiusKm ?? 10 }; const r = (circle.radiusKm / 111) * 1.3; fit([circle.center[0] - r / Math.cos(circle.center[1] * Math.PI / 180), circle.center[1] - r, circle.center[0] + r / Math.cos(circle.center[1] * Math.PI / 180), circle.center[1] + r]) }">
            <div x-ref="map" class="h-full w-full"></div>
        </div>
    </div>
</div>
