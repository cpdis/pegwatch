<x-mail::message>
<x-mail::eyebrow>Release reminder · {{ $area->name }}</x-mail::eyebrow>

# {{ $kind === 'hour' ? 'Releasing within the hour' : 'Releasing within a day' }}

Compulsory releases in your watch "{{ $area->name }}". Applications lodged in the first 15 minutes after release time go to ballot.

@foreach ($items as $item)
<span style="color: {{ \App\Support\Format::STATUS_COLOURS['release'] }};">&#9679;</span> **[{{ $item['id'] }}]({{ $item['url'] }})**@if ($item['meta']) · {{ $item['meta'] }}@endif<br>
{{ $item['when'] }}. Release {{ $item['rlesid'] }}{{ $item['ha'] ? ', '.$item['ha'].' ha' : '' }}.

@endforeach
A calendar file for each release is attached.

Pegwatch is a screening aid, not advice. Confirm in [TENGRAPH]({{ \App\Support\Format::TENGRAPH_URL }}) and with the Mining Registrar before you act.

<x-mail::subcopy>
[Stop alerts for this area]({{ $stopUrl }}) · [Manage your watches]({{ route('watch-areas.index') }})
</x-mail::subcopy>
</x-mail::message>
