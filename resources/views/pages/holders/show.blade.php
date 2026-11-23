<?php

use App\Support\Format;
use App\Support\Register;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component {
    /** Table groups, shown first and in this order, then everything else. */
    public const GROUPS = ['past_expiry' => 'Past expiry, still live', 'expiring' => 'Expiring in 12 months'];

    public const SORTS = ['tenement', 'applied', 'expires', 'area'];

    /** Rows before "Show all". */
    public const PAGE = 50;

    public string $slug;

    public string $name = '';

    public bool $isCompany = true;

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $type = '';

    #[Url(except: '')]
    public string $sort = '';

    #[Url(except: 'asc')]
    public string $dir = 'asc';

    public bool $showAll = false;

    /** The first request's data, so the page load queries once; later requests are table-only. */
    protected ?array $data = null;

    public function mount(string $slug): void
    {
        abort_unless($this->data = Register::holder($slug), 404);
        $this->slug = $slug;
        $this->name = $this->data['holder']['name'];
        $this->isCompany = $this->data['holder']['is_company'];
    }

    #[Computed]
    public function holder(): array
    {
        return $this->data ?? Register::holder($this->slug);
    }

    /**
     * The table: rows filtered by status and type, split into the
     * past-expiry and expiring groups and the rest, each sorted, and capped
     * at PAGE rows until "Show all".
     */
    #[Computed]
    public function table(): array
    {
        $all = $this->data['tenements'] ?? Register::holderTenements($this->slug);
        $rows = array_filter($all, fn ($r) => ($this->status === '' || $r['status'] === $this->status)
            && ($this->type === '' || ($r['type_code'] ?? '?') === $this->type));

        $left = $this->showAll ? PHP_INT_MAX : self::PAGE;
        $groups = [];
        foreach ([...array_keys(self::GROUPS), null] as $g) {
            $in = array_values(array_filter($rows, fn ($r) => $r['group'] === $g));
            usort($in, fn ($a, $b) => $this->compare($a, $b));
            $shown = array_slice($in, 0, $left);
            $left -= count($shown);
            if ($shown) {
                $groups[] = ['key' => $g ?? 'other', 'label' => self::GROUPS[$g] ?? null, 'rows' => $shown];
            }
        }
        // The rest only needs a heading when a group sits above it.
        if (count($groups) > 1 && $groups[count($groups) - 1]['key'] === 'other') {
            $groups[count($groups) - 1]['label'] = 'All other tenements';
        }

        $types = [];
        foreach ($all as $r) {
            $types[$r['type_code'] ?? '?'] ??= ['label' => $r['type_label'], 'n' => 0];
            $types[$r['type_code'] ?? '?']['n']++;
        }
        uasort($types, fn ($a, $b) => $b['n'] <=> $a['n']);

        return ['groups' => $groups, 'matching' => count($rows), 'shown' => array_sum(array_map(fn ($g) => count($g['rows']), $groups)),
            'total' => count($all), 'types' => $types];
    }

    /** Row order: the chosen column, else soonest expiry in a group and newest application in the rest. */
    private function compare(array $a, array $b): int
    {
        $id = fn ($r) => sscanf(preg_replace('~^([A-Z]+)\s*(\d+)/(\d+).*$~i', '$1 $2 $3', $r['fmt_tenid']), '%s %d %d');
        $by = match ($this->sort) {
            'tenement' => [$id($a), $id($b)],
            'applied' => [$a['applied_at'] ?? '', $b['applied_at'] ?? ''],
            'expires' => [$a['ends_at'] ?? '9999', $b['ends_at'] ?? '9999'],
            'area' => [$a['area_ha'] ?? -1, $b['area_ha'] ?? -1],
            default => $a['group'] ? [$a['ends_at'], $b['ends_at']] : [$b['applied_at'] ?? '', $a['applied_at'] ?? ''],
        };

        return ($this->sort !== '' && $this->dir === 'desc' ? -1 : 1) * ($by[0] <=> $by[1]);
    }

    /**
     * The expiry cell: text and whether it needs attention (past expiry but
     * still live, or under six months to go). Pending ground has no expiry.
     *
     * @return array{0: string, 1: bool}
     */
    public function expiry(array $t, bool $short = false): array
    {
        $date = Format::localDate($t['ends_at']);
        if ($t['status'] !== 'LIVE' || $date === '') {
            return [$short ? __('Applied :date', ['date' => Format::localDate($t['applied_at'])]) : '', false];
        }
        if ($t['group'] === 'past_expiry') {
            return [$short ? __('Past expiry (:date)', ['date' => $date]) : __('Past expiry (:date), still live', ['date' => $date]), true];
        }
        $soon = $t['group'] === 'expiring' && now('Australia/Perth')->addMonths(6)->toDateString() > substr($t['ends_at'], 0, 10);

        return [$short ? __('Expires :date', ['date' => $date]) : $date.' · '.Format::relative($t['ends_at']), $soon];
    }

    public function sortBy(string $key): void
    {
        if (! in_array($key, self::SORTS, true)) {
            return;
        }
        $this->dir = $this->sort === $key && $this->dir === 'asc' ? 'desc' : 'asc';
        $this->sort = $key;
    }

    public function render()
    {
        // Individuals stay out of search engines (H6); the meta tag is pushed from the view.
        return $this->view()->title($this->name)
            ->response(fn ($response) => $this->isCompany ? $response : $response->header('X-Robots-Tag', 'noindex, nofollow'));
    }
}; ?>

