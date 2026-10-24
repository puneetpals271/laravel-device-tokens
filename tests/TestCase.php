<?php

namespace Puneetpal\DeviceTokens\Tests;

use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\SanctumServiceProvider;
use Laravel\Sanctum\TransientToken;
use Orchestra\Testbench\TestCase as Orchestra;
use Puneetpal\DeviceTokens\DeviceTokensServiceProvider;
use Puneetpal\DeviceTokens\Tests\Fixtures\User;

abstract class TestCase extends Orchestra
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            SanctumServiceProvider::class,
            DeviceTokensServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('auth.providers.users.model', User::class);

        $app['config']->set('device-tokens.routes.enabled', true);
        $app['config']->set('device-tokens.routes.middleware', []);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadLaravelMigrations();
        $this->loadMigrationsFrom(__DIR__.'/../vendor/laravel/sanctum/database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    /**
     * A user whose requests should look like Sanctum's SPA cookie flow (a
     * TransientToken rather than a personal access token), or null.
     */
    protected ?User $cookieSessionUser = null;

    /**
     * Each HTTP call in a test should behave like a separate request. Laravel's
     * guards cache the resolved user for the lifetime of the application, so
     * without this a revoked token would still look authenticated on the
     * second call within one test.
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->app['auth']->forgetGuards();

        if ($this->cookieSessionUser !== null) {
            // Not Sanctum::actingAs(): that attaches a Mockery mock of
            // PersonalAccessToken, whereas a real cookie session carries a
            // TransientToken, which is the case these tests are about.
            $this->app['auth']->guard('sanctum')->setUser(
                $this->cookieSessionUser->withAccessToken(new TransientToken)
            );
            $this->app['auth']->shouldUse('sanctum');
        }

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    /**
     * Authenticate every following request the way a Sanctum SPA cookie
     * session would: the user is set on the guard with a TransientToken.
     */
    protected function actingAsCookieSession(User $user): static
    {
        $this->cookieSessionUser = $user;

        return $this;
    }

    protected function createUser(array $attributes = []): User
    {
        return User::query()->create(array_merge([
            'name' => 'Test User',
            'email' => 'user@example.com',
            'password' => Hash::make('secret-password'),
        ], $attributes));
    }
}
