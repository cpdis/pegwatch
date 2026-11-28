<?php

use App\Alerts\AlertPreview;
use App\Presale\Presales;
use App\Presale\Stripe;
use App\Support\ClientIp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The paid plans and the Prospector pre-sale (plan step 2). With Stripe set
 * up, "Pre-order" goes to Stripe Checkout; without it, or once the pre-sale
 * places are gone, it records a reservation so demand is still counted.
 */
new #[Title('Plans')] class extends Component {
    public string $email = '';

    public string $plan = 'prospector';

    #[Url(as: 'thanks')]
    public string $thanks = '';

    /** Stripe's Checkout Session id on the way back from paying; the page checks it. */
    #[Url(as: 'session_id')]
    public string $sessionId = '';

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

    /**
     * On ?thanks=paid: 'confirmed' only when this session is paid (from the webhook or
     * asked of Stripe now), 'pending' when Stripe has not finished, else 'unknown'. A
     * bare ?thanks=paid never claims a payment.
     */
    #[Computed]
    public function paidState(): string
    {
        $row = $this->sessionId !== '' ? DB::table('presales')->where('stripe_session_id', $this->sessionId)->first() : null;
        if (! $row) {
            return 'unknown';
        }
        if ($row->status === 'paid') {
            return 'confirmed';
        }
        // The webhook can land after the buyer does: ask Stripe directly.
        $session = Stripe::enabled() ? app(Stripe::class)->session($this->sessionId) : null;
        if ($session && Presales::markPaid($session)) {
            return 'confirmed';
        }

        return 'pending';
    }

    public function reserve(): void
    {
        $this->email = Str::lower(trim($this->email));
        $this->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'plan' => ['required', 'in:prospector,team'],
        ]);
        $key = 'presale:ip:'.ClientIp::of();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('email', __('Too many attempts from here. Try again later.'));

            return;
        }
        RateLimiter::hit($key, 600);

        $soldOut = $this->plan === 'prospector' && Presales::soldOut();
        $checkout = $this->plan === 'prospector' && Stripe::enabled() && ! $soldOut;
        $id = DB::table('presales')->insertGetId([
            'plan' => $this->plan, 'email' => $this->email, 'source' => $this->source,
            'status' => $checkout ? 'checkout' : 'reserved', 'created_at' => now(), 'updated_at' => now(),
        ]);

        if ($checkout) {
            try {
                $session = app(Stripe::class)->checkout($id, $this->email,
                    route('pro', ['thanks' => 'paid']), route('pro'));
            } catch (Throwable $e) {
                // Say so: a reservation here would tell a buyer they hold a place they never paid for.
                Log::error('Pre-sale checkout failed', ['presale' => $id, 'error' => $e->getMessage()]);
                DB::table('presales')->where('id', $id)->update(['status' => 'failed', 'updated_at' => now()]);
                $this->addError('email', __('We could not open the payment page. Nothing was charged. Try again in a few minutes, or write to :email.', [
                    'email' => config('pegwatch.business.support_email'),
                ]));

                return;
            }
            DB::table('presales')->where('id', $id)->update(['stripe_session_id' => $session['id'], 'updated_at' => now()]);
            $this->redirect($session['url']);

            return;
        }

        $this->thanks = match (true) {
            $this->plan === 'team' => 'team',
            $soldOut => 'waitlist',
            default => 'reserved',
        };
        $this->reset('email');
        // The confirmation is at the top of the page.
        $this->js("window.scrollTo({ top: 0, behavior: 'smooth' })");
    }

    public function with(): array
    {
        return [
            'price' => config('pegwatch.presale.price_label'),
            'paidCount' => Presales::paidCount(),
            'goal' => config('pegwatch.presale.goal'),
            'soldOut' => Presales::soldOut(),
            'stripe' => Stripe::enabled(),
            'preview' => AlertPreview::sample(),
            'business' => config('pegwatch.business'),
        ];
    }
}; ?>

@php
    // true: included, false: not included, string: included with a note.
    $rows = [
        [__('Map of live, pending and dead tenements'), true, true, true],
        [__('Tenement and holder pages, with the history Pegwatch holds'), true, true, true],
        [__('Watch areas'), __('1 area'), __('Unlimited'), __('Unlimited, shared across the team')],
        [__('Holder watches'), __('1 holder'), __('Unlimited'), __('Unlimited')],
        [__('Alerts'), __('Tenement dead and release scheduled, with a calendar file and reminders'), __('Every type: applications, shrinkage, grants, transfers, expiry'), __('Every type')],
        [__('GPX and KML exports for GPS and detector apps'), false, true, true],
        [__('CSV export of a holder\'s tenements'), false, true, true],
        [__('Lookups for AI agents and scripts (MCP server and API)'), true, true, true],
        [__('Bulk CSV and GeoJSON exports of every watch, and an API to manage the team\'s watches'), false, false, true],
        [__('Weekly WA digest'), true, true, true],
    ];
    $plans = [__('Free'), __('Prospector'), __('Team')];
    $tax = $business['gst_registered'] ? __('Prices include GST.') : __('Prices are in Australian dollars. No GST is charged: Pegwatch is not registered for GST.');
    $toEmail = "\$nextTick(() => { const el = document.getElementById('presale-email'); el.scrollIntoView({ behavior: 'smooth', block: 'center' }); el.focus({ preventScroll: true }) })";
