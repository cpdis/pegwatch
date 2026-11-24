<?php

use Illuminate\Support\Str;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * For agents (route agents): how AI agents and scripts use Pegwatch. The
 * text is resources/views/agent/guide.blade.php, the same Markdown the Agent
 * Skill and llms-full.txt serve, so the three cannot drift.
 */
new #[Title('For agents')] class extends Component {
    public function with(): array
    {
        return ['html' => Str::markdown(view('agent.guide')->render(), ['html_input' => 'strip', 'allow_unsafe_links' => false])];
    }
}; ?>

<div class="max-w-3xl">
    <x-eyebrow dot="#ffd400">{{ __('MCP, API and Markdown') }}</x-eyebrow>
    <flux:heading size="xl" level="1" class="mt-1">{{ __('Pegwatch for agents') }}</flux:heading>

    <div data-test="agent-guide" class="mt-4 space-y-4 text-sm leading-6 text-zinc-700 dark:text-zinc-300
        [&_h2]:mt-10 [&_h2]:text-lg [&_h2]:font-semibold [&_h2]:text-zinc-900 dark:[&_h2]:text-white
        [&_a]:font-medium [&_a]:text-zinc-900 [&_a]:underline [&_a]:decoration-accent [&_a]:underline-offset-2 dark:[&_a]:text-white
        [&_code]:rounded [&_code]:bg-zinc-100 [&_code]:px-1 [&_code]:py-0.5 [&_code]:text-[0.8125rem] dark:[&_code]:bg-zinc-800
        [&_ul]:list-disc [&_ul]:space-y-2 [&_ul]:ps-5 [&_strong]:text-zinc-900 dark:[&_strong]:text-white
        [&_table]:block [&_table]:w-full [&_table]:overflow-x-auto [&_table]:text-left
        [&_th]:border-b [&_th]:border-zinc-200 [&_th]:py-2 [&_th]:pe-4 [&_th]:font-semibold dark:[&_th]:border-zinc-700
        [&_td]:border-b [&_td]:border-zinc-100 [&_td]:py-2 [&_td]:pe-4 [&_td]:align-top dark:[&_td]:border-zinc-800">
        {!! $html !!}
    </div>
</div>
