<p class="mt-1 text-zinc-200 first:mt-0">
    {{ __('Register extract of :when AWST.', ['when' => $fresh->extract->format('j M Y, g:ia')]) }}
</p>
<p class="mt-2 text-zinc-400">
    {{ __('The department publishes a new extract most business days. Pegwatch checks every night.') }}
    @if ($fresh->lastCheck)
        {{ __('Last check: :when AWST.', ['when' => $fresh->lastCheck->format('j M, g:ia')]) }}
    @endif
</p>
<flux:link :href="route('data')" wire:navigate class="mt-2 inline-block text-white!">{{ __('Data status and disclaimer') }}</flux:link>
