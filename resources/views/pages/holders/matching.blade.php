<?php

use App\Support\Register;
use Livewire\Component;

/**
 * Companies whose registered names start the same way ("Entities matching
 * NORTHERN STAR"). The register has no parent-company field, so the page
 * says this is a name match, and counts joint tenements once.
 */
new class extends Component {
    public array $group;

    public function mount(string $slug): void
    {
        abort_unless($group = Register::nameMatches($slug), 404);
        $this->group = $group;
    }

    public function render()
    {
        return $this->view()->title(__('Entities matching “:name”', ['name' => $this->group['label']]));
    }
}; ?>

<div class="max-w-3xl">
    <x-eyebrow>{{ __('Name match') }}</x-eyebrow>
    <flux:heading size="xl" level="1" class="mt-1 !text-3xl font-bold tracking-tight">{{ __('Entities matching “:name”', ['name' => $group['label']]) }}</flux:heading>
    <flux:text class="mt-2">
        {{ __(':n companies on the register have names starting “:name”. Between them they hold :t distinct live or pending tenements (:ha ha), each counted once however many of them share it.', [
            'n' => count($group['entities']), 'name' => $group['label'],
            't' => number_format($group['tenements']), 'ha' => number_format($group['area_ha']),
        ]) }}
    </flux:text>
    <flux:callout icon="information-circle" variant="secondary" class="mt-4" :text="__('This is a match on names, not ownership. The register has no parent-company field, so the list can include unrelated companies and miss related ones with other names.')" />

    <flux:table class="mt-4 tabular-nums">
        <flux:table.columns>
            <flux:table.column>{{ __('Entity') }}</flux:table.column>
            <flux:table.column align="end">{{ __('Live') }}</flux:table.column>
            <flux:table.column align="end">{{ __('Pending') }}</flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @foreach ($group['entities'] as $e)
                <flux:table.row :key="$e['slug']">
                    <flux:table.cell variant="strong" class="max-w-0 w-full truncate"><flux:link :href="route('holders.show', $e['slug'])" wire:navigate>{{ $e['name'] }}</flux:link></flux:table.cell>
                    <flux:table.cell align="end">{{ number_format($e['live']) }}</flux:table.cell>
                    <flux:table.cell align="end">{{ number_format($e['pending']) }}</flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>
</div>
