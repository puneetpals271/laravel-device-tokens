<?php

namespace Puneetpal\DeviceTokens\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Puneetpal\DeviceTokens\Http\Resources\DeviceResource;

class DeviceController extends Controller
{
    /**
     * List the user's active device tokens, newest first.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $this->ensureSupportsDeviceTokens($user);

        return DeviceResource::collection($user->deviceTokens()->get());
    }

    /**
     * Revoke one device. A token that belongs to someone else, or that does
     * not exist, is reported as not found rather than forbidden so the ids
     * of other users' tokens cannot be probed.
     */
    public function destroy(Request $request, string $id): Response
    {
        $user = $request->user();

        $this->ensureSupportsDeviceTokens($user);

        if (! $user->revokeDeviceToken($id)) {
            abort(Response::HTTP_NOT_FOUND, 'Device not found.');
        }

        return response()->noContent();
    }
}
