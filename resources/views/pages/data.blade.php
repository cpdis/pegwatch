<?php

use App\Support\Format;
use App\Support\Freshness;
use App\Support\Register;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Data and disclaimer')] class extends Component {
    /** Plain names for the SLIP layers the nightly job reads, by layer code. */
    public const LAYERS = [
        'DMIRS-003' => 'Live and pending tenements',
        'DMIRS-026' => 'Dead tenements',
        'DMIRS-030' => 'Release pending (s65 surrender)',
        'DMIRS-031' => 'Amalgamation pending',
        'DMIRS-032' => 'Restoration pending',
        'DMIRS-023' => 'Section 19 closed land',
        'DMIRS-044' => 'Section 57(4) areas',
        'DMIRS-045' => 'Section 57(2aa) zones',
        'DMIRS-002' => 'File notation areas',
        'DMIRS-022' => 'General clearance areas',
    ];

    public function with(): array
    {
        return ['runs' => Register::recentRuns(30), 'fresh' => Freshness::current(), 'layerNames' => self::LAYERS];
    }
}; ?>

@php
    $at = fn (?string $ts, string $format = 'j M Y, g:ia') => $ts ? CarbonImmutable::parse($ts)->setTimezone('Australia/Perth')->format($format) : '—';
    $status = ['ok' => __('Checked'), 'baseline' => __('First copy'), 'failed' => __('Failed'), 'running' => __('Running')];
@endphp

