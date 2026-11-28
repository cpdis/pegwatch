{{--
    One digest issue on the web: a stored issue's content (WeeklyDigest::content) or the
    sample shown before the first issue (WeeklyDigest::sample). The mail has its own copy
    in mail/partials/weekly-digest-body.
--}}
@props(['c'])
@use('App\Digest\WeeklyDigest')
@use('App\Support\FeedGroups')
@use('App\Support\Format')
@use('App\Support\Slug')

@php
    $lead = WeeklyDigest::lead($c);
    $ha = fn ($n) => number_format((float) $n, 1).' ha';
    $type = fn ($e) => Format::sentence($e['tenement_type'] ?? '');
    $holderLinks = function (array $names) {
        $links = array_map(fn ($n) => '<a href="'.e(route('holders.show', ['slug' => Slug::make($n)])).'" class="hover:underline" wire:navigate>'.e($n).'</a>', array_slice($names, 0, 2));

        return implode('; ', $links).(count($names) > 2 ? ' +'.(count($names) - 2) : '');
    };
    // Plain links in running text: a component would add a space before the punctuation after it.
    $tenLink = fn ($e) => '<a href="'.e(route('tenements.show', ['slug' => $e['slug']])).'" class="underline decoration-zinc-800/20 underline-offset-4 hover:decoration-current dark:decoration-white/20" wire:navigate>'.e($e['fmt_tenid']).'</a>';
    $meta = fn (array $parts) => implode(' · ', array_filter($parts, fn ($p) => $p !== null && $p !== ''));
@endphp

@php
    // The first issue starts before Pegwatch was watching: say it is a part week.
    $since = \App\Support\Register::watchingSince();
    $partial = $since && isset($c['period_start']) && \Carbon\CarbonImmutable::parse($c['period_start'])->lt(\Carbon\CarbonImmutable::parse($since));
    // Applicant counts all at 1 rank nobody.
    $applicants = array_filter($c['applicants'] ?? [], fn ($h) => $h['n'] > 1) ? $c['applicants'] : [];
