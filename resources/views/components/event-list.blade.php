{{--
    A list of register changes: diff events (Register::events) or feed items (Register::feed).
    context picks the empty-state sentence and its sign-up: map, activity, tenement or holder.
    subject names the tenement or holder for the empty state. filtered says the list is
    narrowed by filters or the map view, so an empty list blames them, not the register.
    days (day => [type => n]) groups rows under day headings with those counts.
--}}
@props(['events', 'context' => 'activity', 'subject' => null, 'filtered' => false, 'compact' => false, 'days' => null])
@use('App\Support\FeedGroups')
@use('App\Support\Format')
@use('App\Support\Register')

@if (count($events) === 0)
    @php
        $run = Register::latestRun();
        $since = Format::awst(Register::watchingSince(), false);
        $sentence = match (true) {
            $filtered && $context === 'map' => __('No changes on the register in this part of the map. Move or zoom out to see more.'),
            (bool) $filtered => __('No changes match these filters. Widen the dates or clear a filter.'),
            $run === null || Register::baselineOnly() => __('Pegwatch has its first copy of the register. Changes show here from the next extract.'),
            Register::isStale($run) => __('No changes since :date: the department has not published a new extract. We check every night.', [
                'date' => \Carbon\CarbonImmutable::parse($run->tenements_extract)->setTimezone('Australia/Perth')->format('j M'),
            ]),
            $context === 'tenement' && $subject => __('No changes to :id since Pegwatch started watching on :date.', ['id' => $subject, 'date' => $since]),
            $context === 'holder' && $subject => __('No changes to :name\'s tenements since Pegwatch started watching on :date.', ['name' => $subject, 'date' => $since]),
            default => __('No changes since Pegwatch started watching on :date.', ['date' => $since]),
        };
    @endphp
    <div {{ $attributes->class('rounded-lg border border-dashed border-zinc-300 p-4 dark:border-zinc-600') }} data-empty-state="{{ $context }}">
        <flux:text class="text-sm">{{ $sentence }}</flux:text>
        @if ($context === 'tenement')
            <flux:button class="mt-3" size="sm" icon="bell-alert"
                         :href="Route::has('watch.start') ? route('watch.start', ['tenement' => \App\Support\Slug::make((string) $subject)]) : route('register')">
                {{ __('Email me when this changes') }}
            </flux:button>
        @else
            @guest
                <flux:heading size="sm" class="mt-4">{{ __('Get the week\'s changes every Monday') }}</flux:heading>
                <div class="mt-2"><livewire:digest-signup :source="'empty-'.$context" compact :key="'signup-empty-'.$context" /></div>
            @endguest
        @endif
    </div>
