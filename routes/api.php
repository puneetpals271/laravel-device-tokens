<?php

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Route;
use Puneetpal\DeviceTokens\Http\Controllers\DeviceController;
use Puneetpal\DeviceTokens\Http\Controllers\TokenController;

$middleware = fn (string $key) => array_values(array_filter(Arr::wrap(config("device-tokens.routes.{$key}"))));

Route::prefix((string) config('device-tokens.routes.prefix', 'api/auth'))
    ->middleware($middleware('middleware'))
    ->name('device-tokens.')
    ->group(function () use ($middleware) {
        Route::post('token', [TokenController::class, 'store'])
            ->middleware($middleware('login_throttle'))
            ->name('token.issue');

        Route::middleware($middleware('auth_middleware'))->group(function () use ($middleware) {
            Route::post('token/rotate', [TokenController::class, 'rotate'])
                ->middleware($middleware('rotate_throttle'))
                ->name('token.rotate');

            Route::delete('token', [TokenController::class, 'destroy'])
                ->name('token.revoke');

            Route::get('devices', [DeviceController::class, 'index'])
                ->name('devices.index');

            Route::delete('devices/{id}', [DeviceController::class, 'destroy'])
                ->name('devices.destroy');
        });
    });
