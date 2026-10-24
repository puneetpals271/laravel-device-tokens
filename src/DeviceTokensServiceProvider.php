<?php

namespace Puneetpal\DeviceTokens;

use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Puneetpal\DeviceTokens\Listeners\RevokeTokensOnPasswordReset;

class DeviceTokensServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/device-tokens.php', 'device-tokens');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/device-tokens.php' => $this->app->configPath('device-tokens.php'),
            ], 'device-tokens-config');

            $this->publishesMigrations([
                __DIR__.'/../database/migrations' => $this->app->databasePath('migrations'),
            ], 'device-tokens-migrations');
        }

        $this->registerRateLimiters();

        if ($this->app['config']->get('device-tokens.routes.enabled')) {
            $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        }

        if ($this->app['config']->get('device-tokens.revoke_on_password_reset')) {
            Event::listen(PasswordReset::class, RevokeTokensOnPasswordReset::class);
        }
    }

    /**
     * Named limiters for the ready-made routes. Login is keyed by username
     * and IP so one address cannot lock out everyone behind a carrier NAT,
     * with a looser per-IP ceiling. Rotation is keyed by user, so a stolen
     * token cannot be renewed in a tight loop. Applications can override
     * either by registering the same name in their own provider.
     */
    protected function registerRateLimiters(): void
    {
        $username = $this->app['config']->get('device-tokens.login.username', 'email');

        RateLimiter::for('device-tokens.login', function (Request $request) use ($username) {
            // The limiter runs before validation, so the field may be absent
            // or not a string; it must never raise a warning here.
            $identity = $request->input($username);
            $identity = is_string($identity) ? strtolower($identity) : '';

            return [
                Limit::perMinute(5)->by($identity.'|'.$request->ip()),
                Limit::perMinute(20)->by($request->ip()),
            ];
        });

        RateLimiter::for('device-tokens.rotate', function (Request $request) {
            return Limit::perMinute(10)->by((string) ($request->user()?->getAuthIdentifier() ?: $request->ip()));
        });
    }
}
