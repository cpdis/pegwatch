<x-mail::message>
# {{ $area->name }}

Changes on the WA tenement register within {{ $area->radius_km }} km of your watch area,
from the department's extract of {{ $run?->tenements_extract ? \Illuminate\Support\Carbon::parse($run->tenements_extract)->timezone('Australia/Perth')->format('j M Y, g:ia') : 'the latest extract' }} AWST.

@foreach ($events as $e)
**{{ $e['label'] }}: [{{ $e['fmt_tenid'] }}]({{ url('/tenements/'.$e['slug']) }})**
{{ $e['tenement_type'] }}@if ($e['holders']) · {{ collect($e['holders'])->pluck('name')->join(', ') }}@endif

{{ $e['summary'] }}

@endforeach

<x-mail::button :url="url('/')">
Open the map
</x-mail::button>

You get this email because of your watch area "{{ $area->name }}". To stop these emails,
[delete the watch area]({{ route('watch-areas.index') }}).

This is a screening aid, not advice that ground can be applied for. Confirm every tenement in
TENGRAPH and with the Mining Registrar before you act.

Data © State of Western Australia (Department of Mines, Petroleum and Exploration), CC BY 4.0, changes made.
</x-mail::message>
