{{--
    The support address as a mailto link. email_off stops Cloudflare's Email
    Obfuscation rewriting it to /cdn-cgi/l/email-protection, which breaks after
    a wire:navigate page change.
--}}
@php($email = config('pegwatch.business.support_email'))
<!--email_off--><a {{ $attributes->merge(['class' => 'underline underline-offset-2']) }} href="mailto:{{ $email }}">{{ $email }}</a><!--/email_off-->
