{{--
    How current the register copy is. In the header: a chip that opens a
    popover. In the phone menu ($inline): the same words, always open.
--}}
@props(['fresh', 'inline' => false])
@php
    $dot = $fresh->stale ? 'bg-amber-500' : 'bg-zinc-400';
@endphp
@if ($inline)
    <div {{ $attributes->class('px-2 text-sm') }} data-test="freshness-inline">
        <p class="flex items-center gap-2 font-medium text-white tabular-nums">
            <span class="size-2 shrink-0 rounded-full {{ $dot }}" aria-hidden="true"></span>{{ $fresh->label() }}
        </p>
        @include('layouts.app.freshness-note')
    </div>
@else
    <flux:dropdown position="bottom" align="end" {{ $attributes }}>
        <button type="button" data-test="freshness-chip"
                class="inline-flex h-8 cursor-pointer items-center gap-2 rounded-full px-3 text-xs font-medium whitespace-nowrap text-zinc-300 tabular-nums ring-1 ring-white/15 ring-inset hover:bg-white/10 hover:text-white">
            <span class="size-2 shrink-0 rounded-full {{ $dot }}" aria-hidden="true"></span>{{ $fresh->label() }}
        </button>
        <flux:popover class="w-80 text-sm">
            @include('layouts.app.freshness-note')
        </flux:popover>
    </flux:dropdown>
@endif
