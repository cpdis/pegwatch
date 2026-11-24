<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureAgentLimits();
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
