<?php

namespace Puneetpal\DeviceTokens;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;
use Puneetpal\DeviceTokens\Exceptions\NoDeviceTokenException;

/**
 * Per-device Sanctum tokens. Use alongside Laravel\Sanctum\HasApiTokens.
 *
 * @mixin \Laravel\Sanctum\HasApiTokens
 */
trait HasDeviceTokens
{
    /**
     * Metadata columns the package stores on personal_access_tokens.
     *
     * @var list<string>
     */
    protected static array $deviceTokenMetadataColumns = ['platform', 'app_version'];

    /**
     * Issue a token for a device, replacing any token this user already has
     * under the same device name.
     *
     * @param  array<int, string>|null  $abilities  null uses config('device-tokens.abilities')
     * @param  int|\DateTimeInterface|null  $ttl  minutes, an absolute expiry, or null for config('device-tokens.ttl')
     * @param  array{platform?: string|null, app_version?: string|null}  $metadata
     */
    public function issueDeviceToken(
        string $deviceName,
        ?array $abilities = null,
        int|DateTimeInterface|null $ttl = null,
        array $metadata = [],
    ): NewAccessToken {
        $abilities ??= config('device-tokens.abilities', ['*']);
        $expiresAt = $this->deviceTokenExpiry($ttl);
        $metadata = $this->deviceTokenMetadata($metadata);

        return $this->deviceTokenTransaction(function () use ($deviceName, $abilities, $expiresAt, $metadata) {
            $this->tokens()->where('name', $deviceName)->delete();

            return $this->storeDeviceToken($deviceName, $abilities, $expiresAt, $metadata);
        });
    }

    /**
     * Replace the token used for the current request with a fresh one that
     * keeps the same device name, abilities and metadata. The old token is
     * deleted in the same transaction, so the device never ends up with
     * zero tokens or two.
     *
     * @param  int|\DateTimeInterface|null  $ttl  minutes, an absolute expiry, or null for config('device-tokens.ttl')
     *
     * @throws \Puneetpal\DeviceTokens\Exceptions\NoDeviceTokenException
     */
    public function rotateCurrentToken(int|DateTimeInterface|null $ttl = null): NewAccessToken
    {
        $current = $this->currentDeviceToken();
        $expiresAt = $this->deviceTokenExpiry($ttl);
        $metadata = $this->deviceTokenMetadata(
            Arr::only($current->getAttributes(), static::$deviceTokenMetadataColumns)
        );

        $fresh = $this->deviceTokenTransaction(function () use ($current, $expiresAt, $metadata) {
            $token = $this->storeDeviceToken($current->name, $current->abilities ?? ['*'], $expiresAt, $metadata);

            $current->delete();

            return $token;
        });

        $this->withAccessToken($fresh->accessToken);

        return $fresh;
    }

    /**
     * The user's device tokens that can still authenticate, newest first.
     * Tokens past their expires_at, or older than Sanctum's global
     * "expiration" setting, are left out.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany<\Laravel\Sanctum\PersonalAccessToken, $this>
     */
    public function deviceTokens(): MorphMany
    {
        $query = $this->tokens()
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });

        if ($expiration = config('sanctum.expiration')) {
            $query->where('created_at', '>', now()->subMinutes((int) $expiration));
        }

        return $query->orderByDesc('created_at')->orderByDesc($query->getRelated()->getKeyName());
    }

    /**
     * The personal access token authenticating the current request.
     *
     * @throws \Puneetpal\DeviceTokens\Exceptions\NoDeviceTokenException
     */
    public function currentDeviceToken(): PersonalAccessToken
    {
        $current = $this->currentAccessToken();

        if (! $current instanceof PersonalAccessToken) {
            throw new NoDeviceTokenException(
                'The current request was not authenticated with a personal access token.'
            );
        }

        return $current;
    }

    /**
     * Revoke the token authenticating the current request (log out this device).
     */
    public function revokeCurrentDeviceToken(): bool
    {
        $current = $this->currentAccessToken();

        if (! $current instanceof PersonalAccessToken) {
            return false;
        }

        return (bool) $current->delete();
    }

    /**
     * Revoke one of the user's tokens by id. Returns false when the id does
     * not belong to this user.
     */
    public function revokeDeviceToken(int|string $tokenId): bool
    {
        return $this->tokens()->whereKey($tokenId)->delete() > 0;
    }

    /**
     * Revoke every token except the one authenticating the current request.
     * Returns the number of tokens deleted.
     */
    public function revokeOtherDeviceTokens(): int
    {
        $query = $this->tokens();

        $current = $this->currentAccessToken();

        if ($current instanceof PersonalAccessToken) {
            $query->whereKeyNot($current->getKey());
        }

        return $query->delete();
    }

    /**
     * Revoke every token the user has (sign out everywhere). Returns the
     * number of tokens deleted.
     */
    public function revokeAllDeviceTokens(): int
    {
        return $this->tokens()->delete();
    }

    /**
     * Resolve a ttl argument to an absolute expiry.
     *
     * @param  int|\DateTimeInterface|null  $ttl
     */
    protected function deviceTokenExpiry(int|DateTimeInterface|null $ttl): ?DateTimeInterface
    {
        if ($ttl instanceof DateTimeInterface) {
            return $ttl;
        }

        $minutes = $ttl ?? config('device-tokens.ttl');

        if ($minutes === null || $minutes === '') {
            return null;
        }

        return Carbon::now()->addMinutes((int) $minutes);
    }

    /**
     * Keep only the metadata columns the package knows about, dropping nulls.
     *
     * @param  array<string, mixed>  $metadata
     * @return array<string, string>
     */
    protected function deviceTokenMetadata(array $metadata): array
    {
        return array_filter(
            Arr::only($metadata, static::$deviceTokenMetadataColumns),
            fn ($value) => $value !== null && $value !== ''
        );
    }

    /**
     * Create the token through Sanctum, then attach the metadata columns,
     * which are not in PersonalAccessToken's fillable list.
     *
     * @param  array<int, string>  $abilities
     * @param  array<string, string>  $metadata
     */
    protected function storeDeviceToken(
        string $deviceName,
        array $abilities,
        ?DateTimeInterface $expiresAt,
        array $metadata,
    ): NewAccessToken {
        $token = $this->createToken($deviceName, $abilities, $expiresAt);

        if ($metadata !== []) {
            $token->accessToken->forceFill($metadata)->save();
        }

        return $token;
    }

    /**
     * Run the callback in a transaction on the token table's connection.
     *
     * @template TReturn
     *
     * @param  \Closure(): TReturn  $callback
     * @return TReturn
     */
    protected function deviceTokenTransaction(\Closure $callback): mixed
    {
        return $this->tokens()->getRelated()->getConnection()->transaction($callback);
    }
}
