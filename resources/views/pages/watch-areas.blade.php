<?php

use App\Alerts\AlertMatcher;
use App\Alerts\WatchShape;
use App\Models\WatchArea;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * A user's watches (W3, O15): edit an area after saving, see what is inside
 * and what changed lately, preview the email, and see the alert emails sent.
 * New watches are made by the watch panel.
 */
new #[Title('My watches')] class extends Component {
    public string $place = '';

    #[Computed]
    public function watches()
    {
        return Auth::user()->watchAreas()->orderByRaw("kind = 'holder'")->orderByRaw('confirmed_at is null')->oldest()->get();
    }

    /** The area on the map: the first circle or tenement watch. */
    #[Computed]
    public function shown(): ?WatchArea
    {
        return $this->watches->first(fn (WatchArea $w) => $w->kind !== 'holder');
    }

    /** @return list<array{name: string, kind: string, path: string, lng: float, lat: float}> */
    #[Computed]
    public function places(): array
    {
        if (mb_strlen(trim($this->place)) < 2 || ! DB::scalar("select to_regclass('public.places') is not null")) {
            return [];
        }

        return array_map(fn ($r) => (array) $r, DB::select(
            'select name, kind, lga, ST_X(geom) as lng, ST_Y(geom) as lat from places
             where name ilike ? order by name ilike ? desc, name limit 6',
            ['%'.trim($this->place).'%', trim($this->place).'%']
        ));
    }

    private function mine(int $id): WatchArea
    {
        return Auth::user()->watchAreas()->findOrFail($id);
    }

    /** Save a new circle and tell the map. */
    private function reshape(WatchArea $area, ?WatchShape $shape): void
    {
        if (! $shape || $shape->kind !== 'circle' || $area->kind !== 'circle') {
            return;
        }
        $area->update($shape->attributes());
        $this->dispatch('watch-area-moved', circle: ['center' => [$shape->lng, $shape->lat], 'radiusKm' => $shape->radiusKm], bounds: $shape->bounds());
        unset($this->watches);
    }

    public function setRadius(int $id, mixed $km): void
    {
        $area = $this->mine($id);
        $this->reshape($area, $area->shape()->withRadius($km));
    }

    public function moveCentre(int $id, mixed $lng, mixed $lat): void
    {
        $area = $this->mine($id);
        $this->reshape($area, WatchShape::circle($lng, $lat, $area->radius_km));
        $this->place = '';
    }

    /** The map's draw tool reports the edited circle (contracts.md `watch-shape`). */
    public function drawn(int $id, mixed $detail): void
    {
        $shape = WatchShape::fromEvent($detail);
        $this->reshape($this->mine($id), $shape?->kind === 'circle' ? $shape : null);
    }

    public function rename(int $id, string $name): void
    {
        $name = trim($name);
        if ($name !== '' && mb_strlen($name) <= 80) {
            $this->mine($id)->update(['name' => $name]);
        }
        unset($this->watches);
    }

    public function toggleAlert(int $id, string $key): void
    {
        $area = $this->mine($id);
        $keys = in_array($key, $area->alerts(), true) ? array_diff($area->alerts(), [$key]) : [...$area->alerts(), $key];
        $types = WatchArea::eventTypesFor(array_values($keys));
        if ($types === []) {
            Flux::toast(variant: 'warning', text: __('Keep at least one alert, or delete the watch.'));

            return;
        }
        $area->update(['event_types' => $types]);
        unset($this->watches);
    }

    public function toggleCovered(int $id): void
    {
        $area = $this->mine($id);
        $area->update(['include_covered_deaths' => ! $area->include_covered_deaths]);
        unset($this->watches);
    }

    public function setStopped(int $id, bool $stopped): void
    {
        $area = $this->mine($id);
        $area->forceFill(['unsubscribed_at' => $stopped ? now() : null])->save();
        if ($stopped) {
            DB::table('alert_deliveries')->where('watch_area_id', $id)->whereNull('sent_at')->delete();
        }
        unset($this->watches);
    }

    /** Signed in already, so a watch waiting for its emailed link can be confirmed here. */
    public function confirm(int $id): void
    {
        $replaced = $this->mine($id)->confirm();
        Flux::toast(variant: 'success', text: $replaced ? __('Watching it now, in place of :old.', ['old' => $replaced]) : __('Watching it now.'));
        $this->redirectRoute('watch-areas.index', navigate: true);
    }

    public function delete(int $id): void
    {
        $this->mine($id)->delete();
        // Reload so the map and the panel start fresh.
        $this->redirectRoute('watch-areas.index', navigate: true);
    }
}; ?>

