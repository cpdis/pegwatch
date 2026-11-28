<x-mail::message>
<x-mail::eyebrow>Sign in</x-mail::eyebrow>

# Log in to Pegwatch

Press the button to log in as {{ $email }}. The link works once, for the next {{ $minutes }} minutes.

<x-mail::button :url="$url">
Log in
</x-mail::button>

<x-mail::subcopy>
If you didn't ask for this, ignore this email. No one can log in without the link.
</x-mail::subcopy>
</x-mail::message>
