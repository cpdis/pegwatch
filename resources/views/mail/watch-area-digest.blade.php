<x-mail::message>
@if ($preview)
<x-mail::panel>
Preview: what this email would have said about the last 30 days. Real emails come after a night with changes.
</x-mail::panel>

@endif
<x-mail::eyebrow>Watch alert</x-mail::eyebrow>

# {{ $area->name }}

{{ $describe }}. {{ $extract ? __('Changes on the WA tenement register in the department\'s extract of :time AWST.', ['time' => $extract]) : __('Changes on the WA tenement register.') }}

@if (\Illuminate\Support\Facades\Route::has('og.area'))
![{{ __('Map of :name', ['name' => $area->name]) }}]({{ \Illuminate\Support\Facades\URL::signedRoute('og.area', ['watchArea' => $area->id]) }})

@endif
@forelse ($groups as $group => $items)
## <span style="color: {{ \App\Mail\WatchAreaDigest::GROUP_COLOURS[$group] }};">&#9679;</span> {{ \App\Mail\WatchAreaDigest::GROUP_HEADINGS[$group] }}

@foreach ($items as $item)
**[{{ $item['id'] }}]({{ $item['url'] }})**@if ($item['meta']) · {{ $item['meta'] }}@endif<br>
{{ $item['line'] }}@if ($item['holders'])<br>
Holders: {{ $item['holders'] }}@endif


@endforeach
@empty
Nothing in this watch changed.

@endforelse
<x-mail::button :url="$mapUrl">
{{ $area->kind === 'holder' ? __('Open the holder') : __('Open the map') }}
</x-mail::button>

Pegwatch is a screening aid, not advice. Confirm every tenement in [TENGRAPH]({{ \App\Support\Format::TENGRAPH_URL }}) and with the Mining Registrar before you act.

<x-mail::subcopy>
You get this because you watch "{{ $area->name }}".

[Stop alerts for this area]({{ $stopUrl }}) · [Manage your watches]({{ route('watch-areas.index') }})

Data © State of Western Australia (Department of Mines, Petroleum and Exploration), CC BY 4.0, changes made.
</x-mail::subcopy>
</x-mail::message>