@endphp

<div class="max-w-5xl">
    <x-eyebrow>{{ __('Plans') }}</x-eyebrow>
    <flux:heading size="xl" level="1" class="mt-1">{{ __('Every alert, every area, for less than a tank of fuel') }}</flux:heading>
    <flux:text class="mt-2 max-w-2xl text-base">
        {{ __('The map, one watch area and one holder watch stay free. Prospector adds every alert type, unlimited watches, and exports for your GPS and detector apps.') }}
    </flux:text>
    <flux:link class="mt-2 inline-block text-sm" :href="route('use-cases')" wire:navigate>{{ __('See how a prospector, a tenement manager, an investor and a consultant might use it') }}</flux:link>

    @if ($thanks === 'paid' && $this->paidState === 'confirmed')
        <flux:callout class="mt-6" icon="check-circle" variant="success" :heading="__('Thank you, your pre-order is in')">
            <flux:callout.text>{{ __('Stripe will email your receipt, and we\'ve emailed what happens next. Your first year starts the day Prospector goes live.') }}</flux:callout.text>
        </flux:callout>
    @elseif ($thanks === 'paid' && $this->paidState === 'pending')
        <flux:callout class="mt-6" icon="clock" variant="warning" :heading="__('Your payment is still going through')">
            <flux:callout.text>{{ __('Stripe has not confirmed it yet. We\'ll email you when it does. If no email comes within an hour, write to :email.', ['email' => $business['support_email']]) }}</flux:callout.text>
        </flux:callout>
    @elseif ($thanks === 'paid')
        <flux:callout class="mt-6" icon="information-circle" :heading="__('We could not find that payment')">
            <flux:callout.text>{{ __('If you paid, Stripe has emailed you a receipt. Write to :email with it and we\'ll sort it out.', ['email' => $business['support_email']]) }}</flux:callout.text>
        </flux:callout>
    @elseif ($thanks === 'waitlist')
        <flux:callout class="mt-6" icon="check-circle" variant="success" :heading="__('You\'re on the list')">
            <flux:callout.text>{{ __('The pre-sale places are gone. We\'ll email you when Prospector goes live. Nothing is charged.') }}</flux:callout.text>
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

    {{-- What you get, shown rather than listed: a real alert, and your ground on a phone. --}}
    <div class="mt-8 grid items-start gap-6 md:grid-cols-[minmax(0,1fr)_13rem]">
        <div>
            <flux:heading size="sm" class="text-zinc-500">{{ __('An alert, sent the night the register changes') }}</flux:heading>
            <x-alert-preview :preview="$preview" class="mt-2 shadow-sm" />
            <flux:text class="mt-2 text-xs">{{ __('Built from the latest changes on the register. Free alerts cover deaths and releases; Prospector adds applications, grants, transfers, shrinkage and expiry.') }}</flux:text>
        </div>
        <figure>
            <flux:heading size="sm" class="text-zinc-500">{{ __('Your watch on your phone') }}</flux:heading>
            <svg viewBox="0 0 220 400" role="img" class="mt-2 w-full max-w-[13rem]" aria-label="{{ __('Illustration: tenement outlines exported as GPX, shown in a GPS app on a phone') }}">
                <rect x="4" y="4" width="212" height="392" rx="30" fill="#18181b" />
                <rect x="14" y="14" width="192" height="372" rx="22" fill="#eef0e6" />
                <g stroke="#c9ccbb" stroke-width="1" fill="none">
                    <path d="M14 90 C70 70 120 120 206 96" /><path d="M14 150 C80 130 130 180 206 160" /><path d="M14 220 C60 200 150 250 206 228" /><path d="M14 290 C90 270 120 320 206 300" />
                </g>
                <path d="M30 250 L60 210 L95 205 L120 170 L150 160 L178 120" stroke="#2563eb" stroke-width="2" stroke-dasharray="4 3" fill="none" />
                <polygon points="40,70 120,62 128,140 46,150" fill="#dc2626" fill-opacity=".12" stroke="#dc2626" stroke-width="2" />
                <text x="50" y="88" font-size="11" font-weight="700" fill="#991b1b">E 26/180</text>
                <polygon points="110,170 190,176 186,236 104,228" fill="#d97706" fill-opacity=".14" stroke="#d97706" stroke-width="2" />
                <text x="118" y="190" font-size="11" font-weight="700" fill="#92400e">P 26/4512</text>
                <circle cx="150" cy="160" r="7" fill="#2563eb" stroke="#fff" stroke-width="3" />
                <rect x="14" y="300" width="192" height="86" rx="0" fill="#fff" />
                <rect x="14" y="300" width="192" height="1" fill="#e4e4e7" />
                <text x="28" y="326" font-size="12" font-weight="700" fill="#18181b">P 26/4512</text>
                <text x="28" y="344" font-size="10" fill="#52525b">{{ __('Prospecting licence · Pending') }}</text>
                <text x="28" y="362" font-size="10" fill="#52525b">{{ __('You are inside the boundary') }}</text>
                <rect x="80" y="22" width="60" height="6" rx="3" fill="#18181b" />
            </svg>
            <figcaption class="mt-2 text-xs text-zinc-500">{{ __('Export GPX or KML and see the boundaries offline in your GPS or detector app. Illustration.') }}</figcaption>
        </figure>
    </div>

    <div class="mt-10 grid gap-4 md:grid-cols-2">
        <flux:card @class(['ring-2 ring-zinc-800 dark:ring-white' => $plan === 'prospector'])>
            <div class="flex items-baseline justify-between gap-2">
                <flux:heading size="lg">{{ __('Prospector') }}</flux:heading>
                <flux:badge color="amber" size="sm">{{ __('Pre-sale') }}</flux:badge>
            </div>
            <flux:text class="mt-1"><span class="text-2xl font-semibold text-zinc-900 dark:text-white">{{ $price }}</span> {{ __('a year') }}</flux:text>
            <flux:text class="mt-2 text-sm">
                @if ($stripe)
                    {{ __('For prospectors and detectorists. Pay now; your year starts the day Prospector goes live, and you can ask for a full refund until then.') }}
                @else
                    {{ __('For prospectors and detectorists. Reserve now for free; we\'ll email you when you can pay. Your year starts the day Prospector goes live.') }}
                @endif
            </flux:text>
            <div class="mt-4" data-test="presale-goal">
                @if ($soldOut)
                    <flux:text class="text-xs">{{ __('All :goal pre-sale places are taken. Join the list for launch.', ['goal' => $goal]) }}</flux:text>
                @elseif ($paidCount >= 5)
                    <flux:progress :value="min(100, round(100 * $paidCount / max(1, $goal)))" color="amber" />
                    <flux:text class="mt-1.5 text-xs">{{ __(':n of :goal pre-sale places taken', ['n' => $paidCount, 'goal' => $goal]) }}</flux:text>
                @else
                    <flux:text class="text-xs">{{ __(':goal places at the pre-sale price.', ['goal' => $goal]) }}</flux:text>
                @endif
            </div>
            <flux:button class="mt-4 w-full" :variant="$plan === 'prospector' ? 'primary' : 'outline'" wire:click="choose('prospector')" x-on:click="{{ $toEmail }}">
                {{ $soldOut ? __('Join the list') : ($stripe ? __('Pre-order Prospector') : __('Reserve Prospector')) }}
            </flux:button>
        </flux:card>
        <flux:card @class(['ring-2 ring-zinc-800 dark:ring-white' => $plan === 'team'])>
            <flux:heading size="lg">{{ __('Team') }}</flux:heading>
            <flux:text class="mt-1"><span class="text-2xl font-semibold text-zinc-900 dark:text-white">A$49</span> {{ __('a month per team, not per user') }}</flux:text>
            <flux:text class="mt-2 text-sm">{{ __('For consultants and junior explorers: shared watch lists for the whole team, plus bulk CSV and GeoJSON exports and an API to manage the team\'s watches.') }}</flux:text>
            <flux:button class="mt-4 w-full" :variant="$plan === 'team' ? 'primary' : 'outline'" wire:click="choose('team')" x-on:click="{{ $toEmail }}">{{ __('Register interest') }}</flux:button>
        </flux:card>
    </div>

    <form wire:submit="reserve" class="mt-4 max-w-xl scroll-mt-24 space-y-2">
        <flux:label for="presale-email">
            {{ $plan === 'team' ? __('Team: email for team plan news') : ($soldOut ? __('Prospector: email for launch news') : ($stripe ? __('Prospector: email for your receipt') : __('Prospector: email to hold your place'))) }}
        </flux:label>
        <div class="flex gap-2">
            <flux:input id="presale-email" wire:model="email" type="email" autocomplete="email" required :placeholder="__('you@example.com')" class="flex-1" />
            <flux:button type="submit" variant="primary">
                {{ $plan === 'team' ? __('Register') : ($soldOut ? __('Join') : ($stripe ? __('Continue to payment') : __('Reserve'))) }}
            </flux:button>
        </div>
        <flux:error name="email" />
        <flux:text class="text-xs">
            @if ($plan === 'prospector' && $stripe && ! $soldOut)
                {{ __('Payment is taken by Stripe. Pegwatch never sees your card.') }}
            @else
                {{ __('Nothing is charged.') }}
            @endif
            {{ $tax }}
            <flux:link :href="route('terms')" wire:navigate>{{ __('Terms') }}</flux:link> {{ __('and') }}
            <flux:link :href="route('privacy')" wire:navigate>{{ __('privacy') }}</flux:link>.
        </flux:text>
    </form>

    <flux:heading size="lg" class="mt-12">{{ __('What each plan includes') }}</flux:heading>
    {{-- Phones: one list per plan, so nothing hides off the side. --}}
    <div class="mt-2 space-y-6 sm:hidden" data-test="plans-phone">
        @foreach ($plans as $i => $name)
            <section>
                <flux:heading size="sm">{{ $name }}</flux:heading>
                <ul class="mt-1 divide-y divide-zinc-200 text-sm dark:divide-zinc-700">
                    @foreach ($rows as $r)
                        @continue($r[$i + 1] === false)
                        <li class="flex gap-2 py-2">
                            <flux:icon.check variant="mini" class="mt-0.5 shrink-0 text-emerald-600 dark:text-emerald-400" />
                            <span>{{ $r[0] }}@if (is_string($r[$i + 1])): <span class="text-zinc-500 dark:text-zinc-400">{{ $r[$i + 1] }}</span>@endif</span>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endforeach
    </div>
    <div class="relative mt-2 overflow-x-auto max-sm:hidden">
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
                        @foreach (array_slice($r, 1) as $cell)
                            <flux:table.cell class="whitespace-normal">
                                @if ($cell === true)
                                    <flux:icon.check variant="mini" class="text-emerald-600 dark:text-emerald-400" /><span class="sr-only">{{ __('Included') }}</span>
                                @elseif ($cell === false)
                                    <span class="text-zinc-400" aria-hidden="true">–</span><span class="sr-only">{{ __('Not included') }}</span>
                                @else
                                    {{ $cell }}
                                @endif
                            </flux:table.cell>
                        @endforeach
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </div>

    <flux:heading size="lg" class="mt-12">{{ __('Questions') }}</flux:heading>
    <flux:accordion class="mt-2 max-w-2xl" transition>
        <flux:accordion.item :heading="__('When does my year start?')">
            {{ __('The day Prospector goes live, not the day you pre-order. We email you before it starts.') }}
        </flux:accordion.item>
        <flux:accordion.item :heading="__('Can I get a refund?')">
            {{ __('Yes. Until Prospector goes live you can ask for a full refund, for any reason: write to :email. A reservation is never charged.', ['email' => $business['support_email']]) }}
        </flux:accordion.item>
        <flux:accordion.item :heading="__('Who sells Pegwatch, and is there GST?')">
            {{ $business['name'] ? __(':name sells Pegwatch', ['name' => $business['name']]).($business['abn'] ? ' (ABN '.$business['abn'].')' : '').'. ' : '' }}{{ $tax }}
            {{ __('Questions go to :email.', ['email' => $business['support_email']]) }}
        </flux:accordion.item>
        <flux:accordion.item :heading="__('What stays free?')">
            {{ __('The map, tenement and holder pages, the weekly digest, one watch area and one holder watch with death and release alerts.') }}
        </flux:accordion.item>
        <flux:accordion.item :heading="__('Is this advice?')">
            {{ __('No. Pegwatch reads the public register each night and tells you what changed. Before you act, confirm in') }}
            <flux:link :href="\App\Support\Format::TENGRAPH_URL" external>TENGRAPH</flux:link>
            {{ __('and with the Mining Registrar.') }}
        </flux:accordion.item>
    </flux:accordion>

    <flux:text class="mt-10 text-sm">
        {{ __('Not ready to pay?') }} <flux:link :href="route('watch.start')" wire:navigate>{{ __('Watch an area for free') }}</flux:link>
        {{ __('or') }} <flux:link :href="route('digest')" wire:navigate>{{ __('get the weekly digest') }}</flux:link>.
    </flux:text>
</div>