<div class="max-w-3xl">
    <x-eyebrow>{{ __('About the data') }}</x-eyebrow>
    <flux:heading size="xl" level="1" class="mt-2">{{ __('Data and disclaimer') }}</flux:heading>
    <flux:text class="mt-3 text-base">
        {{ __('Pegwatch copies the WA mining tenement register from the department\'s open data every night, compares it with the night before and shows what changed. It is a screening aid, not advice.') }}
    </flux:text>

    @if ($fresh)
        <div class="mt-6 flex items-start gap-3 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700" data-test="data-freshness">
            <span @class(['mt-1.5 size-2.5 shrink-0 rounded-full', 'bg-amber-500' => $fresh->stale, 'bg-zinc-400' => ! $fresh->stale]) aria-hidden="true"></span>
            <div>
                <flux:heading>{{ __('Register extract of :when AWST', ['when' => $fresh->extract->format('j M Y, g:ia')]) }}</flux:heading>
                <flux:text class="mt-1">
                    @if ($fresh->stale)
                        {{ __('The department has not published a newer extract that Pegwatch has seen.') }}
                    @endif
                    {{ __('The department publishes a new extract most business days. Pegwatch checks every night.') }}
                    @if ($fresh->lastCheck)
                        {{ __('Last check: :when AWST.', ['when' => $fresh->lastCheck->format('j M, g:ia')]) }}
                    @endif
                </flux:text>
            </div>
        </div>
    @endif

    <flux:heading size="lg" level="2" class="mt-10">{{ __('The last :n nightly checks', ['n' => count($runs)]) }}</flux:heading>
    <flux:text class="mt-1">{{ __('Times are AWST. "Same extract" means the department had published nothing newer since the check before. Open a check to see each layer it read.') }}</flux:text>

    @if ($runs)
        <flux:table class="mt-3 max-sm:whitespace-normal!">
            <flux:table.columns>
                <flux:table.column>{{ __('Checked') }}</flux:table.column>
                <flux:table.column><span class="max-sm:hidden">{{ __('Live layer extract') }}</span><span class="sm:hidden">{{ __('Extract') }}</span></flux:table.column>
                <flux:table.column align="end">{{ __('Changes') }}</flux:table.column>
                <flux:table.column class="max-sm:hidden">{{ __('Result') }}</flux:table.column>
            </flux:table.columns>
            {{-- One tbody per check, so its row and its layer list share one Alpine scope. --}}
            @foreach ($runs as $run)
                <tbody x-data="{ open: false }" wire:key="run-{{ $run->id }}">
                    <flux:table.row data-test="run-row">
                        <flux:table.cell variant="strong">
                            <button type="button" class="flex cursor-pointer items-center gap-1.5 text-start" x-on:click="open = ! open" x-bind:aria-expanded="open">
                                <flux:icon.chevron-right variant="micro" class="shrink-0 text-zinc-400 transition-transform" x-bind:class="open && 'rotate-90'" />
                                <span><span class="whitespace-nowrap">{{ $at($run->finished_at ?? $run->started_at, 'D j M,') }}</span> <span class="whitespace-nowrap">{{ $at($run->finished_at ?? $run->started_at, 'g:ia') }}</span></span>
                            </button>
                        </flux:table.cell>
                        <flux:table.cell>
                            <span class="whitespace-nowrap">{{ $at($run->tenements_extract, 'j M, g:ia') }}</span>
                            @if ($run->stale)
                                <flux:badge size="sm" color="amber" class="max-sm:mt-1 max-sm:flex max-sm:w-fit sm:ms-1">{{ __('Same extract') }}</flux:badge>
                            @endif
                            {{-- Phones hide the Result column, so a failed check says so here. --}}
                            @if ($run->status === 'failed')
                                <flux:badge size="sm" color="red" class="mt-1 flex w-fit sm:hidden">{{ __('Failed') }}</flux:badge>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell align="end">{{ number_format($run->events) }}</flux:table.cell>
                        <flux:table.cell class="max-sm:hidden">{{ $status[$run->status] ?? Format::sentence($run->status) }}</flux:table.cell>
                    </flux:table.row>
                    <tr x-show="open" x-cloak>
                        <td colspan="4" class="pb-3">
                            @if ($run->layers)
                                <dl class="grid grid-cols-[1fr_auto_auto] gap-x-4 gap-y-1 rounded-md bg-zinc-50 p-3 text-xs dark:bg-zinc-800">
                                    @foreach ($run->layers as $l)
                                        <dt class="text-zinc-700 dark:text-zinc-200">{{ $layerNames[$l->layer_code] ?? Format::sentence(str_replace('_', ' ', $l->layer_key)) }} <span class="text-zinc-400">{{ $l->layer_code }}</span></dt>
                                        <dd class="text-end text-zinc-500 tabular-nums">{{ $l->max_extract ? $at($l->max_extract, 'j M Y') : __('no date') }}</dd>
                                        <dd class="text-end text-zinc-500 tabular-nums">{{ trans_choice('{1} 1 row|[0,*] :n rows', $l->fetched_rows, ['n' => number_format($l->fetched_rows)]) }}</dd>
                                    @endforeach
                                </dl>
                            @else
                                <flux:text class="text-xs">{{ __('No layer reads were recorded for this check.') }}</flux:text>
                            @endif
                        </td>
                    </tr>
                </tbody>
            @endforeach
        </flux:table>
    @else
        <flux:text class="mt-3">{{ __('No nightly check has run yet.') }}</flux:text>
    @endif

    <flux:heading size="lg" level="2" class="mt-10">{{ __('What Pegwatch cannot know') }}</flux:heading>
    <ul class="mt-3 space-y-3 text-sm text-zinc-600 dark:text-zinc-300">
        <li>
            <strong class="font-semibold text-zinc-900 dark:text-white">{{ __('Pegging on the ground.') }}</strong>
            {{ __('For prospecting licences and mining leases, priority can go to whoever marks out the ground first. No dataset shows pegs, so ground with no tenement on the register may already be pegged.') }}
        </li>
        <li>
            <strong class="font-semibold text-zinc-900 dark:text-white">{{ __('Same-day lodgements.') }}</strong>
            {{ __('Applications reach the open data a day or more after they are lodged, and the department does not publish an extract every day. What Pegwatch shows is as of the extract time above, never live.') }}
        </li>
        <li>
            <strong class="font-semibold text-zinc-900 dark:text-white">{{ __('Land that needs consent.') }}</strong>
            {{ __('Townsites, Commonwealth land, national parks and class A reserves, state forest and timber reserves, and Aboriginal reserves can need the Minister\'s or another agency\'s consent before anyone marks out or mines. Their maps are not in the department\'s open data, and the versions other agencies publish do not allow reuse in a paid product.') }}
        </li>
        <li>
            <strong class="font-semibold text-zinc-900 dark:text-white">{{ __('Private land.') }}</strong>
            {{ __('Land tenure, private land that needs the owner\'s consent, and old grants where the owner holds the minerals are not in the open data.') }}
        </li>
    </ul>

    <flux:heading size="lg" level="2" class="mt-10">{{ __('Disclaimer') }}</flux:heading>
    <div class="mt-3 space-y-3 text-sm text-zinc-600 dark:text-zinc-300" data-test="disclaimer">
        <p>{{ __('Pegwatch is a screening aid, not advice. It shows what the department\'s open data said at its latest extract. That data can be out of date or wrong, and it leaves things out (above).') }}</p>
        <p>{{ __('Where Pegwatch shows ground with no live or pending tenement over it on the register, that is all it shows. It is not a finding that anyone may peg that ground or apply for it.') }}</p>
        <p>
            {{ __('Before you peg, apply, buy or sell, confirm in') }}
            <flux:link href="{{ Format::TENGRAPH_URL }}" target="_blank" rel="noreferrer">TENGRAPH</flux:link>
            {{ __('and with the') }}
            <flux:link href="{{ Format::MINING_REGISTRAR_URL }}" target="_blank" rel="noreferrer">{{ __('Mining Registrar') }}</flux:link>{{ __(', and get your own advice. Pegwatch is not responsible for decisions made on what it shows.') }}
        </p>
        <p>{{ __('Holders who are people are shown by name only, as on the public register. Pegwatch never stores or shows their addresses.') }}</p>
    </div>

    <flux:heading size="lg" level="2" class="mt-10">{{ __('Data licence') }}</flux:heading>
    <flux:text class="mt-3">
        {{ __('Data © State of Western Australia (Department of Mines, Petroleum and Exploration), licensed under') }}
        <flux:link href="https://creativecommons.org/licenses/by/4.0/" target="_blank" rel="noreferrer">CC BY 4.0</flux:link>{{ __(', changes made. Pegwatch reshapes it: it compares nightly copies, computes areas and overlaps, and joins layers. The department does not endorse Pegwatch. Source:') }}
        <flux:link href="https://catalogue.data.wa.gov.au/dataset/mining-tenements-dmirs-003" target="_blank" rel="noreferrer">{{ __('Mining Tenements (DMIRS-003)') }}</flux:link>
        {{ __('and related layers on the') }}
        <flux:link href="https://public-services.slip.wa.gov.au/public/rest/services/SLIP_Public_Services/Industry_and_Mining/MapServer" target="_blank" rel="noreferrer">{{ __('SLIP Industry and Mining service') }}</flux:link>.
    </flux:text>
    <flux:text class="mt-3">
        {{ __('Town and locality names, and the WA land outline: Australian Bureau of Statistics,') }}
        <flux:link href="https://www.abs.gov.au/statistics/standards/australian-statistical-geography-standard-asgs-edition-3" target="_blank" rel="noreferrer">{{ __('ASGS Edition 3') }}</flux:link>{{ __(', CC BY 4.0.') }}
    </flux:text>
</div>
