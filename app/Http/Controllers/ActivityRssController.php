<?php

namespace App\Http\Controllers;

use App\Support\FeedFilter;
use App\Support\Format;
use App\Support\Register;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use XMLWriter;

/** RSS 2.0 of the activity feed, with the same query parameters as /activity. */
class ActivityRssController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $query = $request->query();
        $items = Register::feed(FeedFilter::from(is_array($query) ? $query : []), 50);
        $page = route('activity', $query);

        $x = new XMLWriter;
        $x->openMemory();
        $x->startDocument('1.0', 'UTF-8');
        $x->startElement('rss');
        $x->writeAttribute('version', '2.0');
        $x->writeAttribute('xmlns:atom', 'http://www.w3.org/2005/Atom');
        $x->startElement('channel');
        $x->writeElement('title', 'Pegwatch: changes on the WA tenement register');
        $x->writeElement('link', $page);
        $x->writeElement('description', 'Applications, grants, deaths, holder changes and releases on the Western Australian mining tenement register. A screening aid, not advice: confirm in TENGRAPH and with the Mining Registrar.');
        $x->writeElement('language', 'en-au');
        $x->writeElement('lastBuildDate', CarbonImmutable::now()->toRssString());
        $x->startElement('atom:link');
        $x->writeAttribute('href', $request->fullUrl());
        $x->writeAttribute('rel', 'self');
        $x->writeAttribute('type', 'application/rss+xml');
        $x->endElement();

        foreach ($items as $e) {
            $holders = implode('; ', array_column($e['holders'], 'name'));
            $x->startElement('item');
            $x->writeElement('title', $e['label'].': '.$e['fmt_tenid'].($holders !== '' ? ' ('.$holders.')' : ''));
            $link = $e['slug'] ? route('tenements.show', $e['slug']) : ($e['notice']['link'] ?? $page);
            $x->writeElement('link', $link);
            $x->writeElement('description', implode('. ', array_filter([
                $e['summary'], Format::field($e['fmt_tenid']), Register::FEED_SOURCES[$e['source']],
            ])).'.');
            $x->writeElement('category', $e['label']);
            $x->startElement('guid');
            $x->writeAttribute('isPermaLink', 'false');
            $x->text('pegwatch:'.$e['key']);
            $x->endElement();
            if ($e['at']) {
                // Feed dates are AWST wall-clock values.
                $x->writeElement('pubDate', CarbonImmutable::parse($e['at'], 'Australia/Perth')->toRssString());
            }
            $x->endElement();
        }

        $x->endElement();
        $x->endElement();
        $x->endDocument();

        return response($x->outputMemory(), 200, [
            'Content-Type' => 'application/rss+xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=900',
        ]);
    }
}
