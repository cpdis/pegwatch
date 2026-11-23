<?php

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Weekly WA tenement digest')] class extends Component {
    public function with(): array
    {
        $issues = DB::table('digest_issues')->exists();

        return [
            // Before the first issue, a sample shows what one looks like (D1).
            'sample' => $issues ? null : app(\App\Digest\WeeklyDigest::class)->sample(),
            'issues' => DB::table('digest_issues')->orderByDesc('period_end')->limit(52)
                ->get(['week', 'period_start', 'period_end', 'content'])
                ->map(fn ($i) => [
                    'week' => $i->week,
                    'to' => \App\Support\Format::awst($i->period_end, false),
                    'freed' => count(json_decode($i->content, true)['freed'] ?? []),
                ]),
        ];
    }
}; ?>

<div class="max-w-2xl">
    <flux:heading size="xl" level="1">{{ __('The WA tenement register, once a week') }}</flux:heading>
    <flux:text class="mt-2 text-base">
        {{ __('Every Monday night: the week\'s biggest change, the tenements that ended leaving ground no other tenement covers, the biggest new applications and who lodged them, and the compulsory releases coming up. All of Western Australia, from the department\'s own register.') }}
    </flux:text>

    <div class="mt-6">
        <livewire:digest-signup source="digest-page" />
    </div>

    <flux:text class="mt-4 text-sm">
        {{ __('We keep your email address and nothing else, and never share it.') }}
        {{ __('Want the changes in one area the night they happen instead?') }}
        <flux:link :href="Route::has('watch.start') ? route('watch.start') : route('register')" wire:navigate>{{ __('Set up a free watch area') }}</flux:link>.
    </flux:text>

    @if ($sample)
        <section class="mt-10" aria-labelledby="digest-sample">
            <div class="flex flex-wrap items-center gap-2">
                <flux:heading size="lg" id="digest-sample">{{ __('What an issue looks like') }}</flux:heading>
                <flux:badge size="sm" color="amber">{{ __('Sample') }}</flux:badge>
            </div>
            <flux:text class="mt-1 text-sm">
                {{ __('Built from the register for the 7 days to :date, the latest extract. The first issue goes out on the Monday after the first full week of changes.', ['date' => \App\Support\Format::awst($sample['extract_at'] ?? $sample['period_end'], false)]) }}
            </flux:text>
            <div class="mt-2 rounded-xl border border-dashed border-zinc-300 px-4 pb-5 sm:px-6 dark:border-zinc-600">
                <x-digest.issue :c="$sample" />
            </div>
        </section>
    @endif

    <flux:heading size="lg" class="mt-10">{{ __('Past issues') }}</flux:heading>
    @if ($issues->isEmpty())
        <flux:text class="mt-2">{{ __('None yet. The first issue goes out after the first full week of changes.') }}</flux:text>
    @else
        <ul class="mt-2 divide-y divide-zinc-200 dark:divide-zinc-700">
            @foreach ($issues as $i)
                <li class="py-2">
                    <flux:link :href="route('digest.issue', ['week' => $i['week']])" wire:navigate>{{ __('Week to :date', ['date' => $i['to']]) }}</flux:link>
                    <flux:text class="inline text-sm">· {{ trans_choice(':n tenement freed ground|:n tenements freed ground', $i['freed'], ['n' => $i['freed']]) }}</flux:text>
                </li>
            @endforeach
        </ul>
    @endif
</div>
