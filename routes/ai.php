<?php

use App\Http\Middleware\RequireOAuthKeys;
use App\Mcp\Servers\PegwatchAccountServer;
use App\Mcp\Servers\PegwatchServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server\Http\Controllers\OAuthRegisterController;

// The MCP servers (Streamable HTTP), loaded by laravel/mcp with no session or
// cookies. Their card is /.well-known/mcp/server-card.json.
Mcp::web('mcp', PegwatchServer::class)->middleware('throttle:agents')->name('mcp');

// The same tools plus the person's own watches, for an agent the person has
// signed in (OAuth 2.1 with PKCE, Passport). With no token it answers 401 and
// a WWW-Authenticate header pointing at the protected-resource metadata.
Mcp::web('mcp/account', PegwatchAccountServer::class)->middleware(['throttle:agents', RequireOAuthKeys::class, 'auth:api'])->name('mcp.account');

// Discovery (RFC 8414 and RFC 9728) and dynamic client registration (RFC
// 7591). Registration is open to any agent app, so it is throttled.
Mcp::oauthRoutes(registration: false);
Route::post('oauth/register', OAuthRegisterController::class)->middleware('throttle:oauth-register')->name('mcp.oauth.register');