@php
    $watches = $this->watches;
    $areas = $watches->where('kind', '!=', 'holder');
    $holders = $watches->where('kind', 'holder');
    $shown = $this->shown;
    $shape = $shown?->shape();
    $history = $watches->flatMap(fn ($w) => array_map(fn ($h) => $h + ['watch' => $w->name], \App\Alerts\AlertMatcher::history($w)))
        ->sortByDesc('sent_at')->take(20)->values();
    $hasConfirmedArea = $areas->whereNotNull('confirmed_at')->isNotEmpty();
@endphp

<div>
    @if (session('status'))
        <flux:callout class="mb-5" icon="check-circle" variant="success" :heading="session('status')" />
    @endif

    <flux:heading size="xl" level="1">{{ __('My watches') }}</flux:heading>
    <flux:text class="mt-1">{{ __('We email you after a night when the register changes inside a watch. Free: one area and one holder. Next check: :when.', ['when' => \App\Alerts\AlertMatcher::nextCheck()]) }}</flux:text>

    <div class="mt-6 grid gap-6 lg:grid-cols-5">
        <div class="min-w-0 space-y-4 lg:col-span-2">
            @foreach ($areas->concat($holders) as $w)
                @php
                    $s = $w->shape();
                    $sum = $s->summary();
                    $status = $w->confirmed_at === null ? 'pending' : ($w->unsubscribed_at ? 'dead' : 'live');
                @endphp
                <flux:card wire:key="watch-{{ $w->id }}" class="space-y-4" data-test="watch-card">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <x-eyebrow>{{ match ($w->kind) { 'circle' => __('Area · :r km circle', ['r' => rtrim(rtrim(number_format($w->radius_km, 1), '0'), '.')]), 'tenement' => __('Tenement and 2 km around it'), default => __('Holder') } }}</x-eyebrow>
                            <input type="text" value="{{ $w->name }}" maxlength="80" aria-label="{{ __('Watch name') }}"
                                   wire:change="rename({{ $w->id }}, $event.target.value)"
                                   class="mt-1 w-full truncate rounded border-0 bg-transparent p-0 text-lg font-semibold text-zinc-900 focus:ring-2 focus:ring-accent dark:text-white" />
                        </div>
                        <x-status-badge :status="$status" :label="match ($status) { 'pending' => __('Waiting for confirm'), 'dead' => __('Alerts stopped'), default => __('Watching') }" />
                    </div>

                    <flux:text class="text-sm">{{ $s->describe() }}.</flux:text>
                    <flux:text class="text-sm tabular-nums">
                        {{ $w->kind === 'holder' ? __('Holds') : __('Inside') }}:
                        {{ number_format($sum['live']) }} {{ __('live') }}, {{ trans_choice(':n pending tenement|:n pending tenements', $sum['pending'], ['n' => number_format($sum['pending'])]) }}.
                        {{ __('In the last 30 days: :d, :a.', [
                            'd' => trans_choice(':n death|:n deaths', $sum['deaths'], ['n' => $sum['deaths']]),
                            'a' => trans_choice(':n application|:n applications', $sum['applications'], ['n' => $sum['applications']]),
                        ]) }}
                    </flux:text>

                    @if ($status === 'pending')
                        <flux:callout icon="envelope" color="amber">
                            <flux:callout.text>{{ __('Not watching yet: we emailed you a link to confirm it.') }}</flux:callout.text>
                            <x-slot name="actions"><flux:button size="sm" variant="primary" wire:click="confirm({{ $w->id }})">{{ __('Confirm now') }}</flux:button></x-slot>
                        </flux:callout>
                    @elseif ($status === 'dead')
                        <flux:callout icon="bell-slash">
                            <flux:callout.text>{{ __('You stopped alerts for this watch.') }}</flux:callout.text>
                            <x-slot name="actions"><flux:button size="sm" wire:click="setStopped({{ $w->id }}, false)">{{ __('Turn alerts back on') }}</flux:button></x-slot>
                        </flux:callout>
                    @endif

                    @if ($w->kind === 'circle')
                        <div class="space-y-2" x-data>
                            <flux:label>{{ __('Radius') }}</flux:label>
                            <div class="flex flex-wrap items-center gap-2">
                                <flux:button.group>
                                    @foreach ([5, 10, 25, 50] as $km)
                                        <flux:button size="sm" wire:click="setRadius({{ $w->id }}, {{ $km }})" :variant="(float) $w->radius_km === (float) $km ? 'primary' : 'outline'">{{ $km }} km</flux:button>
                                    @endforeach
                                </flux:button.group>
                                <flux:button size="sm" icon="map-pin" data-test="move-centre"
                                             x-on:click="$dispatch('watch-area-move', { id: {{ $w->id }} }); $dispatch('watch-start', { center: [{{ $w->longitude }}, {{ $w->latitude }}], radiusKm: {{ $w->radius_km }} })">
                                    {{ __('Move the centre') }}
                                </flux:button>
                            </div>
                            @if (\Illuminate\Support\Facades\DB::scalar("select to_regclass('public.places') is not null"))
                                <flux:input size="sm" icon="magnifying-glass" wire:model.live.debounce.300ms="place" :placeholder="__('Centre on a town or locality')" />
                                @foreach ($this->places as $p)
                                    <flux:button size="sm" variant="ghost" class="w-full justify-start" wire:click="moveCentre({{ $w->id }}, {{ $p['lng'] }}, {{ $p['lat'] }})">
                                        {{ $p['name'] }} <span class="text-zinc-500">· {{ $p['lga'] ?? $p['kind'] }}</span>
                                    </flux:button>
                                @endforeach
                            @endif
                        </div>
                    @endif

                    <div class="space-y-2">
                        <flux:label>{{ __('Email me about') }}</flux:label>
                        <flux:checkbox :checked="in_array('death', $w->alerts(), true)" wire:click="toggleAlert({{ $w->id }}, 'death')" :label="__('Tenements recorded dead, with the ground they leave uncovered')" />
                        <flux:checkbox :checked="in_array('release', $w->alerts(), true)" wire:click="toggleAlert({{ $w->id }}, 'release')" :label="__('Ground scheduled for compulsory release, with a calendar file and reminders')" />
                        <flux:checkbox :checked="$w->include_covered_deaths" wire:click="toggleCovered({{ $w->id }})" :label="__('Also tell me about deaths that leave no ground uncovered')" />
                        <div class="flex items-center justify-between gap-2 text-sm text-zinc-500 dark:text-zinc-400">
                            <span class="flex items-center gap-2"><flux:icon.lock-closed variant="micro" class="size-4" />{{ __('Applications, grants, transfers, surrenders, expiry') }}</span>
                            <x-pro-lock ref="alert-types" />
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-2 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                        <flux:button size="sm" icon="envelope-open" :href="route('watch-areas.preview', $w->id)" target="_blank">{{ __('Preview the email') }}</flux:button>
                        <flux:button size="sm" icon="lock-closed" :href="url('/pro?ref=export')" wire:navigate>{{ __('Export GPX / KML') }}</flux:button>
                        @if ($status === 'live')
                            <flux:button size="sm" variant="ghost" icon="bell-slash" wire:click="setStopped({{ $w->id }}, true)">{{ __('Stop alerts') }}</flux:button>
                        @endif
                        <flux:spacer />
                        <flux:button size="sm" variant="ghost" icon="trash" wire:click="delete({{ $w->id }})" wire:confirm="{{ __('Delete this watch?') }}" :aria-label="__('Delete')" />
                    </div>
                </flux:card>
            @endforeach

            @if ($areas->isEmpty())
                <flux:card>
                    <flux:heading size="lg">{{ __('Watch an area') }}</flux:heading>
                    <flux:text class="mt-1 mb-4">{{ __('Your free watch: one area, by circle or by tenement.') }}</flux:text>
                    <livewire:watch-this-area />
                </flux:card>
            @elseif ($hasConfirmedArea)
                <a href="{{ url('/pro?ref=second-area') }}" wire:navigate data-test="second-area-lock"
                   class="flex items-center justify-between gap-3 rounded-xl border border-dashed border-zinc-300 p-4 text-zinc-500 hover:border-zinc-400 dark:border-zinc-600 dark:text-zinc-400">
                    <span>
                        <span class="flex items-center gap-2 font-medium text-zinc-800 dark:text-zinc-100"><flux:icon.plus variant="mini" />{{ __('Add a second area') }}</span>
                        <span class="text-sm">{{ __('Prospector watches as many areas and holders as you need.') }}</span>
                    </span>
                    <x-pro-lock />
                </a>
            @endif

            @if ($holders->isEmpty())
                <flux:text class="text-sm">{{ __('Watch a holder: open any holder page and press Watch. One is free.') }}</flux:text>
            @endif

            <section>
                <flux:heading>{{ __('Alert emails') }}</flux:heading>
                @forelse ($history as $h)
                    <div class="flex items-baseline justify-between gap-3 border-b border-zinc-100 py-2 text-sm last:border-0 dark:border-zinc-800">
                        <span class="min-w-0 truncate"><span class="font-medium">{{ $h['watch'] }}</span> · {{ implode(', ', array_slice($h['tenements'], 0, 4)) }}{{ count($h['tenements']) > 4 ? ' +'.(count($h['tenements']) - 4) : '' }}</span>
                        <span class="shrink-0 text-zinc-500 tabular-nums">{{ \App\Support\Format::awst($h['sent_at']) }}</span>
                    </div>
                @empty
                    <flux:text class="mt-1 text-sm">{{ __('None yet. Your first comes after the next extract with changes in a watch.') }}</flux:text>
                @endforelse
            </section>
        </div>

        <div class="min-w-0 space-y-4 lg:col-span-3">
            <div class="relative h-[420px] overflow-hidden rounded-lg lg:h-[520px]" x-data="{ drawn: false, moving: null }">
                <div x-show="moving" x-cloak class="absolute start-2 top-2 z-10 rounded-md bg-ink px-3 py-1.5 text-sm font-medium text-white shadow">{{ __('Click the map to set the new centre') }}</div>
                <div wire:ignore class="h-full w-full"
                     x-data="tenementMap({
                         pick: @js($areas->isEmpty()),
                         draw: @js($areas->isEmpty()),
                         bounds: @js($shape?->bounds()),
                         circle: @js($shown?->kind === 'circle' ? ['center' => [$shown->longitude, $shown->latitude], 'radiusKm' => $shown->radius_km] : null),
                         highlight: @js($shown?->kind === 'tenement' ? $shape->geometry() : null),
                     })"
                     x-on:watch-area-move.window="moving = $event.detail.id; pick = true"
                     x-on:map-pick.window="if (moving) { $wire.moveCentre(moving, $event.detail.lng, $event.detail.lat); moving = null; pick = false }"
                     x-on:watch-shape.window.debounce.400ms="drawn = true; if ($event.detail.kind !== 'circle') circle = null; if (moving && $event.detail.kind === 'circle') $wire.drawn(moving, $event.detail)"
                     x-on:watch-start.window="if (! drawn && $event.detail.center) circle = { center: $event.detail.center, radiusKm: $event.detail.radiusKm ?? 10 }"
                     x-on:watch-area-moved.window="if (! drawn) circle = $event.detail.circle; $event.detail.bounds && fit($event.detail.bounds)">
                    <div x-ref="map" class="h-full w-full"></div>
                </div>
            </div>

            @if ($shown && $shown->kind !== 'holder')
                @php($inside = $shape->tenements(12))
                <section>
                    <flux:heading>{{ __('Tenements inside :name', ['name' => $shown->name]) }}</flux:heading>
                    <div class="mt-2 divide-y divide-zinc-100 dark:divide-zinc-800">
                        @forelse ($inside as $t)
                            <a href="{{ url('/tenements/'.$t['slug']) }}" wire:navigate class="flex items-center justify-between gap-3 py-2 text-sm hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                                <span class="min-w-0 truncate"><span class="font-medium tabular-nums">{{ $t['fmt_tenid'] }}</span> <span class="text-zinc-500">· {{ \App\Support\Format::sentence($t['type']) }}@if ($t['holder']) · {{ $t['holder'] }}@endif</span></span>
                                <x-status-badge :status="$t['status']" />
                            </a>
                        @empty
                            <flux:text class="py-2 text-sm">{{ __('No live or pending tenement inside today.') }}</flux:text>
                        @endforelse
                    </div>
                </section>
            @endif
        </div>
    </div>
</div>
