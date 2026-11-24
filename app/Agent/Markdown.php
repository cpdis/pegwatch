<?php

namespace App\Agent;

use App\Support\Format;
use App\Support\PageMeta;
use App\Support\Register;
use App\Support\TenementSummary;
use DOMDocument;
use DOMXPath;
use League\HTMLToMarkdown\Converter\TableConverter;
use League\HTMLToMarkdown\HtmlConverter;

/**
 * Pages as Markdown, for agents (Accept: text/markdown, or a path ending
 * .md). The map, tenement and holder pages are written from Lookup's data,
 * since their HTML is mostly a map; every other page is its rendered
 * <main> converted, so a new page has a Markdown version with no work.
 */
final class Markdown
{
    public static function home(): string
    {
        $status = Lookup::status();
        $t = $status['totals'];
        $base = rtrim(url('/'), '/');
        $lines = [
            '# '.PageMeta::SITE_NAME.': WA mining tenement map',
            '',
            PageMeta::DEFAULT_DESCRIPTION,
            '',
            '- Live tenements: '.number_format($t['LIVE'] ?? 0),
            '- Pending applications: '.number_format($t['PENDING'] ?? 0),
            '- Last checked: '.($status['last_checked_at'] ? Format::awst($status['last_checked_at']).' AWST' : 'not yet').($status['stale'] ? ' (no newer register extract yet)' : ''),
            '',
            "Open the map at a place with `$base/#map={zoom}/{lat}/{lng}`. For agents: [guide]($base/agents.md), [MCP server]($base/mcp), [API]($base/api/openapi.json), [llms.txt]($base/llms.txt).",
        ];

        $activity = Lookup::activity([], 15)['items'];
        if ($activity) {
            $lines = [...$lines, '', '## Latest changes', '', '| When | Tenement | Change | Holders |', '|---|---|---|---|'];
            foreach ($activity as $e) {
                $lines[] = '| '.self::cell((string) ($e['at'] ?? '')).' | '.self::link($e['fmt_tenid'], $e['url'] ?? null).' | '
                    .self::cell($e['label'].($e['summary'] ? ': '.$e['summary'] : '')).' | '.self::cell(self::holders($e['holders'])).' |';
            }
            $lines[] = '';
            $lines[] = "More: [activity]($base/activity.md).";
        }

        $releases = Lookup::releases(null, 10);
        if ($releases) {
            $lines = [...$lines, '', '## Compulsory releases coming up', '', '| Release | Tenement | Area | Holders |', '|---|---|---|---|'];
            foreach ($releases as $r) {
                $lines[] = '| '.self::cell((string) $r['release_at']).' | '.self::link($r['fmt_tenid'], $r['url']).' | '
                    .self::cell($r['area_ha'].' ha').' | '.self::cell(self::holders($r['holders'])).' |';
            }
        }

        return implode("\n", $lines)."\n";
    }

