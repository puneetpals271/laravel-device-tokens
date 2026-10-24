<?php

namespace Puneetpal\DeviceTokens\Tests\Feature;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Puneetpal\DeviceTokens\Tests\TestCase;

class DeviceRoutesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('auth:sanctum')->get('/me', fn () => ['id' => auth()->id()]);
    }

    #[Test]
    public function logout_revokes_only_the_current_device(): void
    {
        $user = $this->createUser();
        $phone = $user->issueDeviceToken('iPhone 16');
        $tablet = $user->issueDeviceToken('iPad');

        $this->withToken($phone->plainTextToken)->deleteJson('/api/auth/token')->assertNoContent();

        $this->assertSame(['iPad'], $user->tokens()->pluck('name')->all());
        $this->withToken($phone->plainTextToken)->getJson('/me')->assertUnauthorized();
        $this->withToken($tablet->plainTextToken)->getJson('/me')->assertOk();
    }

    #[Test]
    public function logout_requires_authentication(): void
    {
        $this->deleteJson('/api/auth/token')->assertUnauthorized();
    }

    #[Test]
    public function it_lists_active_devices_newest_first_and_marks_the_current_one(): void
    {
        $user = $this->createUser();
        $this->createUser(['email' => 'other@example.com'])->issueDeviceToken('Someone else');

        $tablet = $user->issueDeviceToken('iPad', ['orders:read'], 60, ['platform' => 'ipados']);
        $this->travel(1)->minute();
        $expired = $user->issueDeviceToken('Lost phone', null, 5);
        $this->travel(1)->minute();
        $phone = $user->issueDeviceToken('iPhone 16', null, 60, ['platform' => 'ios', 'app_version' => '3.2.0']);
        $this->travel(10)->minutes();

        $response = $this->withToken($phone->plainTextToken)->getJson('/api/auth/devices')->assertOk();

        $this->assertSame(['iPhone 16', 'iPad'], $response->json('data.*.name'));
        $this->assertSame([true, false], $response->json('data.*.current'));

        $response->assertJsonPath('data.0.id', $phone->accessToken->getKey())
            ->assertJsonPath('data.0.platform', 'ios')
            ->assertJsonPath('data.0.app_version', '3.2.0')
            ->assertJsonPath('data.1.id', $tablet->accessToken->getKey())
            ->assertJsonPath('data.1.abilities', ['orders:read'])
            ->assertJsonPath('data.1.platform', 'ipados')
            ->assertJsonPath('data.1.app_version', null)
            ->assertJsonStructure(['data' => [['id', 'name', 'platform', 'app_version', 'abilities', 'current', 'last_used_at', 'expires_at', 'created_at']]]);

        $this->assertNotNull($response->json('data.0.last_used_at'), 'Sanctum records last_used_at on each authenticated request');
        $this->assertNotContains($expired->accessToken->getKey(), $response->json('data.*.id'));
    }

    #[Test]
    public function it_revokes_one_device_by_id(): void
    {
        $user = $this->createUser();
        $phone = $user->issueDeviceToken('iPhone 16');
        $tablet = $user->issueDeviceToken('iPad');

        $this->withToken($phone->plainTextToken)
            ->deleteJson('/api/auth/devices/'.$tablet->accessToken->getKey())
            ->assertNoContent();

        $this->withToken($tablet->plainTextToken)->getJson('/me')->assertUnauthorized();
        $this->withToken($phone->plainTextToken)->getJson('/me')->assertOk();
    }

    #[Test]
    public function it_cannot_revoke_another_users_device(): void
    {
        $user = $this->createUser();
        $other = $this->createUser(['email' => 'other@example.com']);

        $mine = $user->issueDeviceToken('iPhone 16');
        $theirs = $other->issueDeviceToken('Pixel 9');

        $this->withToken($mine->plainTextToken)
            ->deleteJson('/api/auth/devices/'.$theirs->accessToken->getKey())
            ->assertNotFound()
            ->assertExactJson(['message' => 'Device not found.']);

        $this->withToken($mine->plainTextToken)
            ->deleteJson('/api/auth/devices/999999')
            ->assertNotFound()
            ->assertExactJson(['message' => 'Device not found.']);

        $this->assertSame(1, $other->tokens()->count());

        $this->withToken($theirs->plainTextToken)->getJson('/me')->assertOk();
    }

    #[Test]
    public function revoke_other_and_revoke_all_helpers(): void
    {
        $user = $this->createUser();
        $phone = $user->issueDeviceToken('iPhone 16');
        $user->issueDeviceToken('iPad');
        $user->issueDeviceToken('Mac');

        $user->withAccessToken($phone->accessToken);

        $this->assertSame(2, $user->revokeOtherDeviceTokens());
        $this->assertSame(['iPhone 16'], $user->tokens()->pluck('name')->all());

        $this->assertTrue($user->revokeDeviceToken($phone->accessToken->getKey()));
        $this->assertFalse($user->revokeDeviceToken($phone->accessToken->getKey()));

        $user->issueDeviceToken('iPad');
        $user->issueDeviceToken('Mac');

        $this->assertSame(2, $user->revokeAllDeviceTokens());
        $this->assertSame(0, $user->tokens()->count());
    }
}
