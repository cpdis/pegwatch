<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>
    {{ filled($title ?? null) ? $title.' - '.config('app.name', 'Laravel') : config('app.name', 'Laravel') }}
</title>

<meta name="description" content="{{ __('A map of every live, pending and dead mining tenement in Western Australia, with free email alerts when a tenement near you is recorded dead or has ground scheduled for release.') }}" />
<meta name="theme-color" content="#fafafa" media="(prefers-color-scheme: light)" />
<meta name="theme-color" content="#171717" media="(prefers-color-scheme: dark)" />

<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
@stack('head')

@vite(['resources/css/app.css', 'resources/js/app.js'])
@fluxAppearance
