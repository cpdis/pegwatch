<?php

use App\Alerts\WatchShape;
use App\Alerts\WatchRequest;
use App\Models\User;
use App\Models\WatchArea;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * "Watch this area", email first (W1). The shape comes from the map's draw
 * tool (`watch-shape`), a click on a map without one, or a tenement or holder
 * in the URL. Guests give an email and get a confirm link; a signed-in,
 * verified user's watch starts at once.
 */
new class extends Component {
    #[Locked]
    public bool $compact = false;

    /** Only setShape() makes one, through WatchShape's checked factories (1 to 50 km, inside WA). */
    #[Locked]
    public ?WatchShape $shape = null;

    public string $name = '';

    #[Locked]
    public string $suggestedName = '';

    /** @var list<string> free alert keys (WatchArea::FREE_ALERTS) */
    public array $alerts = ['death', 'release'];

    public bool $include_covered_deaths = false;

    public string $email = '';

    /** Honeypot: hidden from people, filled in by form bots. */
    public string $website = '';

    #[Locked]
    public ?string $sentTo = null;

    public function mount(?string $tenement = null, ?string $holder = null, bool $compact = false): void
    {
        $this->compact = $compact;
        $this->useShape($tenement ? WatchShape::tenement($tenement) : ($holder ? WatchShape::holder($holder) : null));
    }

    /** The map's draw tool reports its shape as it changes (debounced in the browser). */
    public function setShape(mixed $detail): void
    {
        $this->useShape(WatchShape::fromEvent($detail));
    }

    /** A click on a map that has no draw tool: a circle there, keeping the radius. */
    public function pick(mixed $lng, mixed $lat): void
    {
        $this->useShape(WatchShape::circle($lng, $lat, $this->shape?->radiusKm ?? 10));
        $this->showOnMap();
    }

    public function setRadius(mixed $km): void
    {
        if ($this->shape?->kind === 'circle') {
            $this->useShape($this->shape->withRadius($km));
            $this->showOnMap();
        }
    }

    private function useShape(?WatchShape $shape): void
    {
        if (! $shape) {
            return;
        }
        $this->shape = $shape;
        $this->sentTo = null;
        $this->resetValidation();
        // Follow the map with the suggested name until the user types their own.
        $suggested = $shape->defaultName();
        if ($this->name === '' || $this->name === $this->suggestedName) {
            $this->name = $suggested;
        }
        $this->suggestedName = $suggested;
        unset($this->summary, $this->full);
    }

    /** Ask the map to show the circle: the draw tool takes `watch-start`, and so does a plain map's preview. */
    private function showOnMap(): void
    {
        $this->dispatch('watch-start', center: [$this->shape->lng, $this->shape->lat], radiusKm: $this->shape->radiusKm);
    }

    #[Computed]
    public function summary(): ?array
    {
        return $this->shape?->summary();
    }

    /** The signed-in user's watch that a new one would replace, when the free plan is full. */
    #[Computed]
    public function full(): ?WatchArea
    {
        if (! Auth::check() || ! $this->shape) {
            return null;
        }
        $mine = Auth::user()->watchAreas()->inGroup($this->shape->isArea() ? 'area' : 'holder')->confirmed()->oldest()->get();

        return $mine->count() >= WatchArea::FREE_LIMIT ? $mine->first() : null;
    }

    public function submit(bool $replace = false): void
    {
        if (! $this->shape) {
            $this->addError('shape', __('Draw an area on the map first.'));

            return;
        }
        $guest = ! Auth::check();
        $this->email = Str::lower(trim($this->email));
        $this->validate([
            'name' => ['required', 'string', 'max:80'],
            'alerts' => ['required', 'array', 'list', 'min:1'],
            'alerts.*' => [Rule::in(array_keys(WatchArea::FREE_ALERTS))],
            'email' => $guest ? ['required', 'string', 'email:rfc', 'max:254'] : [],
        ], ['alerts.required' => __('Pick at least one alert.')]);

        $user = Auth::user();
        if ($user?->hasVerifiedEmail()) {
            if ($this->full && ! $replace) {
                return; // The view offers to replace the watch or go Pro.
            }
            $area = $this->create($user);
            $area->confirm();
            session()->flash('status', __('Watching :name. Your first email comes after the next extract with changes.', ['name' => $area->name]));
            $this->redirectRoute('watch-areas.index', navigate: true);

            return;
        }

        $email = $user?->email ?? $this->email;
        if ($guest && $this->website !== '') {
            $this->sentTo = $email;

            return;
        }
        if (! WatchRequest::emailConfirm($email, $this->shape, $this->name, $this->alerts, $this->include_covered_deaths)) {
            $this->addError('email', __('Too many sign-ups from here. Try again later.'));

            return;
        }
        $this->sentTo = $email;
    }

    private function create(User $user): WatchArea
    {
        return WatchRequest::create($user, $this->shape, $this->name, $this->alerts, $this->include_covered_deaths);
    }
}; ?>

@php
    $s = $this->summary;
    $what = $shape?->isArea() === false ? __('holder') : __('area');
@endphp

