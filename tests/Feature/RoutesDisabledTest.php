<?php

namespace Puneetpal\DeviceTokens\Tests\Feature;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Puneetpal\DeviceTokens\Tests\TestCase;

class RoutesDisabledTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('device-tokens.routes.enabled', false);
    }

    #[Test]
    public function no_routes_are_registered_by_default(): void
    {
        $this->createUser();

        $this->postJson('/api/auth/token', [
            'email' => 'user@example.com',
            'password' => 'secret-password',
            'device_name' => 'iPhone 16',
        ])->assertNotFound();

        foreach (['token.issue', 'token.rotate', 'token.revoke', 'devices.index', 'devices.destroy'] as $name) {
            $this->assertFalse(Route::has('device-tokens.'.$name), "device-tokens.{$name} should not be registered");
        }
    }

    #[Test]
    public function the_trait_still_works_without_the_routes(): void
    {
        $user = $this->createUser();

        $token = $user->issueDeviceToken('iPhone 16');

        $this->assertSame(1, $user->tokens()->count());
        $this->assertNotEmpty($token->plainTextToken);
    }
}
