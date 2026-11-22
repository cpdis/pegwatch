<?php

use App\Models\DigestSubscriber;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Unsubscribe')] class extends Component {
    #[Locked]
    public string $token = '';

    public bool $unsubscribed = false;

    public function mount(string $token): void
    {
        $s = DigestSubscriber::where('token', $token)->first();
        abort_unless($s, 404);
        $this->token = $token;
        $this->unsubscribed = $s->unsubscribed_at !== null;
    }

    public function unsubscribe(): void
    {
        DigestSubscriber::where('token', $this->token)->whereNull('unsubscribed_at')->update(['unsubscribed_at' => now()]);
        $this->unsubscribed = true;
    }

    public function resubscribe(): void
    {
        DigestSubscriber::where('token', $this->token)->update(['unsubscribed_at' => null]);
        $this->unsubscribed = false;
    }
}; ?>

<div class="max-w-md">
    <flux:heading size="xl" level="1">{{ __('Pegwatch weekly digest') }}</flux:heading>
    @if ($unsubscribed)
        <flux:callout class="mt-4" icon="check-circle" :heading="__('You\'re unsubscribed')">
            <flux:callout.text>{{ __('No more weekly digests will be sent to this address.') }}</flux:callout.text>
        </flux:callout>
        <flux:button class="mt-4" size="sm" variant="ghost" wire:click="resubscribe">{{ __('Undo') }}</flux:button>
    @else
        <flux:text class="mt-2">{{ __('Stop the weekly digest at this address?') }}</flux:text>
        <flux:button class="mt-4" variant="danger" wire:click="unsubscribe">{{ __('Unsubscribe') }}</flux:button>
    @endif
</div>
