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
    $from = \App\Support\Format::awst($c['period_start'], false);
    $to = \App\Support\Format::awst($c['period_end'], false);
@endphp

<div class="max-w-2xl">
    <flux:link :href="route('digest')" wire:navigate class="text-sm">{{ __('Weekly digest') }}</flux:link>
    <flux:heading size="xl" level="1" class="mt-1">{{ __('The WA register, :from to :to', ['from' => $from, 'to' => $to]) }}</flux:heading>

    <x-digest.issue :c="$c" />

    <flux:card class="mt-10">
        <flux:heading>{{ __('Get this every Monday') }}</flux:heading>
        <div class="mt-3"><livewire:digest-signup source="digest-issue" compact /></div>
    </flux:card>
</div>
