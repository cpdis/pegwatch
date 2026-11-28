@php
    app()->instance('pegwatch.error-page', true);
    // A /tenements/<slug> miss names the ID it looked for: "m-27-518" reads as "M 27/518".
    $slug = request()->is('tenements/*') ? mb_substr((string) request()->segment(2), 0, 40) : null;
    $parts = $slug ? explode('-', $slug) : [];
    $id = match (true) {
        ! $slug => null,
        count($parts) === 3 => strtoupper("$parts[0] $parts[1]/$parts[2]"),
        default => strtoupper(str_replace('-', ' ', $slug)),
    };
@endphp
<x-layouts::app :title="__('Not found')">
    <div class="mx-auto max-w-xl py-8 sm:py-16" data-test="not-found">
        <x-eyebrow dot="#a3a3a3">{{ __('404 · Not found') }}</x-eyebrow>
        @if ($id)
            <flux:heading size="2xl" level="1" class="mt-3 max-sm:text-3xl">
                {{ __('No tenement') }} <span class="whitespace-nowrap">{{ $id }}</span> {{ __('on the register or in the dead history.') }}
            </flux:heading>
            <flux:text class="mt-3 text-base">
                {{ __('Pegwatch holds the register as of its latest extract. Check') }}
                <flux:link href="{{ \App\Support\Format::TENGRAPH_URL }}" target="_blank" rel="noreferrer">TENGRAPH</flux:link>{{ __(', or search for the ID or its holder.') }}
            </flux:text>
        @else
            <flux:heading size="2xl" level="1" class="mt-3 max-sm:text-3xl">{{ __('Nothing at this address.') }}</flux:heading>
            <flux:text class="mt-3 text-base">{{ __('The link may be old or mistyped. Search for a tenement or holder, or go back to the map.') }}</flux:text>
        @endif
        <div class="mt-6 flex flex-wrap gap-2">
            <flux:modal.trigger name="search">
                <flux:button variant="primary" icon="magnifying-glass">{{ __('Search tenements and holders') }}</flux:button>
            </flux:modal.trigger>
            <flux:button :href="route('home')" variant="ghost" icon="map" wire:navigate>{{ __('Back to the map') }}</flux:button>
        </div>
    </div>
</x-layouts::app>
