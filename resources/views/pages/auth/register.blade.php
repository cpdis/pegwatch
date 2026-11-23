<x-layouts::auth :title="__('Create a free account')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Create a free account')" :description="__('One watch area and one holder watch are free. No password needed: we email you a link.')" />

        <x-pages::auth.partials.link-form :button="__('Email me a sign-up link')" />

        <flux:callout icon="map-pin">
            <flux:callout.heading>{{ __('Know the ground already?') }}</flux:callout.heading>
            <flux:callout.text>{{ __('Circle it on the map and give your email in one step.') }}</flux:callout.text>
            <x-slot name="actions">
                <flux:button size="sm" :href="route('watch.start')" wire:navigate>{{ __('Watch an area') }}</flux:button>
            </x-slot>
        </flux:callout>

        <flux:text class="text-sm">
            {{ __('Already have an account?') }}
            <flux:link :href="route('login')" wire:navigate>{{ __('Log in') }}</flux:link>
        </flux:text>
    </div>
</x-layouts::auth>
