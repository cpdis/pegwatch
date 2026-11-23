@props([
    'title',
    'description' => null,
])

<div class="flex w-full flex-col gap-1">
    <flux:heading size="xl" level="1">{{ $title }}</flux:heading>
    @if ($description)
        <flux:subheading>{{ $description }}</flux:subheading>
    @endif
</div>
