<?php

namespace App\Agent;

use App\Http\Controllers\DiscoveryController;
use App\Support\Register;
use App\Support\Slug;
use Illuminate\Routing\Route as RouteDef;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * The sitemaps (sitemaps.org): an index, the site's own pages, every live and
 * pending tenement, tenements dead in the last two years, company holders and
 * digest issues. Natural persons' holder pages are noindex and left out.
 * Rebuilt once per nightly run.
 */
final class Sitemap
{
    /** Under the protocol's 50,000-URL limit per file. */
    public const PER_FILE = 40000;

    public static function index(): string
    {
        $parts = ['pages'];
        foreach (['tenements', 'dead', 'holders'] as $kind) {
            $n = (int) ceil(self::count($kind) / self::PER_FILE);
            for ($i = 1; $i <= $n; $i++) {
                $parts[] = "$kind-$i";
            }
        }
        if (DB::table('digest_issues')->exists()) {
            $parts[] = 'digest';
        }
        $lastmod = self::lastmod();

        return self::cached('index', fn () => '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n"
            .implode('', array_map(fn ($p) => '  <sitemap><loc>'.e(route('sitemap.part', $p)).'</loc>'
                .($lastmod ? "<lastmod>$lastmod</lastmod>" : '')."</sitemap>\n", $parts))
            .'</sitemapindex>'."\n");
    }

    /** One file of the index ("pages", "tenements-1", "dead-1", "holders-1", "digest"), or null. */
    public static function part(string $part): ?string
    {
        if (! preg_match('/^(pages|digest|tenements|dead|holders)(?:-([1-9][0-9]{0,2}))?$/', $part, $m)) {
            return null;
        }
        $kind = $m[1];
        $page = isset($m[2]) ? (int) $m[2] : null;
        if (in_array($kind, ['pages', 'digest'], true) !== ($page === null)) {
            return null;
        }
        if ($page !== null && $page > max(1, (int) ceil(self::count($kind) / self::PER_FILE))) {
            return null;
        }

        return self::cached($part, fn () => self::urlset(match ($kind) {
            'pages' => self::pages(),
            'digest' => array_map(fn ($w) => route('digest.issue', $w), DB::table('digest_issues')->orderByDesc('week')->pluck('week')->all()),
            default => array_map(
                fn ($slug) => route($kind === 'holders' ? 'holders.show' : 'tenements.show', $slug),
                self::slugs($kind, ($page - 1) * self::PER_FILE),
            ),
        }));
    }

    /**
     * Public pages without parameters: every GET route that is named, takes
     * no parameters, needs no sign-in and isn't private (robots.txt) or a file.
     */
    private static function pages(): array
    {
        $urls = [];
        foreach (Route::getRoutes() as $r) {
            /** @var RouteDef $r */
            $uri = '/'.ltrim($r->uri(), '/');
            $middleware = $r->gatherMiddleware();
            if (! in_array('GET', $r->methods(), true) || ! $r->getName() || str_contains($uri, '{')
                || str_contains($uri, '.') || str_starts_with($uri, '/api') || str_starts_with($uri, '/mcp')
                || ! in_array('web', $middleware, true)
                || array_filter($middleware, fn ($m) => is_string($m) && (str_starts_with($m, 'auth') || $m === 'guest' || $m === 'verified'))
                || array_filter(DiscoveryController::PRIVATE_PATHS, fn ($p) => str_starts_with($uri, rtrim($p, '/')))) {
                continue;
            }
            $urls[$uri] = url($uri);
        }
        ksort($urls);

        return array_values($urls);
    }

    private static function count(string $kind): int
    {
        return (int) self::remember("count:$kind", fn () => match ($kind) {
            'tenements' => DB::scalar('select count(*) from tenements'),
            'dead' => DB::scalar(self::deadSql('count(*)')),
            'holders' => DB::scalar('select count(*) from ('.self::holdersSql().') h'),
            default => 0,
        });
    }

    /** @return list<string> */
    private static function slugs(string $kind, int $offset): array
    {
        $rows = match ($kind) {
            'tenements' => DB::select('select pw_slug(fmt_tenid) as slug from tenements order by fmt_tenid limit ? offset ?', [self::PER_FILE, $offset]),
            'dead' => DB::select(self::deadSql('slug').' order by slug limit ? offset ?', [self::PER_FILE, $offset]),
            'holders' => DB::select(self::holdersSql().' order by slug limit ? offset ?', [self::PER_FILE, $offset]),
        };

        return array_values(array_filter(array_map(fn ($r) => $r->slug, $rows), fn ($s) => $s !== '' && Slug::make($s) === $s));
    }

    /** Distinct tenements dead in the last two years whose ID is not live again. */
    private static function deadSql(string $select): string
    {
        return "select $select from (select distinct pw_slug(d.fmt_tenid) as slug from dead_tenements d
                  where d.ended_at >= now() - interval '2 years'
                  and not exists (select 1 from tenements t where pw_slug(t.fmt_tenid) = pw_slug(d.fmt_tenid))) x";
    }

    /** Holders on live or pending tenements whom every record marks as a company. */
    private static function holdersSql(): string
    {
        return 'select pw_slug(h.name) as slug from tenements t
                cross join lateral unnest(t.holders, t.holder_is_company) as h(name, is_company)
                group by 1 having bool_and(coalesce(h.is_company, false))';
    }

    private static function urlset(array $urls): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n"
            .implode('', array_map(fn ($u) => '  <url><loc>'.e($u)."</loc></url>\n", $urls))
            .'</urlset>'."\n";
    }

    private static function lastmod(): ?string
    {
        $run = Register::latestRun();

        return $run?->finished_at ? AgentJson::date((string) $run->finished_at) : null;
    }

    private static function cached(string $key, \Closure $build): string
    {
        return self::remember("xml:$key", $build);
    }

    private static function remember(string $key, \Closure $build): mixed
    {
        return Cache::remember('sitemap:'.(Register::latestRun()?->id ?? 0).':'.$key, now()->addDay(), $build);
    }
}
