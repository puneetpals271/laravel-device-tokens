<?php

namespace Puneetpal\DeviceTokens\Listeners;

use Illuminate\Auth\Events\PasswordReset;

/**
 * Signs every device out when a password is reset. Registered by the
 * service provider when config('device-tokens.revoke_on_password_reset')
 * is true; you can also register it yourself for other risk events.
 */
class RevokeTokensOnPasswordReset
{
    public function handle(PasswordReset $event): void
    {
        $user = $event->user;

        if (method_exists($user, 'revokeAllDeviceTokens')) {
            $user->revokeAllDeviceTokens();

            return;
        }

        if (method_exists($user, 'tokens')) {
            $user->tokens()->delete();
        }
    }
}
