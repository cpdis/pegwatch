{{-- A real alert email, as AlertPreview::sample() builds it from the latest register changes. --}}
@props(['preview' => \App\Alerts\AlertPreview::sample()])
<figure {{ $attributes->class('overflow-hidden rounded-xl bg-white text-zinc-900 shadow-2xl ring-1 ring-black/10') }} aria-label="{{ __('An example alert email') }}">
    <div class="border-b border-zinc-200 px-5 py-3 text-xs text-zinc-500">
        <div><span class="font-medium text-zinc-700">{{ __('From') }}</span> Pegwatch</div>
        <div class="mt-0.5 truncate"><span class="font-medium text-zinc-700">{{ __('Subject') }}</span> {{ $preview['subject'] }}</div>
    </div>
    <div class="space-y-4 px-5 py-4 text-sm">
        @foreach ($preview['items'] as $item)
            <div>
                <div><span class="font-semibold tabular-nums">{{ $item['id'] }}</span> <span class="text-zinc-500">· {{ $item['meta'] }}</span></div>
                <p class="mt-0.5 text-zinc-700">{{ $item['line'] }}</p>
            </div>
        @endforeach
    </div>
</figure>
