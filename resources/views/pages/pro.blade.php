<?php

use App\Presale\Stripe;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The paid plans and the Prospector pre-sale (plan step 2). With Stripe set
 * up, "Pre-order" goes to Stripe Checkout; without it, it records a
 * reservation so demand is still counted.
 */
new #[Title('Plans and pre-sale')] class extends Component {
    public string $email = '';

    public string $plan = 'prospector';

    #[Url(as: 'thanks')]
    public string $thanks = '';

    #[Locked]
    public string $source = 'pro-page';

    public function mount(): void
    {
        $ref = request()->query('ref') ?? request()->query('utm_source');
        if (is_string($ref) && $ref !== '') {
            $this->source = Str::limit('pro-page:'.Str::slug($ref), 60, '');
        }
    }

    public function choose(string $plan): void
    {
        $this->plan = $plan === 'team' ? 'team' : 'prospector';
        $this->resetValidation();
    }

    public function reserve(): void
    {
        $this->email = Str::lower(trim($this->email));
        $this->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'plan' => ['required', 'in:prospector,team'],
        ]);
        $key = 'presale:ip:'.request()->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('email', __('Too many attempts from here. Try again later.'));

            return;
        }
        RateLimiter::hit($key, 600);

        $checkout = $this->plan === 'prospector' && Stripe::enabled();
        $id = DB::table('presales')->insertGetId([
            'plan' => $this->plan, 'email' => $this->email, 'source' => $this->source,
            'status' => $checkout ? 'checkout' : 'reserved', 'created_at' => now(), 'updated_at' => now(),
        ]);

        if ($checkout) {
            try {
                $session = app(Stripe::class)->checkout($id, $this->email,
                    route('pro', ['thanks' => 'paid']), route('pro'));
            } catch (Throwable $e) {
                Log::error('Pre-sale checkout failed', ['presale' => $id, 'error' => $e->getMessage()]);
                DB::table('presales')->where('id', $id)->update(['status' => 'reserved', 'updated_at' => now()]);
                $this->thanks = 'reserved';

                return;
            }
            DB::table('presales')->where('id', $id)->update(['stripe_session_id' => $session['id'], 'updated_at' => now()]);
            $this->redirect($session['url']);

            return;
        }

        $this->thanks = $this->plan === 'team' ? 'team' : 'reserved';
    }

    public function with(): array
    {
        return [
            'price' => config('pegwatch.presale.price_label'),
            'paidCount' => DB::table('presales')->where('plan', 'prospector')->whereIn('status', ['paid', 'reserved'])
                ->distinct()->count('email'),
            'goal' => config('pegwatch.presale.goal'),
            'stripe' => Stripe::enabled(),
        ];
    }
}; ?>

@php
    $rows = [
        [__('Map of live, pending and dead tenements'), __('Yes'), __('Yes'), __('Yes')],
        [__('Tenement and holder pages'), __('Yes'), __('With full history'), __('With full history')],
        [__('Watch areas'), __('1 area, daily digest'), __('Unlimited'), __('Unlimited, shared across the team')],
        [__('Alert types'), __('Tenement dead, release scheduled'), __('All: applications, shrinkage, grants, transfers, expiry, release countdowns'), __('All')],
        [__('Holder watch'), __('1 holder (coming soon)'), __('Unlimited'), __('Unlimited')],
        [__('Exports'), __('No'), __('KML and GPX for GPS and detector apps'), __('Plus CSV, GeoJSON and an API')],
        [__('Weekly WA digest'), __('Yes'), __('Yes'), __('Yes')],
    ];
@endphp

