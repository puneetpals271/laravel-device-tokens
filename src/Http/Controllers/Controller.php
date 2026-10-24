<?php

namespace Puneetpal\DeviceTokens\Http\Controllers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Response;
use Laravel\Sanctum\PersonalAccessToken;
use LogicException;
use Puneetpal\DeviceTokens\HasDeviceTokens;

abstract class Controller
{
    /**
     * Fail loudly when the authenticated model does not use the trait. This
     * is a wiring mistake in the application, so it is a LogicException
     * (HTTP 500) rather than a client error.
     *
     * @phpstan-assert \Illuminate\Contracts\Auth\Authenticatable&\Puneetpal\DeviceTokens\HasDeviceTokens $user
     */
    protected function ensureSupportsDeviceTokens(?Authenticatable $user): void
    {
        if (! $user || ! in_array(HasDeviceTokens::class, class_uses_recursive($user), true)) {
            throw new LogicException(sprintf(
                'The authenticated model must use the %s trait to use the device token routes.',
                HasDeviceTokens::class,
            ));
        }
    }

    /**
     * The routes that act on "the current token" only make sense for a
     * request authenticated with a bearer token. A Sanctum SPA cookie
     * session carries a TransientToken instead, which cannot be rotated or
     * revoked, so the client is told so with a 400 instead of a 500.
     *
     * @param  \Illuminate\Contracts\Auth\Authenticatable&\Laravel\Sanctum\HasApiTokens  $user
     */
    protected function ensureAuthenticatedWithDeviceToken(Authenticatable $user): void
    {
        if (! $user->currentAccessToken() instanceof PersonalAccessToken) {
            abort(
                Response::HTTP_BAD_REQUEST,
                'This endpoint requires a request authenticated with a device token (Authorization: Bearer), not a cookie session.'
            );
        }
    }
}
