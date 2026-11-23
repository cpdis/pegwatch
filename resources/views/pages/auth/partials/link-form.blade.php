{{-- "Email me a login link": the way in for every account, new or old. Errors use the "link" bag so a password form on the same page keeps its own. --}}
@props(['button' => __('Email me a login link')])
@if (session('link_sent'))
    <flux:callout icon="envelope" variant="success" :heading="__('Check your inbox')">
        <flux:callout.text>{{ __('We sent a link to :email. It works once, for the next :n minutes.', ['email' => session('link_sent'), 'n' => \App\Auth\MagicLink::LOGIN_MINUTES]) }}</flux:callout.text>
    </flux:callout>
@endif
<form method="POST" action="{{ route('login.link.send') }}" class="flex flex-col gap-4">
    @csrf
    <flux:input name="email" error:bag="link" :label="__('Email address')" :value="old('email')" type="email" required autofocus
                autocomplete="email" placeholder="you@example.com" />
    <div class="hidden" aria-hidden="true">
        <input type="text" name="website" tabindex="-1" autocomplete="off">
    </div>
    <flux:button variant="primary" type="submit" class="w-full" data-test="login-link-button">{{ $button }}</flux:button>
</form>
