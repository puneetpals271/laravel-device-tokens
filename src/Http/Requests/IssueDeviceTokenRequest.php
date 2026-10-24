<?php

namespace Puneetpal\DeviceTokens\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class IssueDeviceTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            $this->usernameField() => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:100'],
            'platform' => ['nullable', 'string', 'max:50'],
            'app_version' => ['nullable', 'string', 'max:50'],
        ];
    }

    public function usernameField(): string
    {
        return (string) config('device-tokens.login.username', 'email');
    }

    /**
     * @return array{platform?: string|null, app_version?: string|null}
     */
    public function metadata(): array
    {
        return $this->only(['platform', 'app_version']);
    }
}
