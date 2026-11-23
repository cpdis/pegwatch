@php($meta = \App\Support\PageMeta::for(request(), $title ?? null))
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>{{ $meta->title }}</title>

<meta name="description" content="{{ $meta->description }}" />
<link rel="canonical" href="{{ $meta->canonical }}" />
<meta property="og:site_name" content="{{ \App\Support\PageMeta::SITE_NAME }}" />
<meta property="og:type" content="website" />
<meta property="og:locale" content="en_AU" />
<meta property="og:title" content="{{ $meta->shareTitle }}" />
<meta property="og:description" content="{{ $meta->description }}" />
<meta property="og:url" content="{{ $meta->canonical }}" />
<meta name="twitter:title" content="{{ $meta->shareTitle }}" />
<meta name="twitter:description" content="{{ $meta->description }}" />
@if ($meta->image)
    <meta property="og:image" content="{{ $meta->image }}" />
    <meta property="og:image:width" content="1200" />
    <meta property="og:image:height" content="630" />
    <meta property="og:image:alt" content="{{ $meta->imageAlt }}" />
    <meta name="twitter:card" content="summary_large_image" />
    <meta name="twitter:image" content="{{ $meta->image }}" />
@else
    <meta name="twitter:card" content="summary" />
@endif
{{-- The header is near-black in both appearances. --}}
<meta name="theme-color" content="#0b0b0c" />

<link rel="icon" href="/favicon.ico" sizes="16x16 32x32 48x48">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="icon" href="/favicon.png" type="image/png" sizes="96x96">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<meta name="pegwatch-map" content="{{ json_encode(\App\Support\Format::mapConfig()) }}" />

{{-- Pages add head tags here with @push('head'), e.g. robots for natural-person holder pages. --}}
@stack('head')

@vite(['resources/css/app.css', 'resources/js/app.js'])
@fluxAppearance
