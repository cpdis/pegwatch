<?php

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Weekly WA tenement digest')] class extends Component {
    public function with(): array
    {
        return [
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
        {{ __('Every Monday night: the tenements that died and left ground with nothing over it, the biggest new applications and who lodged them, and the compulsory releases due in the coming week. All of Western Australia, from the department\'s own register.') }}
    </flux:text>

    <div class="mt-6">
        <livewire:digest-signup source="digest-page" />
    </div>

    <flux:text class="mt-4 text-sm">
        {{ __('We keep your email address and nothing else, and never share it.') }}
        {{ __('Want the changes in one area the night they happen instead?') }}
        <flux:link :href="route('register')" wire:navigate>{{ __('Set up a free watch area') }}</flux:link>.
    </flux:text>

    <flux:heading size="lg" class="mt-10">{{ __('Past issues') }}</flux:heading>
    @if ($issues->isEmpty())
        <flux:text class="mt-2">{{ __('The first issue goes out after the first full week of changes.') }}</flux:text>
    @else
        <ul class="mt-2 divide-y divide-zinc-200 dark:divide-zinc-700">
            @foreach ($issues as $i)
                <li class="py-2">
                    <flux:link :href="route('digest.issue', ['week' => $i['week']])" wire:navigate>{{ __('Week to :date', ['date' => $i['to']]) }}</flux:link>
                    <flux:text class="inline text-sm">· {{ trans_choice(':n tenement left open ground|:n tenements left open ground', $i['freed'], ['n' => $i['freed']]) }}</flux:text>
                </li>
            @endforeach
        </ul>
    @endif
</div>
