{{--
    The page an emailed link opens: what the link is for and one button. Only
    the button's POST acts, so mail scanners that open links change nothing.
--}}
<x-layouts::auth :title="$title">
    <div class="flex flex-col gap-6">
        <div class="space-y-2">
            <flux:heading size="xl" level="1">{{ $heading }}</flux:heading>
            @foreach ($lines ?? [] as $line)
                <flux:text>{{ $line }}</flux:text>
            @endforeach
        </div>

        @if (! empty($note))
            <flux:callout icon="information-circle" color="amber">
                <flux:callout.text>{{ $note }}</flux:callout.text>
                @if (! empty($pro))
                    <x-slot name="actions">
                        <x-pro-lock ref="second-area">{{ __('Keep both with Prospector') }}</x-pro-lock>
                    </x-slot>
                @endif
            </flux:callout>
        @endif

        @if (! empty($action))
            <form method="POST" action="{{ $action }}">
                @csrf
                @foreach ($fields ?? [] as $name => $value)
                    <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                @endforeach
                <flux:button type="submit" :variant="$variant ?? 'primary'" class="w-full" data-test="link-button">{{ $button }}</flux:button>
            </form>
        @endif

        @if (! empty($secondary))
            <flux:button :href="$secondary[1]" variant="{{ empty($action) ? 'primary' : 'ghost' }}" class="w-full" wire:navigate>{{ $secondary[0] }}</flux:button>
        @endif
    </div>
</x-layouts::auth>
