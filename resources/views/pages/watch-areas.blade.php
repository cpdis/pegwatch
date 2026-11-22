<?php

use App\Models\User;
use App\Models\WatchArea;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('My watch area')] class extends Component {
    /** Free plan: one watch area (plan: Free vs Pro). */
    public const FREE_LIMIT = 1;

    public const TYPE_LABELS = [
        'death' => 'Tenement recorded dead, with the share of ground left uncovered',
        'release_scheduled' => 'Part of a licence scheduled for compulsory release',
        'released' => 'Release time reached',
    ];

    public string $name = '';

    public ?float $latitude = null;

    public ?float $longitude = null;

    public float $radius_km = 10;

    /** @var list<string> */
    public array $event_types = WatchArea::FREE_EVENT_TYPES;

    #[Computed]
    public function areas()
    {
        return Auth::user()->watchAreas()->latest()->get()->map(function (WatchArea $a) {
            $a->tenements = (int) DB::scalar(
                'select count(*) from tenements t, watch_areas w where w.id = ? and t.geom && w.area and ST_Intersects(t.geom, w.area)',
                [$a->id]
            );

            return $a;
        });
    }

    public function pick(float $lng, float $lat): void
    {
        $this->longitude = $lng;
        $this->latitude = $lat;
    }

    public function save(): void
    {
        $data = $this->validate([
            'name' => ['required', 'string', 'max:80'],
            // Roughly WA, with a margin
            'latitude' => ['required', 'numeric', 'between:-36,-13'],
            'longitude' => ['required', 'numeric', 'between:112,130'],
            'radius_km' => ['required', 'numeric', 'min:0.5', 'max:'.WatchArea::MAX_RADIUS_KM],
            'event_types' => ['required', 'array', 'list', 'min:1'],
            'event_types.*' => [Rule::in(WatchArea::FREE_EVENT_TYPES)],
        ], [], ['latitude' => 'centre', 'longitude' => 'centre']);

        // Lock the user row so parallel requests cannot each pass the limit check.
        $created = DB::transaction(function () use ($data) {
            $user = User::query()->whereKey(Auth::id())->lockForUpdate()->first();
            if ($user->watchAreas()->count() >= self::FREE_LIMIT) {
                return false;
            }
            $user->watchAreas()->create([...$data, 'event_types' => array_values(array_unique($data['event_types']))]);

            return true;
        });
        if (! $created) {
            $this->addError('name', __('The free plan includes one watch area. Delete it to add another.'));

            return;
        }

        $this->dispatch('area-saved', center: [$data['longitude'], $data['latitude']], radiusKm: (float) $data['radius_km']);
        $this->reset('name', 'latitude', 'longitude');
        unset($this->areas);
        Flux::toast(variant: 'success', text: __('Watch area saved. We will email you after the nightly update.'));
    }

    public function delete(int $id): void
    {
        $area = WatchArea::findOrFail($id);
        abort_unless($area->user_id === Auth::id(), 403);
        $area->delete();
        // Reload so the map switches back to picking a centre.
        $this->redirectRoute('watch-areas.index', navigate: true);
    }
}; ?>

@php
    $areas = $this->areas;
    $shown = $areas->first();
    $canAdd = $areas->count() < self::FREE_LIMIT;
@endphp

<div>
    <flux:heading size="xl" level="1">{{ __('My watch area') }}</flux:heading>
    <flux:text class="mt-1">{{ __('We email you after the nightly update when something changes inside your area. The free plan has one area.') }}</flux:text>

    <div class="mt-4 grid gap-6 md:grid-cols-5">
        <div class="space-y-4 md:col-span-2">
            @foreach ($areas as $a)
                <flux:card wire:key="area-{{ $a->id }}" class="space-y-2">
                    <flux:heading>{{ $a->name }}</flux:heading>
                    <flux:text>{{ $a->radius_km }} km around {{ number_format($a->latitude, 4) }}, {{ number_format($a->longitude, 4) }} · {{ $a->tenements }} {{ __('tenements inside today') }}</flux:text>
                    <ul class="list-disc ps-5 text-sm">
                        @foreach ($a->event_types as $t)
                            <li>{{ self::TYPE_LABELS[$t] ?? $t }}</li>
                        @endforeach
                    </ul>
                    <flux:button size="sm" variant="danger" icon="trash" wire:click="delete({{ $a->id }})" wire:confirm="{{ __('Delete this watch area?') }}">{{ __('Delete') }}</flux:button>
                </flux:card>
            @endforeach

            @if ($canAdd)
                <form wire:submit="save" class="space-y-4">
                    <flux:text>{{ __('Click the map to set the centre.') }}</flux:text>
                    <flux:input wire:model="name" :label="__('Name')" :placeholder="__('e.g. Kalgoorlie east')" />
                    <flux:field>
                        <flux:label>{{ __('Radius') }}: <span x-text="$wire.radius_km"></span> km</flux:label>
                        <input type="range" min="1" max="{{ \App\Models\WatchArea::MAX_RADIUS_KM }}" wire:model.live="radius_km" class="w-full accent-zinc-800 dark:accent-white" />
                    </flux:field>
                    <flux:checkbox.group wire:model="event_types" :label="__('Alert me about')">
                        @foreach (self::TYPE_LABELS as $value => $label)
                            <flux:checkbox :value="$value" :label="$label" />
                        @endforeach
                    </flux:checkbox.group>
                    <flux:error name="latitude" />
                    <flux:button type="submit" variant="primary" x-bind:disabled="$wire.latitude === null">{{ __('Save watch area') }}</flux:button>
                </form>
            @endif
        </div>
        <div class="md:col-span-3">
            <div wire:ignore
                 x-data="tenementMap({
                     pick: {{ $canAdd ? 'true' : 'false' }},
                     bounds: @js($shown ? [$shown->longitude - 0.5, $shown->latitude - 0.5, $shown->longitude + 0.5, $shown->latitude + 0.5] : null),
                     circle: @js($shown ? ['center' => [$shown->longitude, $shown->latitude], 'radiusKm' => $shown->radius_km] : null),
                 })"
                 x-on:map-pick="$wire.pick($event.detail.lng, $event.detail.lat)"
                 x-on:area-saved.window="pick = false; circle = { center: $event.detail.center, radiusKm: $event.detail.radiusKm }; fit([$event.detail.center[0] - 0.5, $event.detail.center[1] - 0.5, $event.detail.center[0] + 0.5, $event.detail.center[1] + 0.5])"
                 x-effect="if (pick && $wire.latitude !== null) circle = { center: [$wire.longitude, $wire.latitude], radiusKm: Number($wire.radius_km) }"
                 class="h-[480px] w-full overflow-hidden rounded-lg">
                <div x-ref="map" class="h-full w-full"></div>
            </div>
        </div>
    </div>
</div>
