{{-- A small-caps line above a heading, e.g. "MINING LEASE · PENDING APPLICATION". The `eyebrow` utility is in app.css. --}}
@props(['dot' => null])
<p {{ $attributes->class('eyebrow flex items-center gap-1.5') }}>
    @if ($dot)<span class="size-2 rounded-full" style="background: {{ $dot }}" aria-hidden="true"></span>@endif{{ $slot }}
</p>
