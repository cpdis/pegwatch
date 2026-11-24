<?php

namespace App\Agent;

use App\Support\PageMeta;
use App\Support\Register;
use Illuminate\Http\Request;

/**
 * schema.org JSON-LD for the head: the site and its dataset on the map and
 * data pages, a Place for a tenement, an Organization for a company holder.
 * Natural persons get none.
 */
final class StructuredData
{
    public static function for(Request $request, PageMeta $meta): ?array
    {
        $route = $request->route();

        return match ($route?->getName()) {
            'home' => ['@context' => 'https://schema.org', '@graph' => [self::website(), self::dataset()]],
            'data' => ['@context' => 'https://schema.org'] + self::dataset(),
            // The page has 404ed already if there is no such tenement.
            'tenements.show' => [
                '@context' => 'https://schema.org', '@type' => 'Place',
                'name' => $meta->shareTitle, 'description' => $meta->description, 'url' => $meta->canonical,
                'containedInPlace' => ['@type' => 'AdministrativeArea', 'name' => 'Western Australia'],
                'isPartOf' => ['@id' => route('home').'#dataset'],
            ],
            'holders.show' => ($h = Register::holderBrief((string) $route->parameter('slug'))) && $h->is_company ? [
                '@context' => 'https://schema.org', '@type' => 'Organization',
                'name' => $h->name, 'description' => $meta->description, 'url' => $meta->canonical,
            ] : null,
            default => null,
        };
    }

    private static function website(): array
    {
        return [
            '@type' => 'WebSite', '@id' => route('home').'#website',
            'name' => PageMeta::SITE_NAME, 'url' => route('home'), 'description' => PageMeta::DEFAULT_DESCRIPTION, 'inLanguage' => 'en-AU',
        ];
    }

    private static function dataset(): array
    {
        $checked = Register::lastCheck();

        return array_filter([
            '@type' => 'Dataset', '@id' => route('home').'#dataset',
            'name' => 'Western Australian mining tenements, checked nightly',
            'description' => 'Every live, pending and dead mining tenement in Western Australia from the state register, with a record of each change Pegwatch has seen: applications, grants, deaths, holder and area changes, and compulsory releases.',
            'url' => route('data'),
            'license' => 'https://creativecommons.org/licenses/by/4.0/',
            'isAccessibleForFree' => true,
            'creator' => ['@type' => 'Organization', 'name' => 'Department of Mines, Petroleum and Exploration, Government of Western Australia'],
            'publisher' => ['@type' => 'Organization', 'name' => PageMeta::SITE_NAME, 'url' => route('home')],
            'spatialCoverage' => ['@type' => 'Place', 'name' => 'Western Australia'],
            'dateModified' => $checked ? AgentJson::date((string) $checked) : null,
            'distribution' => [
                ['@type' => 'DataDownload', 'name' => 'JSON API', 'encodingFormat' => 'application/json', 'contentUrl' => route('api.status')],
                ['@type' => 'DataDownload', 'name' => 'Activity feed', 'encodingFormat' => 'application/rss+xml', 'contentUrl' => route('activity.rss')],
            ],
        ]);
    }
}
