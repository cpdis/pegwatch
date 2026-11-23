<?php

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Weekly digest')] class extends Component {
    public array $c = [];

    public string $week = '';

    public function mount(string $week): void
    {
        $issue = DB::table('digest_issues')->where('week', $week)->first();
        abort_unless($issue, 404);
        $this->week = $week;
        $this->c = json_decode($issue->content, true);
    }
}; ?>

@php
    $label = fn ($type) => \App\Support\Register::EVENT_LABELS[$type] ?? $type;
    $from = \App\Support\Format::awst($c['period_start'], false);
    $to = \App\Support\Format::awst($c['period_end'], false);
@endphp

<div class="max-w-2xl">
    <flux:link :href="route('digest')" wire:navigate class="text-sm">{{ __('Weekly digest') }}</flux:link>
    <flux:heading size="xl" level="1" class="mt-1">{{ __('The WA register, :from to :to', ['from' => $from, 'to' => $to]) }}</flux:heading>

    <flux:table class="mt-4">
        <flux:table.columns>
            <flux:table.column>{{ __('This week') }}</flux:table.column>
            <flux:table.column align="end">{{ __('Count') }}</flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @foreach (\App\Digest\WeeklyDigest::COUNTED as $type)
                @php($n = $c['counts'][$type] ?? 0)
                <flux:table.row>
                    <flux:table.cell>{{ $label($type) }}</flux:table.cell>
                    <flux:table.cell align="end">{{ number_format($n) }}</flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>

    <flux:heading size="lg" class="mt-8">{{ __('Ground left open') }}</flux:heading>
    @forelse ($c['freed'] as $e)
        <flux:text class="mt-2">
            <flux:link :href="route('tenements.show', ['slug' => $e['slug']])" wire:navigate>{{ $e['fmt_tenid'] }}</flux:link>,
            {{ $e['tenement_type'] }}: {{ number_format($e['uncovered_ha'], 1) }} ha {{ __('with no other tenement over it.') }} {{ $e['reason'] ? $e['reason'].'.' : '' }}
        </flux:text>
    @empty
        <flux:text class="mt-2">{{ __('No tenement died this week leaving a hectare or more uncovered.') }}</flux:text>
    @endforelse

    <flux:heading size="lg" class="mt-8">{{ __('Biggest new applications') }}</flux:heading>
    @forelse ($c['applications'] as $e)
        <flux:text class="mt-2">
            <flux:link :href="route('tenements.show', ['slug' => $e['slug']])" wire:navigate>{{ $e['fmt_tenid'] }}</flux:link>,
            {{ $e['tenement_type'] }}{{ $e['area_ha'] !== null ? ': '.number_format($e['area_ha'], 1).' ha' : '' }}{{ $e['holders'] ? ', '.implode(', ', $e['holders']) : '' }}
        </flux:text>
    @empty
        <flux:text class="mt-2">{{ __('No new applications this week.') }}</flux:text>
    @endforelse

    @if ($c['applicants'])
        <flux:heading size="lg" class="mt-8">{{ __('Busiest applicants') }}</flux:heading>
        <ul class="mt-2 space-y-1">
            @foreach ($c['applicants'] as $h)
                <li><flux:link :href="route('holders.show', ['slug' => $h['slug']])" wire:navigate>{{ $h['name'] }}</flux:link>
                    <flux:text class="inline">{{ trans_choice(':n application|:n applications', $h['n'], ['n' => $h['n']]) }}</flux:text></li>
            @endforeach
        </ul>
    @endif

    <flux:heading size="lg" class="mt-8">{{ __('Compulsory releases in the following 7 days') }}</flux:heading>
    @forelse ($c['releases'] as $r)
        <flux:text class="mt-2">
            <flux:link :href="route('tenements.show', ['slug' => $r['slug']])" wire:navigate>{{ $r['fmt_tenid'] }}</flux:link>:
            {{ number_format($r['area_ha'], 1) }} ha, {{ $r['release_at'] }} AWST
        </flux:text>
    @empty
        <flux:text class="mt-2">{{ __('None scheduled.') }}</flux:text>
    @endforelse

    <flux:card class="mt-10">
        <flux:heading>{{ __('Get this every Monday') }}</flux:heading>
        <div class="mt-3"><livewire:digest-signup source="digest-issue" compact /></div>
    </flux:card>
</div>