@endphp
<div {{ $attributes }}>
    @if ($partial)
        <flux:callout class="mt-6" icon="information-circle" :heading="__('A part week')">
            <flux:callout.text>{{ __('Pegwatch started watching the register on :date, so this issue covers less than a full week.', ['date' => Format::awst($since, false)]) }}</flux:callout.text>
        </flux:callout>
    @endif
    @if ($c['stale'] ?? false)
        <flux:callout class="mt-6" icon="clock" variant="warning" :heading="__('No new extract this week')">
            <flux:callout.text>
                {{ __('The department has not published a new extract of the register since :date, so there are no changes to report. We check every night.', ['date' => Format::awst($c['extract_at'] ?? null, false)]) }}
            </flux:callout.text>
        </flux:callout>
    @else
        @if ($lead)
            @php($e = $lead['item'])
            <div class="mt-6 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                <x-eyebrow>{{ __('The week\'s biggest change') }}</x-eyebrow>
                <p class="mt-1.5 text-lg font-medium leading-snug">
                    @if ($lead['kind'] === 'freed')
                        {!! $tenLink($e) !!}
                        {{ $e['reason'] ? __('ended (:reason), and :ha of it has no other live or pending tenement over it (L, G and special prospecting licences aside).', ['reason' => strtolower($e['reason']), 'ha' => $ha($e['uncovered_ha'])])
                            : __('ended, and :ha of it has no other live or pending tenement over it (L, G and special prospecting licences aside).', ['ha' => $ha($e['uncovered_ha'])]) }}
                    @else
                        {!! $holderLinks($e['holders']) !!} {{ __('applied for') }} {!! $tenLink($e) !!}{{ __(', :ha, the biggest application of the week.', ['ha' => $ha($e['area_ha'])]) }}
                    @endif
                </p>
                <flux:text class="mt-1 text-sm">
                    {!! $meta([e($type($e)), $lead['kind'] === 'freed' ? $holderLinks($e['holders']) : null, e(Format::field($e['fmt_tenid']))]) !!}
                </flux:text>
            </div>
        @endif

        @php($counts = array_filter($c['counts'] ?? []))
        <flux:text class="mt-4 text-sm tabular-nums">
            <span class="font-medium text-zinc-800 dark:text-white">{{ __('This week:') }}</span>
            {{ $counts ? FeedGroups::countLine($counts) : __('no changes') }}
        </flux:text>

        <flux:heading size="lg" class="mt-8">{{ __('Freed ground') }}</flux:heading>
        <flux:text class="mt-1 text-sm">{{ __('Tenements that ended, by the area no other tenement covers. Confirm in TENGRAPH and with the Mining Registrar before you act.') }}</flux:text>
        @if ($c['freed'])
            <ul class="mt-2 divide-y divide-zinc-200 dark:divide-zinc-700">
                @foreach ($c['freed'] as $e)
                    <li class="flex items-baseline gap-4 py-2.5">
                        <div class="min-w-0 flex-1">
                            <flux:link :href="route('tenements.show', ['slug' => $e['slug']])" wire:navigate class="font-medium tabular-nums">{{ $e['fmt_tenid'] }}</flux:link>
                            <span class="text-sm text-zinc-500 dark:text-zinc-400">{{ $type($e) }}</span>
                            <div class="text-sm text-zinc-500 dark:text-zinc-400">{!! $meta([e($e['reason'] ?? ''), $holderLinks($e['holders']), e(Format::field($e['fmt_tenid']))]) !!}</div>
                        </div>
                        <div class="shrink-0 text-end text-sm tabular-nums"><span class="font-medium">{{ $ha($e['uncovered_ha']) }}</span>
                            <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('uncovered') }}</div></div>
                    </li>
                @endforeach
            </ul>
        @else
            <flux:text class="mt-2">{{ __('No tenement ended this week leaving a hectare or more with no other tenement over it.') }}</flux:text>
        @endif

        <flux:heading size="lg" class="mt-8">{{ __('Biggest new applications') }}</flux:heading>
        @if ($c['applications'])
            <ul class="mt-2 divide-y divide-zinc-200 dark:divide-zinc-700">
                @foreach ($c['applications'] as $e)
                    <li class="flex items-baseline gap-4 py-2.5">
                        <div class="min-w-0 flex-1">
                            <flux:link :href="route('tenements.show', ['slug' => $e['slug']])" wire:navigate class="font-medium tabular-nums">{{ $e['fmt_tenid'] }}</flux:link>
                            <span class="text-sm text-zinc-500 dark:text-zinc-400">{{ $type($e) }}</span>
                            <div class="text-sm text-zinc-500 dark:text-zinc-400">{!! $meta([$holderLinks($e['holders']), e(Format::field($e['fmt_tenid']))]) !!}</div>
                        </div>
                        <div class="shrink-0 text-end text-sm font-medium tabular-nums">{{ $e['area_ha'] !== null ? $ha($e['area_ha']) : '' }}</div>
                    </li>
                @endforeach
            </ul>
        @else
            <flux:text class="mt-2">{{ __('No new applications this week.') }}</flux:text>
        @endif

        @if ($applicants)
            <flux:heading size="lg" class="mt-8">{{ __('Busiest applicants') }}</flux:heading>
            <ul class="mt-2 divide-y divide-zinc-200 dark:divide-zinc-700">
                @foreach ($applicants as $h)
                    <li class="flex items-baseline gap-4 py-2">
                        <flux:link :href="route('holders.show', ['slug' => $h['slug']])" wire:navigate class="min-w-0 flex-1">{{ $h['name'] }}</flux:link>
                        <span class="shrink-0 text-sm tabular-nums">{{ trans_choice(':n application|:n applications', $h['n'], ['n' => $h['n']]) }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    @endif

    <flux:heading size="lg" class="mt-8">{{ __('Upcoming compulsory releases') }}</flux:heading>
    @if ($c['releases'])
        <ul class="mt-2 divide-y divide-zinc-200 dark:divide-zinc-700">
            @foreach ($c['releases'] as $r)
                <li class="flex items-baseline gap-4 py-2.5">
                    <div class="min-w-0 flex-1">
                        <flux:link :href="route('tenements.show', ['slug' => $r['slug']])" wire:navigate class="font-medium tabular-nums">{{ $r['fmt_tenid'] }}</flux:link>
                        <span class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('releases :date AWST', ['date' => $r['release_at']]) }}</span>
                        <div class="text-sm text-zinc-500 dark:text-zinc-400">{!! $meta([$holderLinks($r['holders'] ?? []), e(Format::field($r['fmt_tenid']))]) !!}</div>
                    </div>
                    <div class="shrink-0 text-end text-sm font-medium tabular-nums">{{ $ha($r['area_ha']) }}</div>
                </li>
            @endforeach
        </ul>
    @else
        <flux:text class="mt-2">
            {{ __('None were on the register when this issue was made.') }}
            <flux:link :href="route('home')" wire:navigate>{{ __('See the releases due now on the map') }}</flux:link>.
        </flux:text>
    @endif
</div>
