<?php

use App\Support\Format;
use App\Support\Register;
use Livewire\Component;

new class extends Component {
    public string $slug;

    protected ?array $data = null;

    public bool $showAll = false;

    public function mount(string $slug): void
    {
        // Kept for this request's render, so the first page load queries once.
        abort_unless($this->data = Register::holder($slug), 404);
        $this->slug = $slug;
    }

    public function render()
    {
        $data = $this->data ?? Register::holder($this->slug);

        return $this->view(['data' => $data])->title($data['holder']['name']);
    }
}; ?>

@php
    $months = $data['applications_by_month'];
    $chart = array_map(fn ($m) => ['date' => $m['month'].'-01', 'n' => $m['n']], $months);
    $none = array_sum(array_column($months, 'n')) === 0;
    $tenements = $showAll ? $data['tenements'] : array_slice($data['tenements'], 0, 50);
    $s = $data['summary'];
@endphp

<div>
    <flux:heading size="xl" level="1">{{ $data['holder']['name'] }}</flux:heading>
    <flux:text class="mt-1">
        {{ number_format($s['live']) }} live, {{ number_format($s['pending']) }} pending, {{ number_format($s['area_ha']) }} ha mapped.
        {{ trans_choice('{0} No dead tenements on record.|{1} 1 dead tenement on record.|[2,*] :n dead tenements on record.', $s['dead'], ['n' => number_format($s['dead'])]) }}
        @unless ($data['holder']['is_company']) {{ __('Shown by name only, as on the public register.') }} @endunless
    </flux:text>

    <div class="mt-4 grid gap-6 md:grid-cols-5">
        <div class="md:col-span-3">
            <div wire:ignore x-data="tenementMap({ bounds: @js($data['bbox']), highlight: @js($data['geometry']) })" class="h-[420px] w-full overflow-hidden rounded-lg">
                <div x-ref="map" class="h-full w-full"></div>
            </div>
        </div>
        <div class="md:col-span-2">
            <flux:heading>{{ __('Applications per month (24 months)') }}</flux:heading>
            @if ($none)
                <flux:text>{{ __('None still live or pending.') }}</flux:text>
            @else
                <flux:chart :value="$chart" class="mt-2 aspect-[2/1]">
                    <flux:chart.svg>
                        <flux:chart.bar field="n" class="text-zinc-700 dark:text-zinc-300" />
                        <flux:chart.axis axis="x" field="date" :format="['month' => 'short', 'year' => '2-digit']">
                            <flux:chart.axis.tick />
                            <flux:chart.axis.line />
                        </flux:chart.axis>
                        <flux:chart.axis axis="y" tick-count="3">
                            <flux:chart.axis.grid />
                            <flux:chart.axis.tick />
                        </flux:chart.axis>
                    </flux:chart.svg>
                    <flux:chart.tooltip>
                        <flux:chart.tooltip.heading field="date" :format="['month' => 'long', 'year' => 'numeric']" />
                        <flux:chart.tooltip.value field="n" :label="__('Applications')" />
                    </flux:chart.tooltip>
                </flux:chart>
            @endif
            <flux:heading class="mt-6">{{ __('Recent changes') }}</flux:heading>
            <x-event-list :events="$data['events']" compact />
        </div>
    </div>

    <flux:heading class="mt-8">{{ __('Tenements') }} ({{ count($data['tenements']) }})</flux:heading>
    <flux:table class="mt-2 tabular-nums">
        <flux:table.columns>
            <flux:table.column>{{ __('Tenement') }}</flux:table.column>
            <flux:table.column>{{ __('Type') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
            <flux:table.column>{{ __('Applied') }}</flux:table.column>
            <flux:table.column>{{ __('Expires') }}</flux:table.column>
            <flux:table.column align="end">ha</flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @foreach ($tenements as $t)
                <flux:table.row :key="$t['slug']">
                    <flux:table.cell><flux:link :href="route('tenements.show', $t['slug'])" wire:navigate>{{ $t['fmt_tenid'] }}</flux:link></flux:table.cell>
                    <flux:table.cell>{{ Format::lower($t['type']) }}</flux:table.cell>
                    <flux:table.cell>{{ Format::lower($t['status']) }}</flux:table.cell>
                    <flux:table.cell>{{ Format::localDate($t['applied_at']) }}</flux:table.cell>
                    <flux:table.cell>{{ Format::localDate($t['ends_at']) }}</flux:table.cell>
                    <flux:table.cell align="end">{{ $t['area_ha'] !== null ? number_format($t['area_ha'], 1) : '' }}</flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>
    @if (count($data['tenements']) > 50 && ! $showAll)
        <flux:button size="sm" variant="subtle" class="mt-2" wire:click="$set('showAll', true)">{{ __('Show all') }} {{ count($data['tenements']) }}</flux:button>
    @endif

    @if (count($data['dead']))
        <flux:heading class="mt-8">{{ __('Dead tenements on record') }}</flux:heading>
        <ul class="mt-1 columns-2 text-sm md:columns-3">
            @foreach ($data['dead'] as $d)
                <li>
                    <flux:link :href="route('tenements.show', $d['slug'])" wire:navigate>{{ $d['fmt_tenid'] }}</flux:link>
                    <span class="text-zinc-500">{{ Format::lower($d['reason']) }} {{ Format::localDate($d['ended_at']) }}</span>
                </li>
            @endforeach
        </ul>
    @endif
</div>
