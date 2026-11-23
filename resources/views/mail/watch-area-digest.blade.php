<x-mail::message>
@if ($preview)
*Preview: what this email would have said about the last 30 days. Real emails come after a night with changes.*

@endif
# {{ $area->name }}

{{ $describe }}. Changes on the WA tenement register@if ($extract) in the department's extract of {{ $extract }} AWST@endif.

@if (\Illuminate\Support\Facades\Route::has('og.area'))
![{{ __('Map of :name', ['name' => $area->name]) }}]({{ \Illuminate\Support\Facades\URL::signedRoute('og.area', ['watchArea' => $area->id]) }})

@endif
@forelse ($groups as $group => $items)
## {{ \App\Mail\WatchAreaDigest::GROUP_HEADINGS[$group] }}

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
You get this because you watch "{{ $area->name }}". [Stop alerts for this area]({{ $stopUrl }}) · [Manage your watches]({{ route('watch-areas.index') }})

Data © State of Western Australia (Department of Mines, Petroleum and Exploration), CC BY 4.0, changes made.
</x-mail::subcopy>
</x-mail::message>
