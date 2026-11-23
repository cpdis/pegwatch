@props(['title' => null, 'fullBleed' => false])
@php($run = \App\Support\Register::latestRun())
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="flex min-h-screen flex-col bg-white dark:bg-zinc-800">
        <flux:header class="border-b border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.toggle class="mr-2 lg:hidden" icon="bars-2" inset="left" />

            <x-app-logo href="{{ route('home') }}" wire:navigate />

            <flux:navbar class="-mb-px max-lg:hidden">
                <flux:navbar.item icon="map" :href="route('home')" :current="request()->routeIs('home')" wire:navigate>
                    {{ __('Map') }}
                </flux:navbar.item>
                <flux:navbar.item icon="bolt" :href="route('activity')" :current="request()->routeIs('activity')" wire:navigate>
                    {{ __('Activity') }}
                </flux:navbar.item>
                @auth
                    <flux:navbar.item icon="bell-alert" :href="route('watch-areas.index')" :current="request()->routeIs('watch-areas.*')" wire:navigate>
                        {{ __('My watch area') }}
                    </flux:navbar.item>
                @endauth
            </flux:navbar>

            <flux:spacer />

            <flux:modal.trigger name="search" shortcut="cmd.k" class="max-sm:hidden">
                <flux:input as="button" size="sm" class="w-full max-w-xs" icon="magnifying-glass" kbd="⌘K" :aria-label="__('Search tenements and holders')">
                    {{ __('Tenement or holder') }}
                </flux:input>
            </flux:modal.trigger>
            <flux:modal.trigger name="search" shortcut="ctrl.k" class="sm:hidden">
                <flux:button variant="ghost" size="sm" icon="magnifying-glass" :aria-label="__('Search tenements and holders')" />
            </flux:modal.trigger>

            @auth
                <flux:dropdown position="bottom" align="end" class="ms-3">
                    <flux:profile :initials="auth()->user()->initials()" icon-trailing="chevron-down" data-test="user-menu" />
                    <flux:menu>
                        <div class="px-2 py-1.5 text-sm">
                            <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                            <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                        </div>
                        <flux:menu.separator />
                        <flux:menu.item :href="route('watch-areas.index')" icon="bell-alert" wire:navigate>{{ __('My watch area') }}</flux:menu.item>
                        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>{{ __('Settings') }}</flux:menu.item>
                        <flux:menu.separator />
                        <form method="POST" action="{{ route('logout') }}" class="w-full">
                            @csrf
                            <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full cursor-pointer" data-test="logout-button">
                                {{ __('Log out') }}
                            </flux:menu.item>
                        </form>
                    </flux:menu>
                </flux:dropdown>
            @else
                <flux:button variant="ghost" size="sm" :href="route('login')" class="ms-3 max-sm:hidden" wire:navigate>{{ __('Log in') }}</flux:button>
                <flux:button variant="primary" size="sm" :href="route('register')" class="ms-2 shrink-0" wire:navigate>{{ __('Free alerts') }}</flux:button>
            @endauth
        </flux:header>

        <flux:sidebar collapsible="mobile" sticky class="border-e border-zinc-200 bg-zinc-50 lg:hidden dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ route('home') }}" wire:navigate />
                <flux:sidebar.collapse />
            </flux:sidebar.header>
            <flux:sidebar.nav>
                <flux:sidebar.item icon="map" :href="route('home')" :current="request()->routeIs('home')" wire:navigate>{{ __('Map') }}</flux:sidebar.item>
                <flux:sidebar.item icon="bolt" :href="route('activity')" :current="request()->routeIs('activity')" wire:navigate>{{ __('Activity') }}</flux:sidebar.item>
                @auth
                    <flux:sidebar.item icon="bell-alert" :href="route('watch-areas.index')" wire:navigate>{{ __('My watch area') }}</flux:sidebar.item>
                @else
                    <flux:sidebar.item icon="arrow-right-end-on-rectangle" :href="route('login')" wire:navigate>{{ __('Log in') }}</flux:sidebar.item>
                    <flux:sidebar.item icon="bell-alert" :href="route('register')" wire:navigate>{{ __('Free alerts') }}</flux:sidebar.item>
                @endauth
            </flux:sidebar.nav>
        </flux:sidebar>

        <livewire:search />

        <div @class(['flex flex-1 flex-col' => $fullBleed, 'flex-1' => ! $fullBleed])>
            {{ $slot }}
        </div>

        <footer class="border-t border-zinc-200 px-4 py-3 text-xs text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
            @if ($run?->tenements_extract)
                Register as of the department's extract of {{ \App\Support\Format::awst($run->tenements_extract) }} AWST{{ $run->stale ? ' (not refreshed since the previous night)' : '' }}.
            @endif
            A screening aid, not advice that ground can be applied for: confirm in
            <a class="underline" href="{{ \App\Support\Format::TENGRAPH_URL }}" target="_blank" rel="noreferrer">TENGRAPH</a>
            and with the Mining Registrar. Data © State of Western Australia (Department of Mines, Petroleum and Exploration), CC BY 4.0, changes made.
        </footer>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