    public static function tenement(array $d): string
    {
        $t = $d['tenement'];
        $lines = [
            '# '.$t['fmt_tenid'],
            '',
            $t['summary'],
            '',
            '- Type: '.($t['type'] ?? $t['type_code'] ?? 'unknown'),
            '- Status: '.$t['status'].($t['dead'] ? ' ('.Format::sentence($t['dead']['reason'] ?? '').', '.($t['dead']['ended_at'] ?? '?').')' : ''),
            '- Holders: '.self::holders($t['holders'], true),
        ];
        foreach (['applied_at' => 'Applied', 'granted_at' => 'Granted', 'ends_at' => $t['dead'] ? 'Ended' : 'Expires', 'holder_changed_at' => 'Holder last changed'] as $k => $label) {
            if (! empty($t[$k])) {
                $lines[] = "- $label: {$t[$k]}";
            }
        }
        if ($t['area_ha'] !== null) {
            $lines[] = '- Area: '.number_format((float) $t['area_ha'], 1).' ha'.($t['legal_area'] ? ' (register: '.Format::legalArea((float) $t['legal_area'], $t['area_unit']).')' : '');
        }
        if ($t['field']) {
            $lines[] = '- Mineral field: '.$t['field'];
        }
        if ($d['location']) {
            $lines[] = '- Nearest place: '.$d['location']['place'].' ('.$d['location']['km'].' km)';
        }
        if ($d['wait_to_grant']) {
            $w = $d['wait_to_grant'];
            $lines[] = "- Waiting: {$w['waited_months']} months so far; similar grants took a median of {$w['median_months']} months";
        }
        if ($d['uncovered_share'] !== null) {
            $lines[] = '- Ground no other tenement covers: '.round($d['uncovered_share'] * 100).'%';
        }
        $lines[] = '- Page: '.$t['url'];

        if ($d['releases']) {
            $lines = [...$lines, '', '## Scheduled releases', ''];
            foreach ($d['releases'] as $r) {
                $lines[] = "- {$r['rlesid']}: {$r['ha']} ha at {$r['release_at']}";
            }
            if ($t['release_calendar']) {
                $lines[] = '';
                $lines[] = 'Calendar: '.$t['release_calendar'];
            }
        }
        if ($d['overlaps']) {
            $lines = [...$lines, '', '## Live and pending tenements over it', '', '| Tenement | Status | Applied | Share | Holders |', '|---|---|---|---|---|'];
            foreach ($d['overlaps'] as $o) {
                $lines[] = '| '.self::link($o['fmt_tenid'], route('tenements.show', $o['slug'])).' | '.$o['status'].' | '.self::cell((string) $o['applied_at'])
                    .' | '.round($o['share'] * 100).'% | '.self::cell(self::holders($o['holders'])).' |';
            }
        }
        if ($d['restrictions']) {
            $lines = [...$lines, '', '## Land restrictions', ''];
            foreach ($d['restrictions'] as $r) {
                $lines[] = '- '.TenementSummary::restrictionText($r).': '.round($r['share'] * 100).'% of the tenement';
            }
        }
        if ($d['pending_actions']) {
            $lines = [...$lines, '', '## Pending actions', ''];
            foreach ($d['pending_actions'] as $a) {
                $lines[] = '- '.self::cell(implode(', ', array_filter(array_map(fn ($v) => is_scalar($v) ? (string) $v : null, $a))));
            }
        }
        if ($d['events']) {
            $lines = [...$lines, '', '## History', '', '| When | Change |', '|---|---|'];
            foreach ($d['events'] as $e) {
                $lines[] = '| '.self::cell((string) ($e['at'] ?? '')).' | '.self::cell($e['label'].($e['summary'] ? ': '.$e['summary'] : '')).' |';
            }
        }
        $lines = [...$lines, '', self::disclaimer()];

        return implode("\n", $lines)."\n";
    }

    public static function holder(array $h): string
    {
        $s = $h['summary'];
        $lines = [
            '# '.$h['holder']['name'],
            '',
            number_format($s['live']).' live and '.number_format($s['pending']).' pending tenements on the WA register, '
                .number_format((float) $s['area_ha']).' ha mapped; '.number_format($s['dead']).' dead on record; '
                .number_format($s['applied_12m']).' applications in the last 12 months.',
            '',
            '- '.($h['holder']['is_company'] ? 'Company' : 'Natural person (named as on the public register, nothing more)'),
            '- Page: '.$h['holder']['url'],
        ];
        if ($h['matches']) {
            $lines = [...$lines, '', '## Companies with matching names', '', 'A name match, not a corporate group: the register has no parent-company field.', ''];
            foreach ($h['matches']['entities'] as $e) {
                $lines[] = '- '.self::link($e['name'], route('holders.show', $e['slug'])).": {$e['live']} live, {$e['pending']} pending";
            }
        }
        if ($h['tenements']) {
            $lines = [...$lines, '', '## Live and pending tenements', '', '| Tenement | Type | Status | Applied | Expires | Area (ha) |', '|---|---|---|---|---|---|'];
            foreach ($h['tenements'] as $t) {
                $lines[] = '| '.self::link($t['fmt_tenid'], route('tenements.show', $t['slug'])).' | '.self::cell($t['type_label']).' | '.$t['status']
                    .' | '.self::cell((string) $t['applied_at']).' | '.self::cell((string) $t['ends_at']).' | '.($t['area_ha'] ?? '').' |';
            }
        }
        if ($h['dead']) {
            $lines = [...$lines, '', '## Dead tenements (latest '.count($h['dead']).')', ''];
            foreach (array_slice($h['dead'], 0, 50) as $t) {
                $lines[] = '- '.self::link($t['fmt_tenid'], route('tenements.show', $t['slug'])).': '.Format::sentence($t['reason'] ?? '').', '.($t['ended_at'] ?? '?');
            }
        }
        $lines = [...$lines, '', self::disclaimer()];

        return implode("\n", $lines)."\n";
    }

