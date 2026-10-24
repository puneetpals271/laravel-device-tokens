<?php

namespace Puneetpal\DeviceTokens\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * A user model that has Sanctum but not HasDeviceTokens, to exercise the
 * misconfiguration path in the controllers.
 */
class PlainUser extends Authenticatable
{
    use HasApiTokens;

    protected $table = 'users';

    protected $guarded = [];

    protected $hidden = ['password', 'remember_token'];
}
