<?php

namespace Puneetpal\DeviceTokens\Exceptions;

use RuntimeException;

/**
 * Thrown when an operation needs the request's personal access token but
 * the user was authenticated another way (for example a Sanctum SPA cookie
 * session, which gives a TransientToken) or not at all.
 */
class NoDeviceTokenException extends RuntimeException
{
}
