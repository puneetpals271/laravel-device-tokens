<?php

namespace Puneetpal\DeviceTokens\Tests\Feature;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\Test;
use Puneetpal\DeviceTokens\Tests\TestCase;

class IssueDeviceTokenTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('auth:sanctum')->get('/me', fn () => ['id' => auth()->id()]);
    }

    #[Test]
    public function it_issues_a_token_with_abilities_expiry_and_metadata(): void
    {
        Carbon::setTestNow('2026-10-23 12:00:00');

        $user = $this->createUser();

        $token = $user->issueDeviceToken('iPhone 16', ['orders:read'], 60, [
            'platform' => 'ios',
            'app_version' => '3.2.0 (411)',
        ]);

        $this->assertMatchesRegularExpression('/^\d+\|.+/', $token->plainTextToken);

        $stored = PersonalAccessToken::query()->sole();
        $this->assertSame('iPhone 16', $stored->name);
        $this->assertSame(['orders:read'], $stored->abilities);
        $this->assertSame('ios', $stored->platform);
        $this->assertSame('3.2.0 (411)', $stored->app_version);
        $this->assertTrue($stored->expires_at->equalTo(Carbon::parse('2026-10-23 13:00:00')));

        $this->withToken($token->plainTextToken)->getJson('/me')
            ->assertOk()
            ->assertJson(['id' => $user->id]);
    }

    #[Test]
    public function it_uses_the_configured_ttl_and_abilities_by_default(): void
    {
        Carbon::setTestNow('2026-10-23 12:00:00');
        config()->set('device-tokens.ttl', 60 * 24 * 7);
        config()->set('device-tokens.abilities', ['profile:read']);

        $token = $this->createUser()->issueDeviceToken('Pixel 9');

        $this->assertSame(['profile:read'], $token->accessToken->abilities);
        $this->assertTrue($token->accessToken->expires_at->equalTo(Carbon::parse('2026-10-30 12:00:00')));
    }

    #[Test]
    public function a_null_ttl_in_config_leaves_expires_at_empty(): void
    {
        config()->set('device-tokens.ttl', null);

        $token = $this->createUser()->issueDeviceToken('Pixel 9');

        $this->assertNull($token->accessToken->expires_at);
    }

    #[Test]
    public function an_absolute_expiry_can_be_passed_instead_of_minutes(): void
    {
        $expiresAt = Carbon::parse('2027-01-01 00:00:00');

        $token = $this->createUser()->issueDeviceToken('Pixel 9', null, $expiresAt);

        $this->assertTrue($token->accessToken->expires_at->equalTo($expiresAt));
    }

    #[Test]
    public function it_replaces_the_existing_token_for_the_same_device(): void
    {
        $user = $this->createUser();

        $first = $user->issueDeviceToken('iPhone 16');
        $second = $user->issueDeviceToken('iPhone 16');

        $this->assertSame(1, $user->tokens()->count());
        $this->assertNotSame($first->plainTextToken, $second->plainTextToken);

        $this->withToken($first->plainTextToken)->getJson('/me')->assertUnauthorized();
        $this->withToken($second->plainTextToken)->getJson('/me')->assertOk();
    }

    #[Test]
    public function it_keeps_tokens_for_other_devices_and_other_users(): void
    {
        $user = $this->createUser();
        $other = $this->createUser(['email' => 'other@example.com']);

        $ipad = $user->issueDeviceToken('iPad');
        $otherPhone = $other->issueDeviceToken('iPhone 16');
        $user->issueDeviceToken('iPhone 16');
        $user->issueDeviceToken('iPhone 16');

        $this->assertSame(2, $user->tokens()->count());
        $this->assertSame(1, $other->tokens()->count());
        $this->withToken($ipad->plainTextToken)->getJson('/me')->assertOk();
        $this->withToken($otherPhone->plainTextToken)->getJson('/me')->assertOk();
    }

    #[Test]
    public function an_expired_token_is_rejected(): void
    {
        $user = $this->createUser();
        $token = $user->issueDeviceToken('iPhone 16', null, 30);

        $this->withToken($token->plainTextToken)->getJson('/me')->assertOk();

        $this->travel(31)->minutes();

        $this->withToken($token->plainTextToken)->getJson('/me')->assertUnauthorized();
    }

    #[Test]
    public function device_tokens_excludes_expired_tokens(): void
    {
        $user = $this->createUser();
        $user->issueDeviceToken('Old phone', null, 10);
        $this->travel(11)->minutes();
        $user->issueDeviceToken('New phone', null, 10);

        $this->assertSame(2, $user->tokens()->count());
        $this->assertSame(['New phone'], $user->deviceTokens()->pluck('name')->all());
    }

    #[Test]
    public function device_tokens_honours_sanctum_global_expiration(): void
    {
        config()->set('device-tokens.ttl', null);
        config()->set('sanctum.expiration', 60);

        $user = $this->createUser();
        $user->issueDeviceToken('Old phone');
        $this->travel(61)->minutes();
        $user->issueDeviceToken('New phone');

        $this->assertSame(['New phone'], $user->deviceTokens()->pluck('name')->all());
    }

    #[Test]
    public function metadata_outside_the_known_columns_is_ignored(): void
    {
        $token = $this->createUser()->issueDeviceToken('iPhone 16', null, null, [
            'platform' => 'ios',
            'app_version' => null,
            'tokenable_id' => 999,
            'token' => 'tampered',
        ]);

        $fresh = $token->accessToken->fresh();

        $this->assertSame('ios', $fresh->platform);
        $this->assertNull($fresh->app_version);
        $this->assertNotSame(999, $fresh->tokenable_id);
        $this->assertNotSame('tampered', $fresh->token);
    }
}
