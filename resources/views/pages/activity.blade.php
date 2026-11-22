<?php

use App\Support\Register;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Activity')] class extends Component {
    public const PER_PAGE = 50;

    #[Url]
    public ?string $type = null;

    #[Url]
    public int $page = 1;

    public function updatedType(): void
    {
        $this->page = 1;
    }

    public function with(): array
    {
        $type = is_string($this->type) && array_key_exists($this->type, Register::EVENT_LABELS) ? $this->type : null;
        $page = min(10000, max(1, $this->page));
        $events = Register::events($type ? ['type' => $type] : [], self::PER_PAGE + 1, ($page - 1) * self::PER_PAGE);

        return [
            'events' => array_slice($events, 0, self::PER_PAGE),
            'hasMore' => count($events) > self::PER_PAGE,
            'current' => $type,
            'pageNo' => $page,
            'labels' => Register::EVENT_LABELS,
        ];
    }
}; ?>

<div class="mx-auto max-w-5xl">
    <flux:heading size="xl" level="1">{{ __('What changed on the register') }}</flux:heading>
    <flux:text class="mt-1">{{ __("Every change Pegwatch has seen between one night's extract and the next.") }}</flux:text>

    <div class="mt-4 max-w-xs">
        <flux:select wire:model.live="type" :label="__('Change type')">
            <flux:select.option value="">{{ __('All changes') }}</flux:select.option>
            @foreach ($labels as $key => $label)
                <flux:select.option :value="$key">{{ $label }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    <div class="mt-4">
        <x-event-list :events="$events" />
    </div>

    <div class="mt-4 flex gap-2">
        @if ($pageNo > 1)
            <flux:button size="sm" icon="arrow-left" wire:click="$set('page', {{ $pageNo - 1 }})">{{ __('Newer') }}</flux:button>
        @endif
        @if ($hasMore)
            <flux:button size="sm" icon-trailing="arrow-right" wire:click="$set('page', {{ $pageNo + 1 }})">{{ __('Older') }}</flux:button>
        @endif
    </div>
</div>
