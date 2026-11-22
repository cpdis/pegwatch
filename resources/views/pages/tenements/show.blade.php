<?php

use App\Support\Format;
use App\Support\Register;
use Livewire\Component;

new class extends Component {
    public string $slug;

    public function mount(string $slug): void
    {
        abort_unless(Register::tenement($slug), 404);
        $this->slug = $slug;
    }

    public function render()
    {
        $data = Register::tenement($this->slug);

        return $this->view(['data' => $data])->title($data['tenement']['fmt_tenid']);
    }
}; ?>

@php
    $t = $data['tenement'];
    $rows = [
        __('Type') => $t['type'],
        __('Status') => $t['dead'] ? __('Dead').' ('.($t['dead']['reason'] ?? __('reason not yet published')).')' : $t['status'],
        __('Survey') => $t['survey_status'],
        __('Area') => $t['legal_area'] ? $t['legal_area'].' '.str_replace('.', '', Format::lower($t['area_unit'])) : null,
        __('Mapped area') => $t['area_ha'] ? number_format($t['area_ha'], 1).' ha' : null,
        ($t['status'] === 'PENDING' ? __('Applied') : __('Started')) => Format::localDate($t['applied_at'], true),
        __('Granted') => Format::localDate($t['granted_at']),
        ($t['dead'] ? __('Ended') : __('Expires')) => Format::localDate($t['ends_at']),
    ];
@endphp

<div class="grid gap-6 md:grid-cols-5">
    <div class="md:col-span-2">
        <flux:heading size="xl" level="1">{{ $t['fmt_tenid'] }}</flux:heading>

        <dl class="mt-4 grid grid-cols-3 gap-y-1 text-sm">
            @foreach ($rows as $label => $value)
                @if ($value)
                    <dt class="text-zinc-500">{{ $label }}</dt>
                    <dd class="col-span-2">{{ $value }}</dd>
                @endif
            @endforeach
            <dt class="text-zinc-500">{{ __('Holders') }}</dt>
            <dd class="col-span-2">
                @forelse ($t['holders'] as $h)
                    <div><flux:link :href="route('holders.show', $h['slug'])" wire:navigate>{{ $h['name'] }}</flux:link></div>
                @empty
                    —
                @endforelse
            </dd>
        </dl>

        @if (count($data['releases']))
            <flux:callout variant="danger" icon="exclamation-triangle" class="mt-4" :heading="__('Part of this licence is due for compulsory surrender.')">
                <flux:callout.text>
                    @foreach ($data['releases'] as $r)
                        <div>{{ $r['rlesid'] }}: {{ __('releases') }} {{ Format::awst($r['release_at']) }} AWST. {{ __('Applications in the first 15 minutes go to ballot.') }}</div>
                    @endforeach
                </flux:callout.text>
            </flux:callout>
        @endif

        @if (count($data['overlaps']))
            <flux:heading class="mt-6">{{ __('Overlapping tenements') }}</flux:heading>
            <ul class="mt-1 text-sm">
                @foreach ($data['overlaps'] as $o)
                    <li>
                        <flux:link :href="route('tenements.show', $o['slug'])" wire:navigate>{{ $o['fmt_tenid'] }}</flux:link>
                        <span class="text-zinc-500">{{ Format::lower($o['type']) ?: __('unknown type') }}, {{ Format::lower($o['status']) }}, {{ __('over') }} {{ Format::pct($o['share']) }} {{ __('of this one') }}</span>
                    </li>
                @endforeach
            </ul>
        @endif

        <flux:heading class="mt-6">{{ __('History seen by Pegwatch') }}</flux:heading>
        <x-event-list :events="$data['events']" />
    </div>
    <div class="md:col-span-3">
        <div wire:ignore x-data="tenementMap({ bounds: @js($data['bbox']), highlight: @js($data['geometry']) })" class="h-[480px] w-full overflow-hidden rounded-lg">
            <div x-ref="map" class="h-full w-full"></div>
        </div>
        <flux:text class="mt-2 text-xs">{{ __('Extract of') }} {{ Format::awst($t['extract_at']) }} AWST. {{ __('Check the current position in TENGRAPH before acting.') }}</flux:text>
    </div>
</div>
