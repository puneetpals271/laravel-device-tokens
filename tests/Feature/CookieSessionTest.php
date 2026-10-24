<?php

namespace Puneetpal\DeviceTokens\Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Puneetpal\DeviceTokens\Tests\TestCase;

/**
 * A request authenticated through Sanctum's SPA cookie flow carries a
 * TransientToken, not a personal access token. The routes that act on
 * "the current token" must refuse such a request with a client error, and
 * the device list and per-device revoke must still work.
 */
class CookieSessionTest extends TestCase
{
    #[Test]
    public function rotating_from_a_cookie_session_is_a_400_not_a_500(): void
    {
        $user = $this->createUser();
        $user->issueDeviceToken('iPhone 16');

        $this->actingAsCookieSession($user)
            ->postJson('/api/auth/token/rotate')
            ->assertBadRequest()
            ->assertJsonPath('message', fn (string $message) => str_contains($message, 'Authorization: Bearer'));

        $this->assertSame(1, $user->tokens()->count(), 'no token is created or deleted');
    }

    #[Test]
    public function logging_out_from_a_cookie_session_is_a_400_and_revokes_nothing(): void
    {
        $user = $this->createUser();
        $user->issueDeviceToken('iPhone 16');

        $this->actingAsCookieSession($user)->deleteJson('/api/auth/token')->assertBadRequest();

        $this->assertSame(1, $user->tokens()->count());
    }

    #[Test]
    public function listing_and_revoking_devices_still_work_from_a_cookie_session(): void
    {
        $user = $this->createUser();
        $phone = $user->issueDeviceToken('iPhone 16');

        $this->actingAsCookieSession($user)
            ->getJson('/api/auth/devices')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.current', false);

        $this->deleteJson('/api/auth/devices/'.$phone->accessToken->getKey())->assertNoContent();

        $this->assertSame(0, $user->tokens()->count());
    }
}
