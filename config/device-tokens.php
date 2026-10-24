<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Token lifetime
    |--------------------------------------------------------------------------
    |
    | Minutes a device token stays valid after it is issued or rotated. The
    | default is 30 days. Set it to null to leave expires_at empty and fall
    | back to Sanctum's own "expiration" setting (which is null by default,
    | meaning tokens never expire; do not ship a mobile app that way).
    |
    */

    'ttl' => env('DEVICE_TOKENS_TTL', 60 * 24 * 30),

    /*
    |--------------------------------------------------------------------------
    | Default abilities
    |--------------------------------------------------------------------------
    |
    | Abilities given to a token when the caller does not pass any. The
    | ready-made login route always uses this list. Enforce them on routes
    | with Sanctum's CheckAbilities and CheckForAnyAbility middleware.
    |
    */

    'abilities' => ['*'],

    /*
    |--------------------------------------------------------------------------
    | Revoke every device on password reset
    |--------------------------------------------------------------------------
    |
    | When true, the package listens for Illuminate\Auth\Events\PasswordReset
    | and deletes all of the user's tokens, signing every device out. The
    | user logs in again on the device they are holding.
    |
    */

    'revoke_on_password_reset' => env('DEVICE_TOKENS_REVOKE_ON_PASSWORD_RESET', false),

    /*
    |--------------------------------------------------------------------------
    | Login route settings
    |--------------------------------------------------------------------------
    |
    | "provider" is the auth user provider used to find and verify the user
    | (see config/auth.php). "username" is the request field and column the
    | user is looked up by.
    |
    */

    'login' => [
        'provider' => 'users',
        'username' => 'email',
    ],

    /*
    |--------------------------------------------------------------------------
    | Ready-made routes
    |--------------------------------------------------------------------------
    |
    | Off by default. When enabled the package registers, under the prefix:
    |
    |   POST   token            issue a token for a device (login)
    |   POST   token/rotate     replace the current token, same device
    |   DELETE token            revoke the current token (logout this device)
    |   GET    devices          list the user's active device tokens
    |   DELETE devices/{id}     revoke one device
    |
    | "middleware" wraps every route; "auth_middleware" wraps all but login.
    | The throttle entries are middleware strings; the two named limiters
    | are registered by the package and key login attempts by username and
    | IP, and rotations by user.
    |
    */

    'routes' => [
        'enabled' => env('DEVICE_TOKENS_ROUTES', false),
        'prefix' => 'api/auth',
        'middleware' => ['api'],
        'auth_middleware' => ['auth:sanctum'],
        'login_throttle' => 'throttle:device-tokens.login',
        'rotate_throttle' => 'throttle:device-tokens.rotate',
    ],

];
