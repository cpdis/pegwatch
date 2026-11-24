<?php

use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Agent apps the person signed in to their account (OAuth), and a way to sign each out. */
new #[Title('Connected agents')] class extends Component {
    #[Computed]
    public function agents(): array
    {
        return Auth::user()->connectedAgents();
    }

    public function disconnect(string $clientId): void
    {
        Auth::user()->disconnectAgents($clientId);
        unset($this->agents);
        Flux::toast(variant: 'success', text: __('Disconnected. It can no longer see or change your watches.'));
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Connected agents') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Connected agents')" :subheading="__('AI agents you let see and change your watches. Disconnecting one signs it out at once.')">
        @forelse ($this->agents as $agent)
            <div wire:key="agent-{{ $agent->client_id }}" class="flex items-center justify-between gap-4 border-b border-zinc-200 py-3 last:border-0 dark:border-zinc-700" data-test="connected-agent">
                <div class="min-w-0">
                    <flux:text class="truncate font-medium text-zinc-900 dark:text-white">{{ $agent->name }}</flux:text>
                    <flux:text class="text-sm">{{ __('Connected :date', ['date' => \Illuminate\Support\Carbon::parse($agent->connected_at)->setTimezone('Australia/Perth')->format('j M Y')]) }}</flux:text>
                </div>
                <flux:button size="sm" variant="danger" wire:click="disconnect('{{ $agent->client_id }}')">{{ __('Disconnect') }}</flux:button>
            </div>
        @empty
            <flux:text>{{ __('None yet. An agent connects at :url and asks you here first.', ['url' => url('/mcp/account')]) }}</flux:text>
            <flux:link class="mt-2 inline-block text-sm" :href="route('agents')" wire:navigate>{{ __('How agents use Pegwatch') }}</flux:link>
        @endforelse
    </x-pages::settings.layout>
</section>
