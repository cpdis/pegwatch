@php
    $from = \Carbon\CarbonImmutable::parse($c['period_start'])->setTimezone('Australia/Perth');
    $to = \Carbon\CarbonImmutable::parse($c['period_end'])->setTimezone('Australia/Perth');
@endphp
<x-mail::message>
<x-mail::eyebrow>Pegwatch weekly</x-mail::eyebrow>

# The WA register, {{ $from->format('j M') }} to {{ $to->format('j M Y') }}

@include('mail.partials.weekly-digest-body', ['c' => $c])

<x-mail::button :url="url('/')">
Open the map
</x-mail::button>

[Read this issue on the web]({{ $archiveUrl }}). Want alerts for one area the night they happen?
[Set up a free watch area]({{ Route::has('watch.start') ? route('watch.start') : route('register') }}).

This is a screening aid, not advice about any ground. Confirm every tenement in
[TENGRAPH]({{ \App\Support\Format::TENGRAPH_URL }}) and with the Mining Registrar before you act.

<x-mail::subcopy>
You get this because you subscribed to the Pegwatch weekly digest. [Unsubscribe]({{ $unsubscribeUrl }}).

Data © State of Western Australia (Department of Mines, Petroleum and Exploration), CC BY 4.0, changes made.
</x-mail::subcopy>
</x-mail::message>
