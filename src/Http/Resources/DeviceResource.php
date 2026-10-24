<?php

namespace Puneetpal\DeviceTokens\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * @property \Laravel\Sanctum\PersonalAccessToken $resource
 */
class DeviceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $current = $request->user()?->currentAccessToken();

        return [
            'id' => $this->resource->getKey(),
            'name' => $this->resource->name,
            'platform' => $this->resource->platform,
            'app_version' => $this->resource->app_version,
            'abilities' => $this->resource->abilities ?? [],
            'current' => $current instanceof PersonalAccessToken
                && $current->getKey() === $this->resource->getKey(),
            'last_used_at' => $this->resource->last_used_at?->toIso8601String(),
            'expires_at' => $this->resource->expires_at?->toIso8601String(),
            'created_at' => $this->resource->created_at?->toIso8601String(),
        ];
    }
}
