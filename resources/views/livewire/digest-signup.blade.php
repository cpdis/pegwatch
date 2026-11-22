<?php

use App\Mail\DigestConfirmMail;
use App\Models\DigestSubscriber;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Email-only sign-up for the weekly WA digest. Double opt-in: nothing is sent
 * but the confirmation until the link in it is pressed. The reply is the same
 * whether or not the address is already subscribed.
 */
new class extends Component {
    #[Locked]
    public string $source = 'site';

    public bool $compact = false;

    public string $email = '';

    /** Honeypot: hidden from people, filled in by form bots. */
    public string $website = '';

    public bool $done = false;

    public function mount(string $source = 'site', bool $compact = false): void
    {
        $ref = request()->query('ref') ?? request()->query('utm_source');
        $this->source = Str::limit($source.(is_string($ref) && $ref !== '' ? ':'.Str::slug($ref) : ''), 60, '');
        $this->compact = $compact;
    }

    public function subscribe(): void
    {
        $this->email = Str::lower(trim($this->email));
        $this->validate(['email' => ['required', 'string', 'email:rfc', 'max:254']]);
        $email = $this->email;

        if ($this->website !== '') {
            $this->done = true;

            return;
        }
        $ipKey = 'digest-signup:ip:'.request()->ip();
        $emailKey = 'digest-signup:email:'.sha1($email);
        if (RateLimiter::tooManyAttempts($ipKey, 5) || RateLimiter::tooManyAttempts($emailKey, 3)) {
            $this->addError('email', __('Too many sign-ups from here. Try again later.'));

            return;
        }
        RateLimiter::hit($ipKey, 600);
        RateLimiter::hit($emailKey, 86400);

        $subscriber = DigestSubscriber::firstOrCreate(['email' => $email], ['source' => $this->source]);
        if (! $subscriber->isActive()) {
            if ($subscriber->unsubscribed_at !== null) {
                // Coming back after unsubscribing: confirm again before sending anything.
                $subscriber->forceFill(['confirmed_at' => null, 'unsubscribed_at' => null, 'source' => $this->source])->save();
            }
            Mail::to($email)->send(new DigestConfirmMail($subscriber));
        }

        $this->reset('email');
        $this->done = true;
    }
}; ?>

<div>
    @if ($done)
        <flux:callout icon="envelope" variant="success" :heading="__('Check your inbox')">
            <flux:callout.text>{{ __('Press the link in the email we just sent to start the weekly digest.') }}</flux:callout.text>
        </flux:callout>
    @else
        <form wire:submit="subscribe" class="space-y-2">
            @unless ($compact)
                <flux:label for="digest-email-{{ $source }}">{{ __('Email address') }}</flux:label>
            @endunless
            <div class="flex gap-2">
                <flux:input id="digest-email-{{ $source }}" wire:model="email" type="email" autocomplete="email" required
                            :placeholder="__('you@example.com')" :aria-label="__('Email address')" class="flex-1" />
                <flux:button type="submit" variant="primary">{{ __('Subscribe') }}</flux:button>
            </div>
            <div class="hidden" aria-hidden="true">
                <input type="text" wire:model="website" tabindex="-1" autocomplete="off">
            </div>
            <flux:error name="email" />
            <flux:text class="text-xs">{{ __('One email a week. Unsubscribe from any issue.') }}</flux:text>
        </form>
    @endif
</div>