<div x-data="{ center: null, waiting: false }"
     x-on:map-moved.window="center = $event.detail.center"
     x-on:watch-shape.window="waiting = false"
     x-on:watch-shape.window.debounce.300ms="$wire.setShape($event.detail)"
     x-on:map-pick.window="$wire.pick($event.detail.lng, $event.detail.lat)"
     @class(['rounded-lg border border-zinc-200 p-3 dark:border-zinc-700' => $compact])
     data-watch-panel>
    @if ($sentTo)
        <flux:callout icon="envelope" variant="success" :heading="__('Check your inbox')">
            <flux:callout.text>{{ __('We sent a link to :email. Press it to start watching :name. It works for :n days.', ['email' => $sentTo, 'name' => $name, 'n' => \App\Models\WatchArea::CONFIRM_DAYS]) }}</flux:callout.text>
        </flux:callout>
    @elseif (! $shape)
        @if ($compact)
            <div class="flex items-center justify-between gap-3">
                <div>
                    <flux:heading size="sm">{{ __('Watch this area') }}</flux:heading>
                    <flux:text class="text-sm">{{ __('Free email when ground here dies or is scheduled for release.') }}</flux:text>
                </div>
                {{-- The map's draw tool answers watch-start with a watch-shape; without one, the watch page has a map to pick on. --}}
                <flux:button variant="primary" size="sm" icon="bell-alert" class="shrink-0" data-test="watch-start"
                             x-on:click="waiting = true; $dispatch('watch-start', center ? { center } : {}); setTimeout(() => waiting && Livewire.navigate(@js(route('watch.start'))), 1500)">
                    {{ __('Watch this area') }}
                </flux:button>
            </div>
        @else
            <flux:callout icon="cursor-arrow-rays">
                <flux:callout.heading>{{ __('Pick the ground on the map') }}</flux:callout.heading>
                <flux:callout.text>{{ __('Click the map to centre a circle, then set its size. To watch one tenement or a holder, use Watch on its page.') }}</flux:callout.text>
            </flux:callout>
        @endif
    @else
        <form wire:submit="submit" class="space-y-4">
            @if ($compact)
                <flux:heading size="sm">{{ __('Watch this area') }}</flux:heading>
            @endif
            <div>
                <flux:text class="text-sm font-medium text-zinc-800 dark:text-zinc-100">{{ $shape->describe() }}</flux:text>
                @if ($s)
                    <flux:text class="mt-1 text-sm tabular-nums" data-test="watch-counts">
                        {{ $shape->isArea() ? __('Inside today:') : __('Holds today:') }}
                        {{ trans_choice(':n live|:n live', $s['live'], ['n' => number_format($s['live'])]) }},
                        {{ trans_choice(':n pending tenement|:n pending tenements', $s['pending'], ['n' => number_format($s['pending'])]) }}.
                        {{ __('Last 30 days: :d, :a, :r.', [
                            'd' => trans_choice(':n death|:n deaths', $s['deaths'], ['n' => $s['deaths']]),
                            'a' => trans_choice(':n application|:n applications', $s['applications'], ['n' => $s['applications']]),
                            'r' => trans_choice(':n release scheduled|:n releases scheduled', $s['releases'], ['n' => $s['releases']]),
                        ]) }}
                    </flux:text>
                @endif
            </div>

            @if ($shape->kind === 'circle')
                <flux:field>
                    <flux:label>{{ __('Radius') }}</flux:label>
                    <flux:button.group>
                        @foreach ([5, 10, 25, 50] as $km)
                            <flux:button size="sm" wire:click="setRadius({{ $km }})" :variant="(float) $shape->radiusKm === (float) $km ? 'primary' : 'outline'">{{ $km }} km</flux:button>
                        @endforeach
                    </flux:button.group>
                </flux:field>
            @endif

            <flux:input wire:model="name" :label="__('Name')" maxlength="80" />

            <div class="space-y-2">
                <flux:checkbox.group wire:model="alerts" :label="__('Email me about')">
                    <flux:checkbox value="death" :label="__('Tenements recorded dead, with the ground they leave uncovered')" />
                    <flux:checkbox value="release" :label="__('Ground scheduled for compulsory release, with a calendar file')" />
                </flux:checkbox.group>
                <ul class="space-y-1 ps-0.5 text-sm text-zinc-500 dark:text-zinc-400" aria-label="{{ __('Prospector alert types') }}">
                    @foreach (\App\Models\WatchArea::PRO_ALERTS as $label)
                        <li class="flex items-center justify-between gap-2">
                            <span class="flex items-center gap-2"><flux:icon.lock-closed variant="micro" class="size-4" />{{ __($label) }}</span>
                            @if ($loop->first)<x-pro-lock ref="alert-types" />@endif
                        </li>
                    @endforeach
                </ul>
                <flux:checkbox wire:model="include_covered_deaths" :label="__('Also tell me about deaths that leave no ground uncovered')"
                               :description="__('Conversions, amalgamations, and deaths with under 1 ha left uncovered. Off by default.')" />
            </div>

            @guest
                <flux:input wire:model="email" type="email" autocomplete="email" required :label="__('Email address')" placeholder="you@example.com" />
                <div class="hidden" aria-hidden="true">
                    <input type="text" wire:model="website" tabindex="-1" autocomplete="off">
                </div>
            @endguest
            <flux:error name="shape" />

            @if ($this->full)
                <flux:callout icon="lock-closed" color="amber" data-test="watch-limit">
                    <flux:callout.heading>{{ __('The free plan watches one :what', ['what' => $what]) }}</flux:callout.heading>
                    <flux:callout.text>{{ __('You watch :name. Replace it with this one, or keep both with Prospector.', ['name' => $this->full->name]) }}</flux:callout.text>
                    <x-slot name="actions">
                        <flux:button size="sm" variant="primary" wire:click="submit(true)">{{ __('Replace :name', ['name' => $this->full->name]) }}</flux:button>
                        <x-pro-lock ref="second-{{ $what }}">{{ __('Keep both') }}</x-pro-lock>
                    </x-slot>
                </flux:callout>
            @else
                <flux:button type="submit" variant="primary" class="w-full" data-test="watch-submit">{{ __('Email me when this changes') }}</flux:button>
            @endif
            <flux:text class="text-xs">{{ __('One email after a night with changes. Unsubscribe from any email.') }}</flux:text>
        </form>
    @endif
</div>
