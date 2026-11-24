<?php

namespace App\Http\Middleware;

use App\Agent\Lookup;
use App\Agent\Markdown;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * On every page: Link headers (RFC 8288) pointing agents at the API
 * catalog, the OpenAPI description, the guide and the page's own Markdown;
 * and, when the client asks for Markdown (Accept: text/markdown, or a .md
 * path, see MarkdownPath), the page as Markdown instead of HTML.
 */
class AgentResponses
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if (! $request->isMethodSafe() || ! str_starts_with((string) $response->headers->get('Content-Type'), 'text/html')) {
            return $response;
        }

        $response->headers->set('Vary', trim($response->headers->get('Vary', '').', Accept', ', '));
        if (! $this->wantsMarkdown($request) || ! in_array($response->getStatusCode(), [200, 404], true)) {
            $response->headers->set('Link', $this->links($request), false);

            return $response;
        }

        $md = $response->getStatusCode() === 404 ? "# Not found\n\nNothing on Pegwatch at ".$request->url().'. Try '.url('/llms.txt')."\n" : $this->markdown($request, (string) $response->getContent());
        $headers = [
            'Content-Type' => 'text/markdown; charset=utf-8',
            'Vary' => 'Accept',
            // A rough count (about four characters a token), as Cloudflare's Markdown for Agents sends.
            'x-markdown-tokens' => (string) (int) ceil(mb_strlen($md) / 4),
            'Link' => '<'.$request->url().'>; rel="canonical"; type="text/html"',
        ];
        foreach (['X-Robots-Tag', 'Cache-Control'] as $keep) {
            if ($response->headers->has($keep)) {
                $headers[$keep] = $response->headers->get($keep);
            }
        }
        $out = response($md, $response->getStatusCode(), $headers);
        foreach ($response->headers->getCookies() as $cookie) {
            $out->headers->setCookie($cookie);
        }

        return $out;
    }

    private function wantsMarkdown(Request $request): bool
    {
        return $request->attributes->get(MarkdownPath::ATTRIBUTE, false)
            || $request->prefers(['text/html', 'text/markdown']) === 'text/markdown';
    }

    private function markdown(Request $request, string $html): string
    {
        $route = $request->route();
        $slug = (string) $route?->parameter('slug');

        return match ($route?->getName()) {
            'home' => Markdown::home(),
            'tenements.show' => ($d = Lookup::tenement($slug)) ? Markdown::tenement($d) : Markdown::fromHtml($html, $request->url()),
            'holders.show' => ($h = Lookup::holder($slug)) ? Markdown::holder($h) : Markdown::fromHtml($html, $request->url()),
            default => Markdown::fromHtml($html, $request->url()),
        };
    }

    private function links(Request $request): string
    {
        $md = $request->path() === '/' ? url('/index.md') : $request->url().'.md';

        return implode(', ', [
            '<'.$md.'>; rel="alternate"; type="text/markdown"',
            '<'.route('wellknown.api-catalog').'>; rel="api-catalog"',
            '<'.route('api.openapi').'>; rel="service-desc"; type="application/vnd.oai.openapi+json"',
            '<'.route('agents').'>; rel="service-doc"; type="text/html"',
            '<'.url('/llms.txt').'>; rel="describedby"; type="text/plain"',
        ]);
    }
}
