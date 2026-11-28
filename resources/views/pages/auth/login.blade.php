@php
    // B18: a guest sent here from a page that needs an account sees why.
    $intended = (string) parse_url((string) session('url.intended', ''), PHP_URL_PATH);
    $why = match (true) {
        $intended === '' => null,
        str_starts_with($intended, '/watch-areas') || $intended === '/dashboard' => __('Log in to see your watch area.'),
        str_starts_with($intended, '/settings') => __('Log in to change your settings.'),
        default => __('Log in to continue.'),
    };
    $usePassword = $errors->has('email') || $errors->has('password');
@endphp
<x-layouts::auth :title="__('Log in')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="$why ?? __('Log in')" :description="__('We email you a link to log in. No password needed.')" />

        <x-auth-session-status :status="session('status')" />

        <x-pages::auth.partials.link-form />

        <div x-data="{ open: @js($usePassword) }" class="flex flex-col gap-4">
            <flux:button variant="ghost" size="sm" inset="left" class="self-start" x-on:click="open = ! open" x-bind:aria-expanded="open">
                {{ __('Use your password instead') }}
            </flux:button>

            <form x-show="open" x-cloak method="POST" action="{{ route('login.store') }}" class="flex flex-col gap-4">
                @csrf
                <flux:input name="email" :label="__('Email address')" :value="old('email')" type="email" required autocomplete="username" placeholder="you@example.com" />

                <div class="relative">
                    <flux:input name="password" :label="__('Password')" type="password" required autocomplete="current-password" viewable />
                    @if (Route::has('password.request'))
                        <flux:link class="absolute end-0 top-0 text-sm" :href="route('password.request')" wire:navigate>{{ __('Forgot it?') }}</flux:link>
                    @endif
                </div>

                <flux:checkbox name="remember" :label="__('Keep me logged in')" :checked="old('remember')" />

                <flux:button variant="filled" type="submit" class="w-full" data-test="login-button">{{ __('Log in with password') }}</flux:button>
            </form>
        </div>

        <flux:text class="text-sm">
            {{ __('New to Pegwatch?') }}
            <flux:link :href="route('watch.start')" wire:navigate>{{ __('Watch an area for free') }}</flux:link>
        </flux:text>
    </div>
</x-layouts::auth>
