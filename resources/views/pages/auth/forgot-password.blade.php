<x-layouts::auth :title="__('Forgot password')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Forgot your password?')" :description="__('We email you a link to set a new one.')" />

        <x-auth-session-status :status="session('status')" />

        <form method="POST" action="{{ route('password.email') }}" class="flex flex-col gap-6">
            @csrf

            <flux:input
                name="email"
                :label="__('Email address')"
                type="email"
                required
                autofocus
                placeholder="you@example.com"
            />

            <flux:button variant="primary" type="submit" class="w-full" data-test="email-password-reset-link-button">
                {{ __('Email me a reset link') }}
            </flux:button>
        </form>

        <flux:text class="text-sm">
            {{ __('You don\'t need a password to use Pegwatch.') }}
            <flux:link :href="route('login')" wire:navigate>{{ __('Email me a login link instead') }}</flux:link>
        </flux:text>
    </div>
</x-layouts::auth>
