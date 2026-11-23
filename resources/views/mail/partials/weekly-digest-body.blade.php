<x-mail::table>
| This week | |
| :-- | --: |
@foreach (\App\Digest\WeeklyDigest::COUNTED as $type)
| {{ $label($type) }} | {{ number_format($c['counts'][$type] ?? 0) }} |
@endforeach
</x-mail::table>

## Ground left open

@forelse ($c['freed'] as $e)
**[{{ $e['fmt_tenid'] }}]({{ url('/tenements/'.$e['slug']) }})**, {{ $e['tenement_type'] }}: {{ number_format($e['uncovered_ha'], 1) }} ha with no other tenement over it.{{ $e['reason'] ? ' '.$e['reason'].'.' : '' }}

@empty
No tenement died this week leaving a hectare or more uncovered.

@endforelse
## Biggest new applications

@forelse ($c['applications'] as $e)
**[{{ $e['fmt_tenid'] }}]({{ url('/tenements/'.$e['slug']) }})**, {{ $e['tenement_type'] }}: {{ $e['area_ha'] !== null ? number_format($e['area_ha'], 1).' ha' : '' }}@if ($e['holders']), {{ implode(', ', $e['holders']) }}@endif

@empty
No new applications this week.

@endforelse
@if ($c['applicants'])
## Busiest applicants

@foreach ($c['applicants'] as $h)
- [{{ $h['name'] }}]({{ url('/holders/'.$h['slug']) }}): {{ $h['n'] }} {{ $h['n'] === 1 ? 'application' : 'applications' }}
@endforeach

@endif
## Compulsory releases in the next 7 days

@forelse ($c['releases'] as $r)
- [{{ $r['fmt_tenid'] }}]({{ url('/tenements/'.$r['slug']) }}): {{ number_format($r['area_ha'], 1) }} ha, {{ $r['release_at'] }} AWST
@empty
None scheduled.
@endforelse
