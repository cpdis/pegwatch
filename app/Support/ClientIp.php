<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * The visitor's address for rate limits. Laravel Cloud runs behind Cloudflare,
 * so Request::ip() is a Cloudflare edge shared by many visitors; Cloudflare
 * puts the real address in CF-Connecting-IP.
 */
final class ClientIp
{
    public static function of(?Request $request = null): string
    {
        $request ??= request();

        return (string) ($request->header('CF-Connecting-IP') ?: $request->ip());
    }
}
