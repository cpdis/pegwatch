@php
    $lead = \App\Digest\WeeklyDigest::lead($c);
    $ha = fn ($n) => number_format((float) $n, 1).' ha';
    $type = fn ($e) => \App\Support\Format::sentence($e['tenement_type'] ?? '');
    $field = fn ($id) => \App\Support\Format::field($id);
    // Register text can hold Markdown's own characters; escape it inside links and tables.
    $md = fn (?string $t) => str_replace(['\\', '[', ']', '|', '*', '_'], ['\\\\', '\\[', '\\]', '\\|', '\\*', '\\_'], (string) $t);
    $ten = fn ($e) => '['.$md($e['fmt_tenid']).']('.url('/tenements/'.$e['slug']).')';
    $holders = fn (array $names) => implode('; ', array_map(fn ($n) => '['.$md($n).']('.url('/holders/'.\App\Support\Slug::make($n)).')', array_slice($names, 0, 2)))
        .(count($names) > 2 ? ' +'.(count($names) - 2) : '');
    $meta = fn (array $parts) => implode(' · ', array_filter($parts, fn ($p) => $p !== null && $p !== ''));
    $counts = array_filter($c['counts'] ?? []);
@endphp
@if ($c['stale'] ?? false)
**No new extract this week.** The department has not published a new extract of the register since {{ \App\Support\Format::awst($c['extract_at'] ?? null, false) }}, so there are no changes to report. We check every night.

@else
@if ($lead)
@php($e = $lead['item'])
@if ($lead['kind'] === 'freed')
**The week's biggest change: {{ $ten($e) }} ended{{ $e['reason'] ? ' ('.strtolower($e['reason']).')' : '' }}, and {{ $ha($e['uncovered_ha']) }} of it has no other tenement over it.** {{ $meta([$type($e), $holders($e['holders']), $field($e['fmt_tenid'])]) }}
@else
**The week's biggest change: {{ $holders($e['holders']) }} applied for {{ $ten($e) }}, {{ $ha($e['area_ha']) }}.** {{ $meta([$type($e), $field($e['fmt_tenid'])]) }}
@endif

@endif
**This week:** {{ $counts ? \App\Support\FeedGroups::countLine($counts) : 'no changes' }}

## Freed ground

Tenements that ended, by the area no other tenement covers.

@if ($c['freed'])
<x-mail::table>
| Tenement | | Uncovered |
| :-- | :-- | --: |
@foreach ($c['freed'] as $e)
| {{ $ten($e) }} | {{ $meta([$md($e['reason'] ?? null), $holders($e['holders']), $field($e['fmt_tenid'])]) }} | {{ $ha($e['uncovered_ha']) }} |
@endforeach
</x-mail::table>
@else
No tenement ended this week leaving a hectare or more with no other tenement over it.

@endif
## Biggest new applications

@if ($c['applications'])
<x-mail::table>
| Tenement | Applicant | Area |
| :-- | :-- | --: |
@foreach ($c['applications'] as $e)
| {{ $ten($e) }} | {{ $meta([$holders($e['holders']), $field($e['fmt_tenid'])]) }} | {{ $e['area_ha'] !== null ? $ha($e['area_ha']) : '' }} |
@endforeach
</x-mail::table>
@else
No new applications this week.

@endif
@if ($c['applicants'])
## Busiest applicants

@foreach ($c['applicants'] as $h)
- [{{ $h['name'] }}]({{ url('/holders/'.$h['slug']) }}): {{ $h['n'] }} {{ $h['n'] === 1 ? 'application' : 'applications' }}
@endforeach

@endif
@endif
## Upcoming compulsory releases

@if ($c['releases'])
<x-mail::table>
| Tenement | Releases (AWST) | Area |
| :-- | :-- | --: |
@foreach ($c['releases'] as $r)
| {{ $ten($r) }} | {{ $meta([$r['release_at'], $field($r['fmt_tenid'])]) }} | {{ $ha($r['area_ha']) }} |
@endforeach
</x-mail::table>
@else
None scheduled.
@endif
