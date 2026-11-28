<?php

use App\Http\Controllers\OAuthMetadataController;
use App\Http\Middleware\RequireOAuthKeys;
use App\Mcp\Servers\PegwatchAccountServer;
use App\Mcp\Servers\PegwatchServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server\Http\Controllers\OAuthRegisterController;
use Laravel\Mcp\Server\Registrar;

// The MCP servers (Streamable HTTP), loaded by laravel/mcp with no session or
// cookies. Their card is /.well-known/mcp/server-card.json.
Mcp::web('mcp', PegwatchServer::class)->middleware('throttle:agents')->name('mcp');

// The same tools plus the person's own watches, for an agent the person has
// signed in (OAuth 2.1 with PKCE, Passport). With no token it answers 401 and
// a WWW-Authenticate header pointing at the protected-resource metadata.
Mcp::web('mcp/account', PegwatchAccountServer::class)->middleware(['throttle:agents', RequireOAuthKeys::class, 'auth:api'])->name('mcp.account');

// Discovery (RFC 9728, RFC 8414, RFC 7517) and dynamic client registration
// (RFC 7591). Route names are the ones laravel/mcp's 401 header middleware
// looks up. Registration is open to any agent app, so it is throttled.
Registrar::ensureMcpScope();
Route::get('.well-known/oauth-protected-resource', [OAuthMetadataController::class, 'protectedResource'])->name('mcp.oauth.protected-resource');
Route::get('.well-known/oauth-protected-resource/{path}', [OAuthMetadataController::class, 'protectedResource'])->where('path', '.*')->name('mcp.oauth.protected-resource.nested');
Route::get('.well-known/oauth-authorization-server', [OAuthMetadataController::class, 'authorizationServer'])->name('mcp.oauth.authorization-server');
Route::get('.well-known/oauth-authorization-server/{path}', [OAuthMetadataController::class, 'authorizationServer'])->where('path', '.*')->name('mcp.oauth.authorization-server.nested');
Route::get('oauth/jwks', [OAuthMetadataController::class, 'jwks'])->name('oauth.jwks');
Route::post('oauth/register', OAuthRegisterController::class)->middleware('throttle:oauth-register')->name('mcp.oauth.register');
