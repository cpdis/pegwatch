<?php

use App\Models\DigestSubscriber;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Confirmation takes a button press, not just the link: mail scanners open
 * links, and a scanner must not be able to subscribe someone.
 */
new #[Title('Confirm the weekly digest')] class extends Component {
    #[Locked]
    public string $token = '';

    public bool $confirmed = false;

    public function mount(string $token): void
    {
        $s = DigestSubscriber::where('token', $token)->first();
        abort_unless($s, 404);
        $this->token = $token;
        $this->confirmed = $s->isActive();
    }

    public function confirm(): void
    {
        $s = DigestSubscriber::where('token', $this->token)->firstOrFail();
        $s->forceFill(['confirmed_at' => $s->confirmed_at ?? now(), 'unsubscribed_at' => null])->save();
        $this->confirmed = true;
    }
}; ?>

<div class="max-w-md">
    <flux:heading size="xl" level="1">{{ __('Pegwatch weekly digest') }}</flux:heading>
    @if ($confirmed)
        <flux:callout class="mt-4" icon="check-circle" variant="success" :heading="__('You\'re subscribed')">
            <flux:callout.text>{{ __('The next issue arrives after Monday night\'s update. Every issue has an unsubscribe link.') }}</flux:callout.text>
        </flux:callout>
        <flux:card class="mt-6">
            <flux:heading>{{ __('Next: one area, the night it changes') }}</flux:heading>
            <flux:text class="mt-1">{{ __('The digest covers all of WA once a week. A watch area emails you the changes in one area the morning after the register shows them.') }}</flux:text>
            <div class="mt-4 flex flex-wrap gap-2">
                <flux:button variant="primary" icon="map-pin" :href="Route::has('watch.start') ? route('watch.start') : route('register')" wire:navigate>{{ __('Set up a free watch area') }}</flux:button>
                <flux:button variant="ghost" :href="route('home')" wire:navigate>{{ __('Open the map') }}</flux:button>
            </div>
        </flux:card>
    @else
        <flux:text class="mt-2">{{ __('Press the button to start one email a week about changes on the WA tenement register.') }}</flux:text>
        <flux:button class="mt-4" variant="primary" wire:click="confirm">{{ __('Confirm my subscription') }}</flux:button>
    @endif
</div>
