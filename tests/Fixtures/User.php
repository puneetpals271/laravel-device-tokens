<?php

namespace Puneetpal\DeviceTokens\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use Puneetpal\DeviceTokens\HasDeviceTokens;

class User extends Authenticatable
{
    use HasApiTokens;
    use HasDeviceTokens;

    protected $table = 'users';

    protected $guarded = [];

    protected $hidden = ['password', 'remember_token'];
}
