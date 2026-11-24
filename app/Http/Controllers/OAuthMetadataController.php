<?php

namespace App\Http\Controllers;

use App\Support\PageMeta;
use Illuminate\Http\JsonResponse;
use Laravel\Mcp\Server\Registrar;
use Laravel\Passport\Passport;

/**
 * OAuth discovery for agents (routes/ai.php): protected resource metadata
 * (RFC 9728), authorization server metadata (RFC 8414) and the key that
 * signs access tokens (RFC 7517). laravel/mcp's own versions lack the JWKS
 * and bearer method fields that clients and scanners look for.
 */
class OAuthMetadataController extends Controller
{
    public function protectedResource(string $path = ''): JsonResponse
    {
        return $this->json([
            'resource' => url('/'.ltrim($path, '/')),
            'authorization_servers' => [url('/')],
            'scopes_supported' => [Registrar::OAUTH_SCOPE],
            'bearer_methods_supported' => ['header'],
            'resource_name' => PageMeta::SITE_NAME,
            'resource_documentation' => route('agents'),
        ]);
    }

    public function authorizationServer(): JsonResponse
    {
        return $this->json([
            'issuer' => url('/'),
            'authorization_endpoint' => route('passport.authorizations.authorize'),
            'token_endpoint' => route('passport.token'),
            'registration_endpoint' => route('mcp.oauth.register'),
            'jwks_uri' => route('oauth.jwks'),
            'response_types_supported' => ['code'],
            'response_modes_supported' => ['query'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'scopes_supported' => [Registrar::OAUTH_SCOPE],
            'token_endpoint_auth_methods_supported' => ['none', 'client_secret_basic', 'client_secret_post'],
            'service_documentation' => url('/auth.md'),
        ]);
    }

    /** The public half of Passport's signing key, as a JWK set (RS256). Empty until the keys are set. */
    public function jwks(): JsonResponse
    {
        $pem = config('passport.public_key') ?: (is_file($file = Passport::keyPath('oauth-public.key')) ? file_get_contents($file) : null);
        // A missing or malformed key (a bad paste into the env var) means an empty set, not a 500.
        $key = $pem ? openssl_pkey_get_public(str_replace('\\n', "\n", $pem)) : false;
        $details = $key ? openssl_pkey_get_details($key) : false;
        if (! is_array($details) || ! isset($details['rsa'])) {
            return $this->json(['keys' => []]);
        }
        $b64 = fn (string $bin) => rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
        $n = $b64($details['rsa']['n']);

        return $this->json(['keys' => [[
            'kty' => 'RSA', 'use' => 'sig', 'alg' => 'RS256', 'kid' => substr(hash('sha256', $n), 0, 16),
            'n' => $n, 'e' => $b64($details['rsa']['e']),
        ]]]);
    }

    private function json(array $data): JsonResponse
    {
        return response()->json($data, 200, ['Cache-Control' => 'public, max-age=3600', 'Access-Control-Allow-Origin' => '*'], JSON_UNESCAPED_SLASHES);
    }
}
