@php
    $label = fn ($type) => \App\Support\Register::EVENT_LABELS[$type] ?? $type;
    $from = \Carbon\CarbonImmutable::parse($c['period_start'])->setTimezone('Australia/Perth');
    $to = \Carbon\CarbonImmutable::parse($c['period_end'])->setTimezone('Australia/Perth');
@endphp
<x-mail::message>
# The WA register, {{ $from->format('j M') }} to {{ $to->format('j M Y') }}

@include('mail.partials.weekly-digest-body', ['c' => $c, 'label' => $label])

<x-mail::button :url="url('/')">
Open the map
</x-mail::button>

[Read this issue on the web]({{ $archiveUrl }}). Want alerts for one area the night they happen?
[Set up a free watch area]({{ route('register') }}).

This is a screening aid, not advice that ground can be applied for. Confirm every tenement in
TENGRAPH and with the Mining Registrar before you act.

You get this because you subscribed to the Pegwatch weekly digest. [Unsubscribe]({{ $unsubscribeUrl }}).

Data © State of Western Australia (Department of Mines, Petroleum and Exploration), CC BY 4.0, changes made.
</x-mail::message>
