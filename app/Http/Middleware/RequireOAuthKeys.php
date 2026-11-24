<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Passport\Passport;
use Symfony\Component\HttpFoundation\Response;

/**
 * Account access for agents needs Passport's signing keys
 * (PASSPORT_PRIVATE_KEY and PASSPORT_PUBLIC_KEY). Until they are set, say
 * so plainly instead of failing inside the token guard.
 */
class RequireOAuthKeys
{
    public function handle(Request $request, Closure $next): Response
    {
        $configured = config('passport.public_key') || is_file(Passport::keyPath('oauth-public.key'));

        return $configured ? $next($request) : response()->json([
            'message' => 'Signing in agents is not set up on this server yet. The public tools are at '.url('/mcp').'.',
        ], 503);
    }
}
