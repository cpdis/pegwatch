@props(['title' => null, 'fullBleed' => false])
@php
    $fresh = \App\Support\Freshness::current();
    // The header button starts the email-first watch flow once it exists (f-accounts).
    $alertsUrl = Route::has('watch.start') ? route('watch.start') : route('register');
    $nav = [
        ['label' => __('Map'), 'icon' => 'map', 'route' => 'home', 'current' => 'home'],
        ['label' => __('Activity'), 'icon' => 'bolt', 'route' => 'activity', 'current' => 'activity*'],
        ['label' => __('Weekly digest'), 'icon' => 'envelope', 'route' => 'digest', 'current' => 'digest*'],
        ['label' => __('Plans'), 'icon' => 'sparkles', 'route' => 'pro', 'current' => 'pro'],
    ];
    if (auth()->check()) {
        $nav[] = ['label' => __('My watches'), 'icon' => 'bell-alert', 'route' => 'watch-areas.index', 'current' => 'watch-areas.*'];
    }
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="flex min-h-screen flex-col bg-white text-zinc-900 antialiased dark:bg-zinc-900 dark:text-zinc-100">
        {{--
            The frame (header, phone menu, footer) is near-black in both
            appearances; the `dark` class gives the Flux parts inside it their
            dark styles. Header 3.5rem + footer 4rem: the map page's height
            calc relies on the 7.5rem.
        --}}
        <flux:header class="dark gap-1 bg-ink px-3! text-white shadow-[inset_0_-1px_0_rgb(255_255_255/0.08)] sm:gap-2 sm:px-6! lg:px-8!">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" :aria-label="__('Menu')" />

            <x-app-logo :href="route('home')" wire:navigate :aria-label="\App\Support\PageMeta::SITE_NAME" class="me-1 sm:me-3 lg:me-5 max-sm:[&>span]:hidden" />

            <flux:navbar class="-mb-px max-lg:hidden">
                @foreach ($nav as $item)
                    <flux:navbar.item :href="route($item['route'])" :current="request()->routeIs($item['current'])" wire:navigate class="data-current:text-white!">
                        {{ $item['label'] }}
                    </flux:navbar.item>
                @endforeach
            </flux:navbar>

            <flux:spacer class="max-sm:hidden" />

            @if ($fresh)
                <x-layouts::app.freshness :fresh="$fresh" class="max-sm:hidden" />
            @endif

            {{-- Triggers are display: contents, so widths go on the inputs. --}}
            <flux:modal.trigger name="search" shortcut="cmd.k" class="max-sm:hidden">
                <flux:input as="button" size="sm" icon="magnifying-glass" kbd="⌘K" :placeholder="__('Tenement or holder')" class="max-w-56 lg:max-w-64 xl:max-w-xs" :aria-label="__('Search tenements and holders')" />
            </flux:modal.trigger>
            {{-- Phones: a wide pill to tap, not a bare icon. --}}
            <flux:modal.trigger name="search" shortcut="ctrl.k" class="sm:hidden">
                <flux:input as="button" size="sm" icon="magnifying-glass" :placeholder="__('Search')" class="h-9! min-w-0 flex-1 rounded-full! whitespace-nowrap" :aria-label="__('Search tenements and holders')" data-test="search-pill" />
            </flux:modal.trigger>

            @auth
                <flux:dropdown position="bottom" align="end" class="ms-1 sm:ms-2">
                    <flux:profile :initials="auth()->user()->initials()" icon-trailing="chevron-down" data-test="user-menu" />
                    <flux:menu>
                        <div class="px-2 py-1.5 text-sm">
                            <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                            <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                        </div>
                        <flux:menu.separator />
                        <flux:menu.item :href="route('watch-areas.index')" icon="bell-alert" wire:navigate>{{ __('My watches') }}</flux:menu.item>
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
                <flux:button variant="ghost" size="sm" :href="route('login')" class="ms-1 max-sm:hidden" wire:navigate>{{ __('Log in') }}</flux:button>
                <flux:button variant="primary" size="sm" :href="$alertsUrl" class="ms-1 shrink-0" wire:navigate>{{ __('Free alerts') }}</flux:button>
            @endauth
        </flux:header>

        <flux:sidebar collapsible="mobile" sticky class="dark border-e border-white/10 bg-ink lg:hidden">
            <flux:sidebar.header>
                <x-app-logo :href="route('home')" wire:navigate />
                <flux:sidebar.collapse />
            </flux:sidebar.header>
            <flux:sidebar.nav>
                @foreach ($nav as $item)
                    <flux:sidebar.item :icon="$item['icon']" :href="route($item['route'])" :current="request()->routeIs($item['current'])" wire:navigate>{{ $item['label'] }}</flux:sidebar.item>
                @endforeach
                @guest
                    <flux:sidebar.item icon="arrow-right-end-on-rectangle" :href="route('login')" wire:navigate>{{ __('Log in') }}</flux:sidebar.item>
                    <flux:sidebar.item icon="bell-alert" :href="$alertsUrl" wire:navigate>{{ __('Free alerts') }}</flux:sidebar.item>
                @endguest
            </flux:sidebar.nav>
            @if ($fresh)
                <flux:sidebar.spacer />
                <x-layouts::app.freshness :fresh="$fresh" inline class="pb-2 sm:hidden" />
            @endif
        </flux:sidebar>

        <livewire:search />

        <div @class(['flex flex-1 flex-col' => $fullBleed, 'flex-1' => ! $fullBleed])>
            {{ $slot }}
        </div>

        <footer class="flex items-center bg-ink px-4 py-3 text-xs leading-5 text-zinc-400 sm:px-6 md:min-h-16 lg:px-8" data-test="site-footer">
            <p>
                {{ __('A screening aid, not advice. Confirm in') }}
                <a class="text-zinc-200 underline decoration-zinc-600 underline-offset-2 hover:text-white" href="{{ \App\Support\Format::TENGRAPH_URL }}" target="_blank" rel="noreferrer">TENGRAPH</a>
                {{ __('and with the Mining Registrar. Data © State of WA') }}
                (<abbr class="no-underline" title="Department of Mines, Petroleum and Exploration">DMPE</abbr>), CC BY 4.0, {{ __('changes made.') }}
                <a class="ms-1 font-medium whitespace-nowrap text-white underline decoration-accent underline-offset-2" href="{{ route('data') }}" wire:navigate>{{ __('Data and disclaimer') }}</a>
                <a class="ms-1 font-medium whitespace-nowrap text-white underline decoration-accent underline-offset-2" href="{{ route('use-cases') }}" wire:navigate>{{ __('How people use it') }}</a>
                <a class="ms-1 font-medium whitespace-nowrap text-white underline decoration-accent underline-offset-2" href="{{ route('agents') }}" wire:navigate>{{ __('For agents') }}</a>
            </p>
        </footer>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
