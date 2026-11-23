<x-mail::message>
# Confirm your watch

Press the button to start watching **{{ $area->name }}**: {{ $describe }}.

We email you after a night when the WA register changes there: tenements recorded dead, with the ground they leave uncovered, and ground scheduled for compulsory release.

<x-mail::button :url="$confirmUrl">
Start watching {{ $area->name }}
</x-mail::button>

The link works for {{ $days }} days. If you didn't ask for this, ignore this email and nothing more will be sent.
</x-mail::message>
