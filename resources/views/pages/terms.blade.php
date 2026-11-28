<?php

use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Terms of use and of the Prospector pre-sale. Plain language; reviewed by
 * the owner before launch (see docs/go-live.md).
 */
new #[Title('Terms')] class extends Component {
    public function with(): array
    {
        return ['business' => config('pegwatch.business'), 'price' => config('pegwatch.presale.price_label')];
    }
}; ?>

<div class="max-w-3xl space-y-4 text-sm leading-6 text-zinc-700 dark:text-zinc-300 [&_h2]:mt-8 [&_h2]:text-base [&_h2]:font-semibold [&_h2]:text-zinc-900 dark:[&_h2]:text-white">
    <x-eyebrow>{{ __('Legal') }}</x-eyebrow>
    <flux:heading size="xl" level="1">{{ __('Terms') }}</flux:heading>
    <p>{{ __('Last updated 28 November 2026.') }}
        @if ($business['name'])
            {{ __('Pegwatch is run by :name', ['name' => $business['name']]) }}@if ($business['abn']) {{ __('(ABN :abn)', ['abn' => $business['abn']]) }}@endif.
        @endif
        {{ __('Questions:') }} <x-support-email />.
    </p>

    <h2>{{ __('What Pegwatch is') }}</h2>
    <p>{{ __('Pegwatch copies the Western Australian mining tenement register from the department\'s open data each night and shows what changed. It is a screening aid, not advice and not the official register. The data can be late, wrong or incomplete, and it does not show pegs on the ground or land that needs consent. Confirm anything you act on in TENGRAPH and with the Mining Registrar.') }}</p>

    <h2>{{ __('Your account and alerts') }}</h2>
    <p>{{ __('You sign in with a link we email you. Keep your email account secure: anyone who can read it can sign in as you. Alerts are sent after a night when the register changes. An alert can be late or missed if the department publishes late or our check fails, so do not rely on Pegwatch alone for a deadline.') }}</p>

    <h2>{{ __('The Prospector pre-sale') }}</h2>
    <p>{{ __('A Prospector pre-order costs :price for one year. Your year starts the day Prospector goes live, not the day you pay. Until it goes live you can have a full refund for any reason: write to the address above. If Prospector does not go live, every pre-order is refunded in full. A reservation made before payment opens is never charged.', ['price' => $price]) }}</p>
    <p>{{ $business['gst_registered'] ? __('Prices include GST.') : __('No GST is charged: Pegwatch is not registered for GST.') }} {{ __('Stripe takes the payment; Pegwatch never sees your card.') }}</p>

    <h2>{{ __('Your rights') }}</h2>
    <p>{{ __('Nothing in these terms limits your rights under the Australian Consumer Law. Apart from those rights, Pegwatch is provided as it is, and we are not liable for a loss that comes from relying on the data.') }}</p>

    <h2>{{ __('Fair use') }}</h2>
    <p>{{ __('Do not overload the site, get around its limits, or use it to find or contact people named as holders other than for a lawful tenement matter. Tenement data is © State of Western Australia (DMPE), CC BY 4.0, and you may reuse it under that licence.') }}</p>

    <h2>{{ __('Changes and law') }}</h2>
    <p>{{ __('We may change these terms; the date above says when. The laws of Western Australia apply.') }}</p>
    <p><flux:link :href="route('privacy')" wire:navigate>{{ __('Privacy') }}</flux:link></p>
</div>