<div class="max-w-4xl">
    <flux:heading size="xl" level="1">{{ __('Every alert, every area, for less than a tank of fuel') }}</flux:heading>
    <flux:text class="mt-2 max-w-2xl text-base">
        {{ __('The map and one watch area stay free. Prospector adds every alert type, unlimited watch areas and holder watches, and exports for your GPS and detector apps.') }}
    </flux:text>

    @if ($thanks === 'paid')
        <flux:callout class="mt-6" icon="check-circle" variant="success" :heading="__('Thank you, your pre-order is in')">
            <flux:callout.text>{{ __('Stripe will email your receipt. We\'ll email you when Prospector goes live, and your first year starts then.') }}</flux:callout.text>
        </flux:callout>
    @elseif ($thanks === 'reserved')
        <flux:callout class="mt-6" icon="check-circle" variant="success" :heading="__('Your place is reserved')">
            <flux:callout.text>{{ __('We\'ll email you when the pre-sale opens, at the pre-sale price. Nothing is charged until you choose to pay.') }}</flux:callout.text>
        </flux:callout>
    @elseif ($thanks === 'team')
        <flux:callout class="mt-6" icon="check-circle" variant="success" :heading="__('Thanks, we\'ll be in touch')">
            <flux:callout.text>{{ __('We\'ll email you when team plans with shared watch lists are ready.') }}</flux:callout.text>
        </flux:callout>
    @endif

    <div class="mt-8 grid gap-4 md:grid-cols-2">
        <flux:card @class(['ring-2 ring-zinc-800 dark:ring-white' => $plan === 'prospector'])>
            <div class="flex items-baseline justify-between gap-2">
                <flux:heading size="lg">{{ __('Prospector') }}</flux:heading>
                <flux:badge color="amber" size="sm">{{ __('Pre-sale') }}</flux:badge>
            </div>
            <flux:text class="mt-1"><span class="text-2xl font-semibold text-zinc-900 dark:text-white">{{ $price }}</span> {{ __('a year') }}</flux:text>
            <flux:text class="mt-2 text-sm">{{ __('For prospectors and detectorists. Your year starts the day Prospector goes live, and you can ask for a full refund until then.') }}</flux:text>
            <flux:button class="mt-4 w-full" :variant="$plan === 'prospector' ? 'primary' : 'outline'" wire:click="choose('prospector')" x-on:click="$nextTick(() => document.getElementById('presale-email').focus())">
                {{ $stripe ? __('Pre-order Prospector') : __('Reserve Prospector') }}
            </flux:button>
        </flux:card>
        <flux:card @class(['ring-2 ring-zinc-800 dark:ring-white' => $plan === 'team'])>
            <flux:heading size="lg">{{ __('Team') }}</flux:heading>
            <flux:text class="mt-1"><span class="text-2xl font-semibold text-zinc-900 dark:text-white">A$49</span> {{ __('a month per team, not per user') }}</flux:text>
            <flux:text class="mt-2 text-sm">{{ __('For consultants and junior explorers: shared watch lists for the whole team, plus CSV, GeoJSON and an API.') }}</flux:text>
            <flux:button class="mt-4 w-full" :variant="$plan === 'team' ? 'primary' : 'outline'" wire:click="choose('team')" x-on:click="$nextTick(() => document.getElementById('presale-email').focus())">{{ __('Register interest') }}</flux:button>
        </flux:card>
    </div>

    <form wire:submit="reserve" class="mt-4 max-w-xl space-y-2">
        <flux:label for="presale-email">
            {{ $plan === 'team' ? __('Email for team plan news') : ($stripe ? __('Email for your receipt') : __('Email to hold your place')) }}
        </flux:label>
        <div class="flex gap-2">
            <flux:input id="presale-email" wire:model="email" type="email" autocomplete="email" required :placeholder="__('you@example.com')" class="flex-1" />
            <flux:button type="submit" variant="primary">
                {{ $plan === 'team' ? __('Register') : ($stripe ? __('Continue to payment') : __('Reserve')) }}
            </flux:button>
        </div>
        <flux:error name="email" />
        <flux:text class="text-xs">
            @if ($plan === 'prospector' && $stripe)
                {{ __('Payment is taken by Stripe. Pegwatch never sees your card.') }}
            @else
                {{ __('Nothing is charged.') }}
            @endif
        </flux:text>
    </form>

    @if ($paidCount >= 5)
        <flux:text class="mt-3 text-sm">{{ trans_choice(':n prospector has signed up so far.|:n prospectors have signed up so far.', $paidCount, ['n' => $paidCount]) }}</flux:text>
    @endif

    <flux:heading size="lg" class="mt-12">{{ __('What each plan includes') }}</flux:heading>
    <div class="mt-2 overflow-x-auto">
        <flux:table class="min-w-[40rem]">
            <flux:table.columns>
                <flux:table.column></flux:table.column>
                <flux:table.column>{{ __('Free') }}</flux:table.column>
                <flux:table.column>{{ __('Prospector') }}</flux:table.column>
                <flux:table.column>{{ __('Team') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($rows as $r)
                    <flux:table.row>
                        <flux:table.cell class="whitespace-normal font-medium text-zinc-800 dark:text-white">{{ $r[0] }}</flux:table.cell>
                        <flux:table.cell class="whitespace-normal">{{ $r[1] }}</flux:table.cell>
                        <flux:table.cell class="whitespace-normal">{{ $r[2] }}</flux:table.cell>
                        <flux:table.cell class="whitespace-normal">{{ $r[3] }}</flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </div>

    <flux:text class="mt-6 text-sm">
        {{ __('Not ready to pay?') }} <flux:link :href="route('digest')" wire:navigate>{{ __('Get the free weekly digest') }}</flux:link>
        {{ __('or') }} <flux:link :href="route('register')" wire:navigate>{{ __('set up a free watch area') }}</flux:link>.
    </flux:text>
</div>
