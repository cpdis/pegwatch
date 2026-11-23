{{-- Marks a Prospector-plan feature and links to the plans page. --}}
@props(['ref' => null])
<a href="{{ url('/pro').($ref ? '?ref='.urlencode($ref) : '') }}" wire:navigate
   {{ $attributes->class('inline-flex items-center gap-1 rounded-full bg-zinc-900 px-2 py-0.5 text-[11px] font-semibold text-accent dark:bg-white dark:text-zinc-900') }}>
    <flux:icon.lock-closed variant="micro" class="size-3" />{{ $slot->isEmpty() ? __('Pro') : $slot }}
</a>