<div>
    @unless ($isCompany)
        @push('head')
            <meta name="robots" content="noindex, nofollow">
        @endpush
    @endunless
    @php
        $data = $this->holder;
        $s = $data['summary'];
        $months = $data['activity_by_month'];
        $chart = array_map(fn ($m) => ['date' => $m['month'].'-01', 'applications' => $m['applications'], 'deaths' => $m['deaths']], $months);
        $quiet = array_sum(array_column($months, 'applications')) + array_sum(array_column($months, 'deaths')) === 0;
        $deadSince = $data['dead_since'] && $data['dead_since'] > $months[0]['month'].'-01' ? $data['dead_since'] : null;
        $matches = $data['matches'];
    @endphp

    <x-eyebrow>{{ $isCompany ? __('Holder · Company') : __('Holder · Individual') }}</x-eyebrow>
    <div class="mt-1 flex flex-wrap items-start justify-between gap-3">
        <flux:heading size="xl" level="1" class="!text-3xl font-bold tracking-tight">{{ $name }}</flux:heading>
        <div class="flex flex-wrap items-center gap-2">
            @if (Route::has('watch.start'))
                <flux:button variant="primary" size="sm" icon="bell-alert" :href="route('watch.start', ['holder' => $slug])" wire:navigate>
                    {{ __('Watch this holder') }}
                </flux:button>
            @endif
            @if ($isCompany)
                <x-pro-lock ref="export">{{ __('Export CSV') }}</x-pro-lock>
            @endif
        </div>
    </div>
    <flux:text class="mt-1 max-w-3xl">
        @if ($s['live'] + $s['pending'] === 0)
            {{ __('Nothing live or pending on the register.') }}
        @else
            {{ number_format($s['live']) }} live, {{ number_format($s['pending']) }} pending, {{ number_format($s['area_ha']) }} ha mapped.
        @endif
        {{ trans_choice('{0} No dead tenements on record.|{1} 1 dead tenement on record.|[2,*] :n dead tenements on record.', $s['dead'], ['n' => number_format($s['dead'])]) }}
        {{ trans_choice('{0} No applications in the last 12 months.|{1} 1 application in the last 12 months.|[2,*] :n applications in the last 12 months.', $s['applied_12m'], ['n' => number_format($s['applied_12m'])]) }}
        @unless ($isCompany) {{ __('Shown by name only, as on the public register.') }} @endunless
    </flux:text>
    @if ($matches)
        <flux:text class="mt-1 text-sm">
            <flux:link :href="route('holders.matching', $matches['stem'])" wire:navigate>{{ __('One of :n entities matching “:name”', ['n' => count($matches['entities']), 'name' => $matches['label']]) }}</flux:link>
            · {{ __(':n distinct tenements between them', ['n' => number_format($matches['tenements'])]) }} · {{ __('a name match, not a corporate group') }}
        </flux:text>
    @endif
    @if (Route::has('watch.start'))
        <flux:text class="mt-1 text-xs">{{ __('Watching one holder is free.') }}</flux:text>
    @endif

    {{-- Portfolio strip: what they hold by type, and what expires soon. --}}
    @if ($data['by_type'])
        <div class="mt-5 grid grid-cols-2 gap-2 sm:flex sm:flex-wrap" data-portfolio-strip>
            @foreach ($data['by_type'] as $t)
                <div class="rounded-lg border border-zinc-200 px-3 py-2 sm:min-w-40 dark:border-zinc-700">
                    <div class="truncate text-xs text-zinc-500 dark:text-zinc-400">{{ $t['label'] }}</div>
                    <div class="text-xl font-semibold tabular-nums">{{ number_format($t['live'] + $t['pending']) }}</div>
                    <div class="text-xs text-zinc-500 tabular-nums dark:text-zinc-400">
                        {{ number_format($t['live']) }} {{ __('live') }} · {{ number_format($t['pending']) }} {{ __('pending') }}<br>{{ number_format($t['area_ha']) }} ha
                    </div>
                </div>
            @endforeach
            <div class="rounded-lg border px-3 py-2 sm:min-w-40 {{ $s['expiring_90d'] ? 'border-amber-300 bg-amber-50 dark:border-amber-700 dark:bg-amber-950' : 'border-zinc-200 dark:border-zinc-700' }}">
                <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Expiring in 90 days') }}</div>
                <div class="text-xl font-semibold tabular-nums">{{ number_format($s['expiring_90d']) }}</div>
                <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ trans_choice('live tenement|live tenements', $s['expiring_90d']) }}</div>
            </div>
        </div>
    @endif

    <div class="mt-5 grid gap-6 md:grid-cols-5">
        <div class="md:col-span-3">
            <div wire:ignore x-data="tenementMap(@js(['bounds' => $data['bbox'], 'focus' => ['features' => $data['focus']]]))" class="h-[420px] w-full overflow-hidden rounded-lg max-sm:h-[300px]">
                <div x-ref="map" class="h-full w-full"></div>
            </div>
            @if ($data['focus_dead'] && $data['focus']['features'])
                <flux:text class="mt-1 text-xs">{{ __('Nothing live or pending: the map shows their latest :n dead tenements.', ['n' => number_format(count($data['focus']['features']))]) }}</flux:text>
            @endif
        </div>
        <div class="md:col-span-2">
            <flux:heading>{{ __('Applications and deaths per month (24 months)') }}</flux:heading>
            @if ($quiet)
                <flux:text>{{ __('No applications or deaths on record in the last 24 months.') }}</flux:text>
            @else
                <flux:chart :value="$chart" class="mt-2 aspect-[2/1]">
                    <flux:chart.svg>
                        <flux:chart.group>
                            <flux:chart.bar field="applications" class="text-zinc-800 dark:text-zinc-200" />
                            <flux:chart.bar field="deaths" class="text-zinc-400 dark:text-zinc-500" />
                        </flux:chart.group>
                        <flux:chart.axis axis="x" field="date" :format="['month' => 'short', 'year' => '2-digit']">
                            <flux:chart.axis.tick />
                            <flux:chart.axis.line />
                        </flux:chart.axis>
                        <flux:chart.axis axis="y" tick-count="3">
                            <flux:chart.axis.grid />
                            <flux:chart.axis.tick />
                        </flux:chart.axis>
                    </flux:chart.svg>
                    <flux:chart.tooltip>
                        <flux:chart.tooltip.heading field="date" :format="['month' => 'long', 'year' => 'numeric']" />
                        <flux:chart.tooltip.value field="applications" :label="__('Applications')" />
                        <flux:chart.tooltip.value field="deaths" :label="__('Deaths')" />
                    </flux:chart.tooltip>
                </flux:chart>
                <div class="flex gap-4 text-xs text-zinc-500 dark:text-zinc-400">
                    <span class="flex items-center gap-1.5"><span class="size-2.5 rounded-sm bg-zinc-800 dark:bg-zinc-200"></span>{{ __('Applications') }}</span>
                    <span class="flex items-center gap-1.5"><span class="size-2.5 rounded-sm bg-zinc-400 dark:bg-zinc-500"></span>{{ __('Deaths') }}</span>
                    @if ($deadSince)<span>{{ __('Deaths on record from :date.', ['date' => Format::localDate($deadSince)]) }}</span>@endif
                </div>
            @endif
            <flux:heading class="mt-6">{{ __('Recent changes') }}</flux:heading>
            <x-event-list :events="$data['events']" compact context="holder" :subject="$name" />
        </div>
    </div>

    @island(name: 'table')
        @php($table = $this->table)
        @if ($table['total'] > 0)
            <div class="mt-8 flex flex-wrap items-end justify-between gap-3" data-holder-table>
                <flux:heading size="lg">{{ __('Live and pending tenements') }} ({{ number_format($table['total']) }})</flux:heading>
                <div class="flex flex-wrap items-center gap-2">
                    <flux:radio.group wire:model.live="status" variant="segmented" size="sm" :aria-label="__('Status')">
                        <flux:radio value="" :label="__('All')" />
                        <flux:radio value="LIVE" :label="__('Live')" />
                        <flux:radio value="PENDING" :label="__('Pending')" />
                    </flux:radio.group>
                    @if (count($table['types']) > 1)
                        <flux:select wire:model.live="type" size="sm" class="!w-auto" :aria-label="__('Type')">
                            <flux:select.option value="">{{ __('All types') }}</flux:select.option>
                            @foreach ($table['types'] as $code => $t)
                                <flux:select.option :value="$code">{{ $t['label'] }} ({{ $t['n'] }})</flux:select.option>
                            @endforeach
                        </flux:select>
                    @endif
                </div>
            </div>

            @if ($table['matching'] === 0)
                <flux:text class="mt-3">{{ __('None match these filters.') }}</flux:text>
            @else
                <flux:table class="mt-2 tabular-nums" container:class="max-sm:hidden">
                    <flux:table.columns>
                        @foreach (['tenement' => __('Tenement'), 'type' => __('Type'), 'status' => __('Status'), 'applied' => __('Applied'), 'expires' => __('Expires'), 'area' => 'ha'] as $col => $label)
                            @if (in_array($col, $this::SORTS, true))
                                <flux:table.column sortable :sorted="$sort === $col" :direction="$dir" wire:click="sortBy('{{ $col }}')" :align="$col === 'area' ? 'end' : 'start'">{{ $label }}</flux:table.column>
                            @else
                                <flux:table.column>{{ $label }}</flux:table.column>
                            @endif
                        @endforeach
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($table['groups'] as $group)
                            @if ($group['label'])
                                <flux:table.row :key="'g-'.$group['key']">
                                    <flux:table.cell colspan="6" class="!pt-5 text-xs font-semibold tracking-wide text-zinc-800 uppercase dark:text-white">{{ $group['label'] }}</flux:table.cell>
                                </flux:table.row>
                            @endif
                            @foreach ($group['rows'] as $t)
                                <flux:table.row :key="$t['slug']">
                                    <flux:table.cell variant="strong"><flux:link :href="route('tenements.show', $t['slug'])" wire:navigate>{{ $t['fmt_tenid'] }}</flux:link></flux:table.cell>
                                    <flux:table.cell>{{ $t['type_label'] }}@if ($t['field'])<span class="text-zinc-400"> · {{ $t['field'] }}</span>@endif</flux:table.cell>
                                    <flux:table.cell><x-status-badge :status="$t['status'] ?? 'dead'" :label="$t['status'] ? null : __('Unknown')" /></flux:table.cell>
                                    <flux:table.cell>{{ Format::localDate($t['applied_at']) }}</flux:table.cell>
                                    @php([$expiry, $warn] = $this->expiry($t))
                                    <flux:table.cell @class(['!text-amber-700 dark:!text-amber-400' => $warn])>{{ $expiry }}</flux:table.cell>
                                    <flux:table.cell align="end">{{ $t['area_ha'] !== null ? number_format($t['area_ha'], $t['area_ha'] < 10 ? 1 : 0) : '' }}</flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        @endforeach
                    </flux:table.rows>
                </flux:table>

                {{-- Phones: two-line rows instead of a clipped table. --}}
                <ul class="mt-2 divide-y divide-zinc-200 sm:hidden dark:divide-zinc-700" data-phone-rows>
                    @foreach ($table['groups'] as $group)
                        @if ($group['label'])
                            <li class="pt-4 pb-1 text-xs font-semibold tracking-wide uppercase">{{ $group['label'] }}</li>
                        @endif
                        @foreach ($group['rows'] as $t)
                            <li wire:key="m-{{ $t['slug'] }}" class="py-2 text-sm tabular-nums">
                                <div class="flex items-center gap-2">
                                    <flux:link :href="route('tenements.show', $t['slug'])" class="font-medium" wire:navigate>{{ $t['fmt_tenid'] }}</flux:link>
                                    <x-status-badge :status="$t['status'] ?? 'dead'" :label="$t['status'] ? null : __('Unknown')" />
                                    <span class="ms-auto text-zinc-500 dark:text-zinc-400">{{ $t['area_ha'] !== null ? number_format($t['area_ha'], $t['area_ha'] < 10 ? 1 : 0).' ha' : '' }}</span>
                                </div>
                                <div class="mt-0.5 truncate text-xs text-zinc-500 dark:text-zinc-400">
                                    {{ $t['type_label'] }}@if ($t['field']) · {{ $t['field'] }}@endif
                                    @php([$expiry, $warn] = $this->expiry($t, short: true))
                                    · <span @class(['text-amber-700 dark:text-amber-400' => $warn])>{{ $expiry }}</span>
                                </div>
                            </li>
                        @endforeach
                    @endforeach
                </ul>

                @if ($table['matching'] > $table['shown'])
                    <flux:button size="sm" variant="subtle" class="mt-2" wire:click="$set('showAll', true)">{{ __('Show all') }} {{ number_format($table['matching']) }}</flux:button>
                @endif
            @endif
        @endif
    @endisland

    @if (count($data['dead']))
        <flux:heading class="mt-8">{{ __('Dead tenements on record') }} ({{ number_format($s['dead']) }})</flux:heading>
        @if ($s['dead'] > count($data['dead']))
            <flux:text class="text-xs">{{ __('The latest :n shown.', ['n' => count($data['dead'])]) }}</flux:text>
        @endif
        <ul class="mt-1 text-sm sm:columns-2 md:columns-3">
            @foreach ($data['dead'] as $d)
                <li>
                    <flux:link :href="route('tenements.show', $d['slug'])" wire:navigate>{{ $d['fmt_tenid'] }}</flux:link>
                    <span class="text-zinc-500">{{ Format::lower($d['reason']) }} {{ Format::localDate($d['ended_at']) }}</span>
                </li>
            @endforeach
        </ul>
    @endif
</div>