    /** Any page: its <main> (or <body>) as Markdown, under its title. */
    public static function fromHtml(string $html, string $url): string
    {
        $doc = new DOMDocument;
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        $xp = new DOMXPath($doc);
        $title = trim((string) $xp->query('//title')->item(0)?->textContent);
        // Flux renders the page body as <div data-flux-main>; the header, sidebar and footer sit outside it.
        $root = $xp->query('//*[@data-flux-main]')->item(0) ?? $xp->query('//main')->item(0) ?? $xp->query('//body')->item(0);
        if (! $root) {
            return "# $title\n\n$url\n";
        }
        // Chrome and controls: scripts, the map island, forms, dialogs, hidden and screen-reader-only bits.
        foreach (iterator_to_array($xp->query('.//script|.//style|.//template|.//noscript|.//svg|.//form|.//button|.//dialog|.//*[@aria-hidden="true"]|.//*[@hidden]|.//*[@*[name()="wire:ignore"]]|.//*[contains(concat(" ", normalize-space(@class), " "), " sr-only ")]', $root)) as $n) {
            $n->parentNode?->removeChild($n);
        }
        $inner = '';
        foreach ($root->childNodes as $child) {
            $inner .= $doc->saveHTML($child);
        }
        $converter = new HtmlConverter(['strip_tags' => true, 'remove_nodes' => 'head', 'hard_break' => true, 'header_style' => 'atx']);
        $converter->getEnvironment()->addConverter(new TableConverter);
        $md = $converter->convert($inner);
        // Inline elements split over lines in the HTML leave runs of spaces and newlines inside links and lines.
        $md = preg_replace_callback('/\[([^\]]*)\]\(/', fn ($m) => '['.trim(preg_replace('/\s+/', ' ', $m[1])).'](', $md);
        $md = implode("\n", array_map(fn ($l) => trim(preg_replace('/[ \t]{2,}/', ' ', $l)), explode("\n", $md)));
        $md = trim(preg_replace("/\n{3,}/", "\n\n", $md));
        $heading = preg_match('/^# /m', $md) ? '' : '# '.preg_replace('/ · '.preg_quote(PageMeta::SITE_NAME, '/').'$/', '', $title)."\n\n";

        return $heading.$md."\n\nSource: $url\n";
    }

    private static function disclaimer(): string
    {
        $run = Register::latestRun();

        return '_From the WA register'.($run?->tenements_extract ? ' extract of '.Format::awst($run->tenements_extract).' AWST' : '')
            .'. Data © State of Western Australia (DMPE), CC BY 4.0. A screening aid, not legal advice: confirm with the Mining Registrar._';
    }

    private static function holders(array $holders, bool $links = false): string
    {
        return implode('; ', array_map(fn ($h) => $links && isset($h['slug']) ? self::link($h['name'], route('holders.show', $h['slug'])) : $h['name'], $holders)) ?: 'none recorded';
    }

    private static function link(string $text, ?string $url): string
    {
        return $url ? '['.self::cell($text).']('.$url.')' : self::cell($text);
    }

    private static function cell(string $v): string
    {
        return str_replace(['|', "\n"], ['\|', ' '], $v);
    }
}
