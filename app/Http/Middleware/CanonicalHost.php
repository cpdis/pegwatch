<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * In production, a page read on any host but APP_URL's (such as the
 * *.laravel.cloud address) moves there for good, so search engines index one
 * site. Only GET and HEAD move: webhooks and the health check keep working on
 * either host.
 */
class CanonicalHost
{
    public function handle(Request $request, Closure $next): Response
    {
        $canonical = parse_url((string) config('app.url'), PHP_URL_HOST);
        if (app()->isProduction() && $canonical && $request->getHost() !== $canonical
            && $request->isMethodSafe() && ! $request->is('up')) {
            return redirect()->to(rtrim((string) config('app.url'), '/').$request->getRequestUri(), 301);
        }

        return $next($request);
    }
}
