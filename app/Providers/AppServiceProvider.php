<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Agents sign in with the authorization code flow only (see configureOAuth).
        Passport::$deviceCodeGrantEnabled = false;
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureAgentLimits();
        $this->configureOAuth();
    }

    /**
     * The API and MCP server are open to any client, so each client gets a
     * budget. Behind Laravel Cloud's Cloudflare the client address is in
     * CF-Connecting-IP (as in the search box).
     */
    protected function configureAgentLimits(): void
    {
        $client = fn (Request $r): string => (string) ($r->header('CF-Connecting-IP') ?: $r->ip());

        RateLimiter::for('agents', fn (Request $r) => Limit::perMinute(120)->by('agents:'.$client($r)));
        // Each of these sends an email, so far fewer (the per-address limits still apply on top).
        RateLimiter::for('agent-signups', fn (Request $r) => Limit::perHour(10)->by('agent-signups:'.$client($r)));
        // Agent apps registering as OAuth clients: each app does this once per install.
        RateLimiter::for('oauth-register', fn (Request $r) => Limit::perHour(20)->by('oauth-register:'.$client($r)));
    }

    /**
     * OAuth for agents acting for a signed-in person (/mcp/account). Clients
     * register themselves (RFC 7591, laravel/mcp) and use PKCE; the person
     * approves each one on the consent page and can revoke it in settings.
     * Keys come from PASSPORT_PRIVATE_KEY and PASSPORT_PUBLIC_KEY.
     */
    protected function configureOAuth(): void
    {
        Passport::authorizationView('agent.authorize');
        Passport::tokensExpireIn(CarbonInterval::hours(8));
        Passport::refreshTokensExpireIn(CarbonInterval::days(60));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
