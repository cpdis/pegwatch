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
        <flux:button class="mt-4" :href="route('home')" wire:navigate>{{ __('Open the map') }}</flux:button>
    @else
        <flux:text class="mt-2">{{ __('Press the button to start one email a week about changes on the WA tenement register.') }}</flux:text>
        <flux:button class="mt-4" variant="primary" wire:click="confirm">{{ __('Confirm my subscription') }}</flux:button>
    @endif
</div>
