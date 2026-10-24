<?php

namespace Puneetpal\DeviceTokens\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\PersonalAccessToken;
use LogicException;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\Test;
use Puneetpal\DeviceTokens\HasDeviceTokens;
use Puneetpal\DeviceTokens\Tests\Fixtures\PlainUser;
use Puneetpal\DeviceTokens\Tests\TestCase;

class LoginRouteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('auth:sanctum')->get('/me', fn () => ['id' => auth()->id()]);
    }

    protected function customPrefixAndAbilities($app): void
    {
        $app['config']->set('device-tokens.routes.prefix', 'api/v1/auth');
        $app['config']->set('device-tokens.abilities', ['orders:read', 'profile:read']);
    }

    protected function usernameLogin($app): void
    {
        $app['config']->set('device-tokens.login.username', 'username');
    }

    #[Test]
    public function it_issues_a_token_for_valid_credentials(): void
    {
        Carbon::setTestNow('2026-10-23 12:00:00');
        $user = $this->createUser();

        $response = $this->postJson('/api/auth/token', [
            'email' => 'user@example.com',
            'password' => 'secret-password',
            'device_name' => 'iPhone 16',
            'platform' => 'ios',
            'app_version' => '3.2.0',
        ])
            ->assertCreated()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('expires_at', Carbon::parse('2026-11-22 12:00:00')->toIso8601String())
            ->assertJsonPath('device.name', 'iPhone 16')
            ->assertJsonPath('device.platform', 'ios')
            ->assertJsonPath('device.app_version', '3.2.0')
            ->assertJsonPath('device.abilities', ['*']);

        $this->assertSame(1, $user->tokens()->count());

        $this->withToken($response->json('token'))->getJson('/me')
            ->assertOk()
            ->assertJson(['id' => $user->id]);
    }

    #[Test]
    public function logging_in_again_from_the_same_device_replaces_the_token(): void
    {
        $this->createUser();

        $payload = ['email' => 'user@example.com', 'password' => 'secret-password', 'device_name' => 'iPhone 16'];

        $first = $this->postJson('/api/auth/token', $payload)->json('token');
        $second = $this->postJson('/api/auth/token', $payload)->json('token');

        $this->assertSame(1, PersonalAccessToken::query()->count());
        $this->withToken($first)->getJson('/me')->assertUnauthorized();
        $this->withToken($second)->getJson('/me')->assertOk();
    }

    #[Test]
    public function it_rejects_bad_credentials_with_a_validation_error(): void
    {
        $this->createUser();

        $wrongPassword = $this->postJson('/api/auth/token', [
            'email' => 'user@example.com',
            'password' => 'wrong',
            'device_name' => 'iPhone 16',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        $unknownUser = $this->postJson('/api/auth/token', [
            'email' => 'nobody@example.com',
            'password' => 'secret-password',
            'device_name' => 'iPhone 16',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        $this->assertSame(
            $wrongPassword->json(),
            $unknownUser->json(),
            'an unknown account and a wrong password must be indistinguishable',
        );
        $this->assertSame(0, PersonalAccessToken::query()->count());
    }

    #[Test]
    public function a_non_string_username_is_a_validation_error_not_a_server_error(): void
    {
        $this->createUser();

        $this->postJson('/api/auth/token', [
            'email' => ['user@example.com'],
            'password' => 'secret-password',
            'device_name' => 'iPhone 16',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    #[Test]
    public function the_login_response_does_not_mark_the_new_token_as_current(): void
    {
        $this->createUser();

        $this->postJson('/api/auth/token', [
            'email' => 'user@example.com',
            'password' => 'secret-password',
            'device_name' => 'iPhone 16',
        ])
            ->assertCreated()
            ->assertJsonPath('device.current', false)
            ->assertJsonPath('device.last_used_at', null);
    }

    #[Test]
    #[DefineEnvironment('usernameLogin')]
    public function the_username_field_is_configurable(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->string('username')->nullable());

        $this->createUser(['username' => 'puneet']);

        $this->postJson('/api/auth/token', [
            'username' => 'puneet',
            'password' => 'secret-password',
            'device_name' => 'Pixel 9',
        ])->assertCreated();

        $this->postJson('/api/auth/token', [
            'username' => 'someone-else',
            'password' => 'secret-password',
            'device_name' => 'Pixel 9',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['username']);
    }

    #[Test]
    public function a_user_model_without_the_trait_is_a_configuration_error(): void
    {
        // Set at request time: the controller resolves the user provider from
        // config on every call, so a fresh application is not needed. (A
        // method-level DefineEnvironment cannot override this key because
        // Testbench runs the base defineEnvironment() after the attribute.)
        config()->set('auth.providers.users.model', PlainUser::class);

        $this->withoutExceptionHandling();
        $this->createUser();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(HasDeviceTokens::class);

        $this->postJson('/api/auth/token', [
            'email' => 'user@example.com',
            'password' => 'secret-password',
            'device_name' => 'iPhone 16',
        ]);
    }

    #[Test]
    public function it_requires_a_device_name(): void
    {
        $this->createUser();

        $this->postJson('/api/auth/token', [
            'email' => 'user@example.com',
            'password' => 'secret-password',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['device_name']);

        $this->postJson('/api/auth/token', [
            'email' => 'user@example.com',
            'password' => 'secret-password',
            'device_name' => str_repeat('x', 101),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['device_name']);
    }

    #[Test]
    public function login_attempts_are_rate_limited_per_username_and_ip(): void
    {
        $this->createUser();

        $payload = ['email' => 'user@example.com', 'password' => 'wrong', 'device_name' => 'iPhone 16'];

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/token', $payload)->assertUnprocessable();
        }

        $this->postJson('/api/auth/token', $payload)
            ->assertTooManyRequests()
            ->assertHeader('Retry-After')
            ->assertJsonPath('message', 'Too Many Attempts.');

        $this->postJson('/api/auth/token', [...$payload, 'email' => 'someone-else@example.com'])
            ->assertUnprocessable();
    }

    #[Test]
    #[DefineEnvironment('customPrefixAndAbilities')]
    public function the_prefix_and_default_abilities_come_from_config(): void
    {
        $this->createUser();

        $this->postJson('/api/v1/auth/token', [
            'email' => 'user@example.com',
            'password' => 'secret-password',
            'device_name' => 'iPhone 16',
        ])
            ->assertCreated()
            ->assertJsonPath('device.abilities', ['orders:read', 'profile:read']);

        $this->assertSame('api/v1/auth/token/rotate', Route::getRoutes()->getByName('device-tokens.token.rotate')->uri());
    }
}
