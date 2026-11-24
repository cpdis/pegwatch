<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `/tenements/m-27-518.md` is that page as Markdown, and `/index.md` is the
 * home page's. Runs before routing: strips the suffix and marks the request,
 * and AgentResponses renders it. Real .md files (auth.md, SKILL.md) are
 * routes of their own and pass through.
 */
class MarkdownPath
{
    public const ATTRIBUTE = 'pegwatch.markdown';

    private const OWN_FILES = ['auth.md'];

    public function handle(Request $request, Closure $next): Response
    {
        $path = $request->path();
        if (! $request->isMethodSafe() || ! str_ends_with($path, '.md') || in_array($path, self::OWN_FILES, true)
            || str_starts_with($path, '.well-known/')) {
            return $next($request);
        }

        $page = substr($path, 0, -3);
        $page = $page === 'index' ? '/' : '/'.$page;
        $query = $request->getQueryString();
        $server = array_merge($request->server->all(), ['REQUEST_URI' => $request->getBaseUrl().$page.($query ? "?$query" : '')]);
        $rewritten = $request->duplicate(server: $server);
        $rewritten->attributes->set(self::ATTRIBUTE, true);
        app()->instance('request', $rewritten);

        return $next($rewritten);
    }
}
