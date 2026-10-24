<?php

namespace Puneetpal\DeviceTokens\Tests\Feature;

use Illuminate\Auth\Events\PasswordReset;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\Test;
use Puneetpal\DeviceTokens\Listeners\RevokeTokensOnPasswordReset;
use Puneetpal\DeviceTokens\Tests\TestCase;

class PasswordResetTest extends TestCase
{
    protected function enableRevocation($app): void
    {
        $app['config']->set('device-tokens.revoke_on_password_reset', true);
    }

    #[Test]
    #[DefineEnvironment('enableRevocation')]
    public function a_password_reset_revokes_every_device_when_enabled(): void
    {
        $user = $this->createUser();
        $bystander = $this->createUser(['email' => 'other@example.com']);

        $user->issueDeviceToken('iPhone 16');
        $user->issueDeviceToken('iPad');
        $bystander->issueDeviceToken('Pixel 9');

        event(new PasswordReset($user));

        $this->assertSame(0, $user->tokens()->count());
        $this->assertSame(1, $bystander->tokens()->count());
    }

    #[Test]
    public function a_password_reset_leaves_tokens_alone_by_default(): void
    {
        $user = $this->createUser();
        $user->issueDeviceToken('iPhone 16');

        event(new PasswordReset($user));

        $this->assertSame(1, $user->tokens()->count());
    }

    #[Test]
    public function the_listener_can_be_used_directly(): void
    {
        $user = $this->createUser();
        $user->issueDeviceToken('iPhone 16');

        (new RevokeTokensOnPasswordReset)->handle(new PasswordReset($user));

        $this->assertSame(0, $user->tokens()->count());
    }
}