@else
    @php
        $entries = FeedGroups::collapse(array_values($events));
        $groups = $days !== null ? FeedGroups::byDay($entries) : [['day' => null, 'entries' => $entries]];
    @endphp
    <div {{ $attributes }}>
        @foreach ($groups as $group)
            @if ($group['day'])
                <div class="sticky top-0 z-[1] flex flex-wrap items-baseline gap-x-3 border-b border-zinc-200 bg-white/95 pt-4 pb-1.5 backdrop-blur dark:border-zinc-700 dark:bg-zinc-800/95"
                     wire:key="day-{{ $group['day'] }}">
                    <h3 class="text-sm font-semibold">{{ FeedGroups::dayLabel($group['day']) }}</h3>
                    <span class="text-xs tabular-nums text-zinc-500 dark:text-zinc-400">{{ FeedGroups::countLine($days[$group['day']] ?? []) }}</span>
                </div>
            @endif
            <ul class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @foreach ($group['entries'] as $e)
                    @if ($e['bulk'] ?? false)
                        <li wire:key="{{ $e['key'] }}" class="py-2.5">
                            <div class="flex items-baseline gap-2 text-sm">
                                <span class="inline-block size-2 shrink-0 translate-y-[-1px] rounded-full dark:ring-1 dark:ring-white/60" style="background: {{ Format::eventColour('application') }}"></span>
                                <span><a href="{{ route('holders.show', $e['holder']['slug']) }}" class="font-medium hover:underline" wire:navigate>{{ $e['holder']['name'] }}</a>{{ \Illuminate\Support\Str::after($e['sentence'], $e['holder']['name']) }}</span>
                            </div>
                            <details class="group ps-4 text-xs text-zinc-500 dark:text-zinc-400">
                                <summary class="mt-1 cursor-pointer select-none py-1 hover:text-zinc-800 dark:hover:text-white">{{ __('Show the :n', ['n' => count($e['members'])]) }}</summary>
                                <ul class="mt-1 grid grid-cols-2 gap-x-4 gap-y-1 sm:grid-cols-3">
                                    @foreach ($e['members'] as $m)
                                        <li class="tabular-nums" x-on:mouseenter="$dispatch('map-select', { tenid: @js($m['tenid']) })" x-on:mouseleave="$dispatch('map-select', { tenid: null })">
                                            @if ($m['slug'])
                                                <a href="{{ route('tenements.show', $m['slug']) }}" class="text-zinc-800 hover:underline dark:text-zinc-100" wire:navigate>{{ $m['fmt_tenid'] }}</a>
                                            @else
                                                <a href="{{ $m['notice']['link'] ?? '#' }}" class="text-zinc-800 hover:underline dark:text-zinc-100" target="_blank" rel="noreferrer">{{ $m['fmt_tenid'] }}</a>
                                            @endif
                                            @if ($m['area_ha'])<span> · {{ number_format($m['area_ha']) }} ha</span>@endif
                                        </li>
                                    @endforeach
                                </ul>
                            </details>
                        </li>
                        @continue
                    @endif
                    <li wire:key="{{ $e['key'] }}" class="py-2.5"
                        x-on:mouseenter="$dispatch('event-hover', { id: @js($e['key']) }); $dispatch('map-select', { tenid: @js($e['tenid'] ?? null) })"
                        x-on:mouseleave="$dispatch('event-hover', { id: null }); $dispatch('map-select', { tenid: null })">
                        <div class="flex items-baseline gap-2 text-sm">
                            <span class="inline-block size-2 shrink-0 translate-y-[-1px] rounded-full dark:ring-1 dark:ring-white/60" style="background: {{ Format::eventColour($e['type']) }}"></span>
                            <span class="font-medium">{{ $e['label'] }}</span>
                            @if ($e['slug'] ?? null)
                                <flux:link :href="route('tenements.show', $e['slug'])" wire:navigate class="tabular-nums">{{ $e['fmt_tenid'] }}</flux:link>
                            @else
                                <span class="tabular-nums">{{ $e['fmt_tenid'] }}</span>
                            @endif
                            @if (! $days)
                                <span class="ms-auto shrink-0 text-xs tabular-nums text-zinc-500 dark:text-zinc-400">{{ Format::localDate($e['at'] ?? null) }}</span>
                            @endif
                        </div>
                        <div class="ps-4 text-xs text-zinc-500 dark:text-zinc-400">
                            {{-- A holder change already names the holders in its summary. --}}
                            @php
                                $holders = $e['type'] === 'holder_change' ? [] : array_slice($e['holders'], 0, 2);
                                $field = $compact ? null : Format::field($e['fmt_tenid']);
                            @endphp
                            {{ $e['summary'] }}
                            @if ($holders)
                                @if ($e['summary'] !== '') · @endif
                                @foreach ($holders as $h)<a href="{{ route('holders.show', $h['slug']) }}" class="hover:underline" wire:navigate>{{ $h['name'] }}</a>@if (! $loop->last); @endif
                                @endforeach
                                @if (count($e['holders']) > 2) +{{ count($e['holders']) - 2 }} @endif
                            @endif
                            @if ($field)@if ($e['summary'] !== '' || $holders) · @endif{{ $field }}@endif
                        </div>
                        @unless ($compact)
                            <div class="ps-4 text-[11px] text-zinc-400 dark:text-zinc-500">
                                {{ Register::FEED_SOURCES[$e['source'] ?? 'change'] }}@if (($e['source'] ?? 'change') === 'change' && $e['detected_at']), {{ __('seen :date', ['date' => Format::awst($e['detected_at'], false)]) }}@endif
                                @if ($e['notice']['link'] ?? null)· <a href="{{ $e['notice']['link'] }}" class="underline hover:text-zinc-700 dark:hover:text-zinc-200" target="_blank" rel="noreferrer">{{ __('the notice') }}</a>@endif
                            </div>
                        @endunless
                    </li>
                @endforeach
            </ul>
        @endforeach
    </div>
@endif
