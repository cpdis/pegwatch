<?php

use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * What Pegwatch keeps about people and why. Plain language; reviewed by the
 * owner before launch (see docs/go-live.md).
 */
new #[Title('Privacy')] class extends Component {
    public function with(): array
    {
        return ['business' => config('pegwatch.business')];
    }
}; ?>

<div class="max-w-3xl space-y-4 text-sm leading-6 text-zinc-700 dark:text-zinc-300 [&_h2]:mt-8 [&_h2]:text-base [&_h2]:font-semibold [&_h2]:text-zinc-900 dark:[&_h2]:text-white [&_ul]:list-disc [&_ul]:space-y-1 [&_ul]:ps-5">
    <x-eyebrow>{{ __('Legal') }}</x-eyebrow>
    <flux:heading size="xl" level="1">{{ __('Privacy') }}</flux:heading>
    <p>{{ __('Last updated 28 November 2026. Questions or requests:') }} <x-support-email />.</p>

    <h2>{{ __('What we keep about you') }}</h2>
    <ul>
        <li>{{ __('Your email address, and the areas, tenements and holders you watch.') }}</li>
        <li>{{ __('Which alerts we sent you and when, so you can see them in your account.') }}</li>
        <li>{{ __('For a pre-order: your email, the plan, and the payment status and amount Stripe reports. Stripe keeps your card; we never see it.') }}</li>
        <li>{{ __('Your IP address, briefly, to limit repeated sign-ups and searches.') }}</li>
    </ul>

    <h2>{{ __('Who else handles it') }}</h2>
    <ul>
        <li>{{ __('Laravel Cloud hosts the site and database; Cloudflare sits in front of it.') }}</li>
        <li>{{ __('Resend sends our emails.') }}</li>
        <li>{{ __('Stripe takes payments.') }}</li>
    </ul>
    <p>{{ __('We do not sell or share your details for marketing, and the site has no advertising trackers.') }}</p>

    <h2>{{ __('People on the register') }}</h2>
    <p>{{ __('The public register names the holders of each tenement, and some are people. Pegwatch shows a person\'s name only as the register gives it, never their address, and does not let search engines index pages about people.') }}</p>

    <h2>{{ __('Your choices') }}</h2>
    <p>{{ __('Every alert email has a one-click unsubscribe. You can delete your account in settings, which deletes your watches. To see or correct what we hold, or to ask for it to be deleted, write to the address above. We answer within 30 days. If you are not happy with our answer, you can complain to the Office of the Australian Information Commissioner.') }}</p>
    <p><flux:link :href="route('terms')" wire:navigate>{{ __('Terms') }}</flux:link></p>
</div>
