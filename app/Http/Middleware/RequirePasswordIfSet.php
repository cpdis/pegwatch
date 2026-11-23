<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Http\Request;

/**
 * Laravel's password confirmation, for accounts that have a password. An
 * account that signs in by emailed link has none to confirm, so it passes.
 */
class RequirePasswordIfSet extends RequirePassword
{
    public function handle($request, Closure $next, $redirectToRoute = null, $passwordTimeoutSeconds = null)
    {
        if ($request instanceof Request && $request->user() && ! $request->user()->hasPassword()) {
            return $next($request);
        }

        return parent::handle($request, $next, $redirectToRoute, $passwordTimeoutSeconds);
    }
}
