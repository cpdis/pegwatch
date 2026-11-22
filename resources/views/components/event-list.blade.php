@props(['events', 'compact' => false])
@use('App\Support\Format')

@if (count($events) === 0)
    <flux:text class="text-sm">{{ __('No changes recorded yet. Changes appear after the second nightly run.') }}</flux:text>
@else
    <ul class="divide-y divide-zinc-200 dark:divide-zinc-700">
        @foreach ($events as $e)
            <li wire:key="event-{{ $e['id'] }}" class="py-2"
                x-on:mouseenter="$dispatch('event-hover', { id: {{ $e['id'] }} })"
                x-on:mouseleave="$dispatch('event-hover', { id: null })">
                <div class="flex items-baseline gap-2 text-sm">
                    <span class="inline-block size-2 shrink-0 rounded-full" style="background: {{ Format::eventColour($e['type']) }}"></span>
                    <span class="font-medium">{{ $e['label'] }}</span>
                    <flux:link :href="route('tenements.show', $e['slug'])" wire:navigate>{{ $e['fmt_tenid'] }}</flux:link>
                    @unless ($compact)
                        <span class="ms-auto text-xs text-zinc-500">{{ __('detected') }} {{ Format::awst($e['detected_at'], false) }}</span>
                    @endunless
                </div>
                <div class="ps-4 text-xs text-zinc-500 dark:text-zinc-400">
                    {{ $e['summary'] }}
                    @if (count($e['holders']))
                        ·
                        @foreach (array_slice($e['holders'], 0, 2) as $h)
                            @if (! $loop->first), @endif
                            <a href="{{ route('holders.show', $h['slug']) }}" class="hover:underline" wire:navigate>{{ $h['name'] }}</a>
                        @endforeach
                        @if (count($e['holders']) > 2) +{{ count($e['holders']) - 2 }} @endif
                    @endif
                    @if ($e['occurred_at'] && ! $compact) · {{ Format::localDate($e['occurred_at'], true) }} @endif
                </div>
            </li>
        @endforeach
    </ul>
@endif
