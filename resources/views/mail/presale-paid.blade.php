<x-mail::message>
# Thank you, your pre-order is in

You have one of the first Pegwatch Prospector places{{ $amount ? ', paid at '.$amount : '' }}. Stripe sends the payment receipt separately.

**What happens next.** Your year starts the day Prospector goes live, not today. We'll email you before it starts, from this address.

**Changed your mind?** Until Prospector goes live you can have a full refund, for any reason. Reply to this email or write to {{ $support }}.

@if ($business['name'])
Sold by {{ $business['name'] }}@if ($business['abn']), ABN {{ $business['abn'] }}@endif. {{ $business['gst_registered'] ? 'The price includes GST.' : 'No GST is charged: the seller is not registered for GST.' }}
@endif
</x-mail::message>
