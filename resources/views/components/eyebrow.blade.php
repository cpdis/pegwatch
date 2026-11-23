{{-- A small-caps line above a heading, e.g. "MINING LEASE · PENDING APPLICATION". --}}
@props(['dot' => null])
<p {{ $attributes->class('flex items-center gap-1.5 text-[11px] font-semibold tracking-[0.08em] text-zinc-500 uppercase dark:text-zinc-400') }}>
    @if ($dot)<span class="size-2 rounded-full" style="background: {{ $dot }}" aria-hidden="true"></span>@endif{{ $slot }}
</p>
