{{--
    Pegwatch's email shell: the site's near-black header with the Peg P mark and a
    hi-vis rule, a white card, and a quiet footer. Tables and inline CSS (the theme
    in themes/pegwatch.css is inlined at send time) so Outlook and Gmail keep it.
    Only the rules in <style> below survive inlining: phone widths and dark mode
    (Apple Mail, iOS Mail and Outlook for Mac honour them; Gmail ignores both).
--}}
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
<title>{{ config('app.name') }}</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<meta name="color-scheme" content="light dark">
<meta name="supported-color-schemes" content="light dark">
<style>
@media only screen and (max-width: 620px) {
.content, .inner-body, .footer { width: 100% !important; }
.wrapper { padding: 0 !important; }
.header, .inner-body { border-radius: 0 !important; }
.content-cell { padding: 24px 20px !important; }
.header-cell { padding: 16px 20px !important; }
.footer-cell { padding: 20px !important; }
.header-tag { display: none !important; }
}
@media only screen and (max-width: 480px) {
.button { display: block !important; text-align: center !important; }
}
@media (prefers-color-scheme: dark) {
body, .wrapper { background-color: #0b0b0c !important; }
.inner-body { background-color: #171717 !important; border-color: #262626 !important; }
.content-cell p, .content-cell li, .content-cell td, .content-cell th { color: #d4d4d4 !important; }
.content-cell h1, .content-cell strong { color: #ffffff !important; }
.content-cell a { color: #ffffff !important; }
.content-cell h2, .eyebrow, .table th { color: #a3a3a3 !important; }
.content-cell h2, .subcopy, .table th, .table td { border-color: #262626 !important; }
.subcopy p, .subcopy a, .footer p, .footer a { color: #a3a3a3 !important; }
.panel { background-color: #262212 !important; }
.panel p { color: #e5e5e5 !important; }
.button, .content-cell a.button { color: #0b0b0c !important; }
}
</style>
{!! $head ?? '' !!}
</head>
<body>

<table class="wrapper" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="center">
<table class="content" width="600" cellpadding="0" cellspacing="0" role="presentation">
{!! $header ?? '' !!}

<!-- Email Body -->
<tr>
<td class="body" width="100%" cellpadding="0" cellspacing="0">
<table class="inner-body" align="center" width="600" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="content-cell">
{!! Illuminate\Mail\Markdown::parse($slot) !!}

{!! $subcopy ?? '' !!}
</td>
</tr>
</table>
</td>
</tr>

{!! $footer ?? '' !!}
</table>
</td>
</tr>
</table>
</body>
</html>
