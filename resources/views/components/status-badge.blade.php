{{-- A status as a coloured dot and a word. Accepts register statuses ("LIVE") or keys ("release"). --}}
@props(['status', 'label' => null])
@php
    $key = \App\Support\Format::statusKey($status);
    $colour = \App\Support\Format::STATUS_COLOURS[$key];
@endphp
<span {{ $attributes->class('inline-flex shrink-0 items-center gap-1.5 rounded-full border border-zinc-200 bg-white px-2 py-0.5 text-xs font-medium text-zinc-800 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100') }}>
    <span class="size-2 rounded-full" style="background: {{ $colour }}" aria-hidden="true"></span>{{ $label ?? __(\App\Support\Format::STATUS_LABELS[$key]) }}
</span>
