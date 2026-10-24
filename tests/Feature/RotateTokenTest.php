<?php

namespace Puneetpal\DeviceTokens\Tests\Feature;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\TransientToken;
use PHPUnit\Framework\Attributes\Test;
use Puneetpal\DeviceTokens\Exceptions\NoDeviceTokenException;
use Puneetpal\DeviceTokens\Tests\TestCase;

class RotateTokenTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('auth:sanctum')->get('/me', fn () => ['id' => auth()->id()]);
    }

    #[Test]
    public function rotating_keeps_device_abilities_and_metadata_and_invalidates_the_old_token(): void
    {
        Carbon::setTestNow('2026-10-23 12:00:00');

        $user = $this->createUser();
        $old = $user->issueDeviceToken('iPhone 16', ['orders:read', 'orders:write'], 60 * 24 * 30, [
            'platform' => 'ios',
            'app_version' => '3.2.0',
        ]);

        $this->travel(20)->days();

        $response = $this->withToken($old->plainTextToken)
            ->postJson('/api/auth/token/rotate')
            ->assertOk()
            ->assertJsonStructure(['token', 'token_type', 'expires_at', 'device' => ['id', 'name', 'abilities']])
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('device.name', 'iPhone 16')
            ->assertJsonPath('device.platform', 'ios')
            ->assertJsonPath('device.app_version', '3.2.0')
            ->assertJsonPath('device.abilities', ['orders:read', 'orders:write'])
            ->assertJsonPath('device.current', true);

        $fresh = $response->json('token');

        $this->assertNotSame($old->plainTextToken, $fresh);
        $this->assertSame(1, $user->tokens()->count());
        $this->assertNull(PersonalAccessToken::query()->find($old->accessToken->getKey()));

        $this->withToken($old->plainTextToken)->getJson('/me')->assertUnauthorized();
        $this->withToken($fresh)->getJson('/me')->assertOk();

        $this->assertSame(
            Carbon::parse('2026-12-12 12:00:00')->toIso8601String(),
            $response->json('expires_at'),
            'rotation applies the configured ttl from the moment of rotation, not from the original login',
        );
    }

    #[Test]
    public function rotating_on_the_model_returns_the_plain_text_token_and_updates_the_current_token(): void
    {
        $user = $this->createUser();
        $old = $user->issueDeviceToken('iPhone 16', ['orders:read']);

        $user->withAccessToken($old->accessToken);

        $fresh = $user->rotateCurrentToken(120);

        $this->assertMatchesRegularExpression('/^\d+\|.+/', $fresh->plainTextToken);
        $this->assertSame(['orders:read'], $fresh->accessToken->abilities);
        $this->assertSame($fresh->accessToken->getKey(), $user->currentAccessToken()->getKey());
        $this->assertSame(1, $user->tokens()->count());
    }

    #[Test]
    public function rotating_without_a_personal_access_token_throws(): void
    {
        $user = $this->createUser();
        $user->withAccessToken(new TransientToken);

        $this->expectException(NoDeviceTokenException::class);

        $user->rotateCurrentToken();
    }

    #[Test]
    public function the_rotate_route_requires_a_valid_token(): void
    {
        $user = $this->createUser();
        $token = $user->issueDeviceToken('iPhone 16', null, 10);

        $this->postJson('/api/auth/token/rotate')->assertUnauthorized();

        $this->travel(11)->minutes();

        $this->withToken($token->plainTextToken)
            ->postJson('/api/auth/token/rotate')
            ->assertUnauthorized();

        $this->assertSame(1, $user->tokens()->count(), 'an expired token cannot be rotated and is left for prune-expired');
    }

    #[Test]
    public function the_rotate_route_is_rate_limited_per_user(): void
    {
        $user = $this->createUser();
        $token = $this->createUser(['email' => 'two@example.com'])->issueDeviceToken('Pixel 9');
        $plain = $user->issueDeviceToken('iPhone 16')->plainTextToken;

        for ($i = 0; $i < 10; $i++) {
            $plain = $this->withToken($plain)->postJson('/api/auth/token/rotate')->assertOk()->json('token');
        }

        $this->withToken($plain)->postJson('/api/auth/token/rotate')
            ->assertTooManyRequests()
            ->assertHeader('Retry-After');

        $this->withToken($token->plainTextToken)->postJson('/api/auth/token/rotate')->assertOk();
    }
}
