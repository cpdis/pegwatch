{{--
    OAuth consent (Passport's authorization view): an agent app asks to act
    for the signed-in person. It names the app and where it sends them back,
    because any app can register itself, and says exactly what it can do.
--}}
@php
    $redirect = (string) $request->query('redirect_uri', $client->redirect_uris[0] ?? '');
    $returnsTo = parse_url($redirect, PHP_URL_HOST) ?: (parse_url($redirect, PHP_URL_SCHEME) ? parse_url($redirect, PHP_URL_SCHEME).'://' : $redirect);
@endphp
<x-layouts::auth :title="__('Connect an agent')">
    <div class="flex flex-col gap-6">
        <div class="space-y-2">
            <flux:heading size="xl" level="1">{{ __('Let :app use your Pegwatch account?', ['app' => $client->name]) }}</flux:heading>
            <flux:text>{{ __('Signed in as :email.', ['email' => $user->email]) }}</flux:text>
        </div>

        <div class="space-y-2">
            <flux:text class="font-medium text-zinc-900 dark:text-white">{{ __('It will be able to:') }}</flux:text>
            <ul class="list-disc space-y-1 ps-5 text-sm text-zinc-600 dark:text-zinc-300">
                <li>{{ __('see your watches and the alert emails sent for them') }}</li>
                <li>{{ __('start, change, stop and delete your watches') }}</li>
                <li>{{ __('look up the register, as anyone can') }}</li>
            </ul>
            <flux:text class="text-sm">{{ __('It can\'t change your email address, password or sign-in, and alerts only ever go to your own address.') }}</flux:text>
        </div>

        <flux:callout icon="information-circle">
            <flux:callout.text>
                {{ __('Pegwatch hasn\'t checked this app: any agent can ask. Only allow it if you just started connecting :app, and it sends you back to :host.', ['app' => $client->name, 'host' => $returnsTo]) }}
            </flux:callout.text>
        </flux:callout>

        <div class="flex flex-col gap-2">
            <form method="POST" action="{{ route('passport.authorizations.approve') }}">
                @csrf
                <input type="hidden" name="auth_token" value="{{ $authToken }}">
                <flux:button type="submit" variant="primary" class="w-full" data-test="oauth-approve">{{ __('Allow') }}</flux:button>
            </form>
            <form method="POST" action="{{ route('passport.authorizations.deny') }}">
                @csrf
                @method('DELETE')
                <input type="hidden" name="auth_token" value="{{ $authToken }}">
                <flux:button type="submit" variant="ghost" class="w-full" data-test="oauth-deny">{{ __('Cancel') }}</flux:button>
            </form>
        </div>

        <flux:text class="text-xs">{{ __('You can disconnect it at any time in Settings, under Connected agents.') }}</flux:text>
    </div>
</x-layouts::auth>
