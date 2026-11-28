@props(['url'])
{{-- The site's top bar: the Peg P mark (a hosted PNG; mail clients drop SVG), the name, and the hi-vis rule. --}}
<tr>
<td class="header">
<table width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="header-cell">
<a href="{{ $url }}" class="brand" target="_blank" rel="noopener"><img src="{{ asset('mail/logo.png') }}" class="logo" width="28" height="28" alt="" /><span class="brand-name">{{ trim($slot) }}</span></a>
</td>
<td class="header-cell header-tag" align="right">WA tenement register</td>
</tr>
</table>
</td>
</tr>
