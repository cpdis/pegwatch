{{-- Marks a Prospector-plan feature and links to the plans page. --}}
@props(['ref' => null])
<a href="{{ url('/pro').($ref ? '?ref='.urlencode($ref) : '') }}" wire:navigate
   {{-- An outline chip, quieter than the yellow buttons; at least 36px tall on phones so it can be tapped. --}}
   {{ $attributes->class('inline-flex items-center gap-1 rounded-full border border-amber-600/40 px-2.5 py-0.5 text-xs font-medium text-amber-800 hover:bg-amber-50 max-sm:min-h-9 dark:border-accent/40 dark:text-accent dark:hover:bg-white/5') }}>
    <flux:icon.lock-closed variant="micro" class="size-3" />{{ $slot->isEmpty() ? __('Pro') : $slot }}
</a>
