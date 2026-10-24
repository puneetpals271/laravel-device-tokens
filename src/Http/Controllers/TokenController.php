<?php

namespace Puneetpal\DeviceTokens\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\NewAccessToken;
use Puneetpal\DeviceTokens\Http\Requests\IssueDeviceTokenRequest;
use Puneetpal\DeviceTokens\Http\Resources\DeviceResource;

class TokenController extends Controller
{
    /**
     * Log in: verify the credentials and issue a token for the named device.
     *
     * An unknown username and a wrong password produce the same 422 response
     * so the endpoint cannot be used to check whether an account exists.
     */
    public function store(IssueDeviceTokenRequest $request): JsonResponse
    {
        $username = $request->usernameField();
        $provider = Auth::createUserProvider((string) config('device-tokens.login.provider', 'users'));

        $user = $provider->retrieveByCredentials([$username => $request->input($username)]);
        $credentials = ['password' => $request->input('password')];

        if (! $user || ! $provider->validateCredentials($user, $credentials)) {
            throw ValidationException::withMessages([
                $username => [trans('auth.failed')],
            ]);
        }

        $this->ensureSupportsDeviceTokens($user);

        $provider->rehashPasswordIfRequired($user, $credentials);

        $token = $user->issueDeviceToken(
            $request->input('device_name'),
            null,
            null,
            $request->metadata(),
        );

        return $this->tokenResponse($token, $request, Response::HTTP_CREATED);
    }

    /**
     * Replace the current token with a fresh one for the same device.
     */
    public function rotate(Request $request): JsonResponse
    {
        $user = $request->user();

        $this->ensureSupportsDeviceTokens($user);
        $this->ensureAuthenticatedWithDeviceToken($user);

        return $this->tokenResponse($user->rotateCurrentToken(), $request);
    }

    /**
     * Log out this device only.
     */
    public function destroy(Request $request): Response
    {
        $user = $request->user();

        $this->ensureSupportsDeviceTokens($user);
        $this->ensureAuthenticatedWithDeviceToken($user);

        $user->revokeCurrentDeviceToken();

        return response()->noContent();
    }

    /**
     * The response shape shared by login and rotation. The plain-text token
     * appears here and nowhere else; it is never logged or stored.
     */
    protected function tokenResponse(NewAccessToken $token, Request $request, int $status = Response::HTTP_OK): JsonResponse
    {
        return response()->json([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
            'device' => (new DeviceResource($token->accessToken))->resolve($request),
        ], $status);
    }
}
