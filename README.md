# laravel-device-tokens

Per-device [Laravel Sanctum](https://laravel.com/docs/sanctum) tokens for first-party iOS and Android apps.

Sanctum's `createToken()` is the right primitive for a mobile app, but it leaves the same decisions to every project: a user who logs in twice from one phone should hold one token, not two; tokens must expire, because phones get lost; an app needs a way to replace an expiring token without asking for the password again; and "sign out this device", "sign out everywhere" and "sign out after a password reset" are each a different delete. This package makes those decisions once, with tests:

- One token per device name. Logging in again from the same device replaces the previous token.
- An `expires_at` on every token (30 days by default).
- A rotation endpoint that issues a fresh token for the same device and deletes the old one in the same database transaction.
- Revocation of the current device, one device by id, every other device, or every device.
- Two nullable columns, `platform` and `app_version`, on `personal_access_tokens` so a sessions screen can show what each token belongs to.
- Optional ready-made routes with rate limiting, and an optional listener that signs every device out on password reset.

Every token it issues is an ordinary `personal_access_tokens` row that `auth:sanctum`, `tokenCan()`, the `abilities` middleware and `sanctum:prune-expired` already understand. Nothing in Sanctum's guard or token format changes.

## Requirements

- PHP 8.2, 8.3 or 8.4 (Laravel 13 needs PHP 8.3 or newer)
- Laravel 12 or 13
- `laravel/sanctum` 4.x, installed and migrated (`php artisan install:api` does this on a fresh application)
- A user model that uses `Laravel\Sanctum\HasApiTokens`

Laravel 11 is not supported: it left its security-fix window in March 2026 and Composer refuses to install it by default.

## Installation

1. Install the package.

   ```bash
   composer require puneetpal/laravel-device-tokens
   ```

   The service provider is discovered automatically.

2. Publish the configuration file and the migration.

   ```bash
   php artisan vendor:publish --tag=device-tokens-config
   php artisan vendor:publish --tag=device-tokens-migrations
   ```

   This creates `config/device-tokens.php` and `database/migrations/<timestamp>_add_device_metadata_to_personal_access_tokens_table.php`.

3. Run the migration.

   ```bash
   php artisan migrate
   ```

   It adds two nullable `string(50)` columns, `platform` and `app_version`, to Sanctum's token table. The table name is read from `Sanctum::$personalAccessTokenModel`, so a custom token model keeps working. The migration skips a column that already exists.

4. Add the trait to your user model, next to Sanctum's.

   ```php
   <?php

   namespace App\Models;

   use Illuminate\Foundation\Auth\User as Authenticatable;
   use Laravel\Sanctum\HasApiTokens;
   use Puneetpal\DeviceTokens\HasDeviceTokens;

   class User extends Authenticatable
   {
       use HasApiTokens;
       use HasDeviceTokens;

       // ...
   }
   ```

   `HasDeviceTokens` builds on `HasApiTokens` rather than replacing it. Existing calls to `createToken()` and `tokens()` keep working.

5. Enable the ready-made routes if you want them. In `config/device-tokens.php` set `routes.enabled` to `true`, or add to `.env`:

   ```dotenv
   DEVICE_TOKENS_ROUTES=true
   ```

   The routes are registered under `api/auth` (see [Configuration](#configuration)). If you prefer to write your own controllers, leave this off and call the trait methods directly; the [API reference](#api-reference) covers them.

6. Turn on revocation on password reset.

   ```dotenv
   DEVICE_TOKENS_REVOKE_ON_PASSWORD_RESET=true
   ```

   This listens for `Illuminate\Auth\Events\PasswordReset`, which Laravel's password broker fires after a successful reset, and deletes every token the user has. A password reset usually means the user suspects their account was compromised; without this, a token on a stolen phone stays valid for the rest of its lifetime. It is off by default only because an existing application may already do this itself. Call `revokeAllDeviceTokens()` from your email-change and account-recovery flows for the same reason.

7. Schedule Sanctum's own cleanup. Rotation and re-login delete the rows they replace; only tokens that expire while unused are left behind.

   ```php
   // routes/console.php
   use Illuminate\Support\Facades\Schedule;

   Schedule::command('sanctum:prune-expired --hours=24')->daily();
   ```

## Verify it works

With the routes enabled and `php artisan serve` running, log in. The user must already exist.

```bash
curl -i -X POST http://localhost:8000/api/auth/token \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"email":"user@example.com","password":"secret","device_name":"iPhone 16","platform":"ios","app_version":"1.0.0"}'
```

Expected: `HTTP/1.1 201 Created` and a body like

```json
{
  "token": "1|Ab3dEf...",
  "token_type": "Bearer",
  "expires_at": "2026-11-23T12:00:00+00:00",
  "device": {
    "id": 1,
    "name": "iPhone 16",
    "platform": "ios",
    "app_version": "1.0.0",
    "abilities": ["*"],
    "current": false,
    "last_used_at": null,
    "expires_at": "2026-11-23T12:00:00+00:00",
    "created_at": "2026-10-24T12:00:00+00:00"
  }
}
```

Wrong credentials, or an email that does not exist, both return `422 Unprocessable Content` with the same body, so the endpoint cannot be used to find out which accounts exist:

```json
{"message":"These credentials do not match our records.","errors":{"email":["These credentials do not match our records."]}}
```

Make an authenticated call. `GET /api/user` is the route `install:api` creates.

```bash
curl -i http://localhost:8000/api/user \
  -H "Accept: application/json" \
  -H "Authorization: Bearer 1|Ab3dEf..."
```

Expected: `HTTP/1.1 200 OK` with the user's JSON.

Rotate the token.

```bash
curl -i -X POST http://localhost:8000/api/auth/token/rotate \
  -H "Accept: application/json" \
  -H "Authorization: Bearer 1|Ab3dEf..."
```

Expected: `HTTP/1.1 200 OK` with the same shape as login, a new `token`, a new `expires_at` counted from now, and `"current": true`. The old token is deleted; using it again returns `401 Unauthorized` with `{"message":"Unauthenticated."}`.

List devices and log out.

```bash
curl -i http://localhost:8000/api/auth/devices \
  -H "Accept: application/json" \
  -H "Authorization: Bearer 2|NewToken..."
```

Expected: `200 OK` and `{"data":[{ ...device... }]}`, newest first, with `"current": true` on the caller's row.

```bash
curl -i -X DELETE http://localhost:8000/api/auth/token \
  -H "Accept: application/json" \
  -H "Authorization: Bearer 2|NewToken..."
```

Expected: `HTTP/1.1 204 No Content` and no body. The same token now gets `401 Unauthorized` on every route.

## Client integration

Both clients follow the same rules:

- Store the token and its `expires_at` in the platform keystore (iOS Keychain, Android Keystore-backed storage), never in `UserDefaults`, plain `SharedPreferences` or AsyncStorage.
- When the app comes to the foreground, rotate if the token is within a few days of expiring. Write the new token before the old one leaves memory.
- Treat `401` on any request as a state, not an error: clear the stored token and return to sign-in once, rather than retrying. An expired token cannot be rotated; the user logs in again.

### iOS (Swift)

Requires iOS 16 or later for `URL.appending(path:)`. The Keychain wrapper is complete; nothing else is assumed.

```swift
import Foundation
import Security
import UIKit

struct DeviceSession: Codable {
    let token: String
    let expiresAt: Date
}

enum AuthError: Error {
    case unauthenticated
    case server(status: Int)
    case keychain(OSStatus)
}

/// One Keychain item holding the session as JSON. Readable after the first
/// unlock so a background refresh can use it; never migrated to a new device.
enum SessionKeychain {
    private static let service = "com.example.app.device-session"
    private static let account = "default"

    private static var baseQuery: [String: Any] {
        [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: service,
            kSecAttrAccount as String: account,
        ]
    }

    static func load() -> DeviceSession? {
        var query = baseQuery
        query[kSecReturnData as String] = true
        query[kSecMatchLimit as String] = kSecMatchLimitOne

        var item: CFTypeRef?
        guard SecItemCopyMatching(query as CFDictionary, &item) == errSecSuccess,
              let data = item as? Data else { return nil }

        return try? JSONDecoder().decode(DeviceSession.self, from: data)
    }

    static func save(_ session: DeviceSession) throws {
        let data = try JSONEncoder().encode(session)
        let attributes: [String: Any] = [
            kSecValueData as String: data,
            kSecAttrAccessible as String: kSecAttrAccessibleAfterFirstUnlockThisDeviceOnly,
        ]

        var status = SecItemUpdate(baseQuery as CFDictionary, attributes as CFDictionary)
        if status == errSecItemNotFound {
            status = SecItemAdd(baseQuery.merging(attributes) { $1 } as CFDictionary, nil)
        }
        guard status == errSecSuccess else { throw AuthError.keychain(status) }
    }

    static func clear() {
        SecItemDelete(baseQuery as CFDictionary)
    }
}

final class AuthClient {
    private let baseURL = URL(string: "https://api.example.com")!

    func login(email: String, password: String) async throws {
        var request = URLRequest(url: baseURL.appending(path: "api/auth/token"))
        request.httpMethod = "POST"
        request.setValue("application/json", forHTTPHeaderField: "Content-Type")
        request.setValue("application/json", forHTTPHeaderField: "Accept")
        request.httpBody = try JSONEncoder().encode([
            "email": email,
            "password": password,
            "device_name": await UIDevice.current.name,
            "platform": "ios",
            "app_version": Bundle.main.object(forInfoDictionaryKey: "CFBundleShortVersionString") as? String ?? "unknown",
        ])

        let (data, response) = try await URLSession.shared.data(for: request)
        try SessionKeychain.save(try decodeSession(data, response))
    }

    /// Call when the app comes to the foreground. Rotates when the token is
    /// within three days of expiring. On 401 the stored session is cleared
    /// and the caller should show the sign-in screen.
    func rotateIfNeeded() async throws {
        guard let session = SessionKeychain.load(),
              session.expiresAt.timeIntervalSinceNow < 3 * 24 * 60 * 60 else { return }

        var request = URLRequest(url: baseURL.appending(path: "api/auth/token/rotate"))
        request.httpMethod = "POST"
        request.setValue("Bearer \(session.token)", forHTTPHeaderField: "Authorization")
        request.setValue("application/json", forHTTPHeaderField: "Accept")

        let (data, response) = try await URLSession.shared.data(for: request)
        do {
            try SessionKeychain.save(try decodeSession(data, response))
        } catch AuthError.unauthenticated {
            SessionKeychain.clear()
            throw AuthError.unauthenticated
        }
    }

    /// Adds the stored token to an outgoing request, if there is one.
    func authorize(_ request: inout URLRequest) {
        if let session = SessionKeychain.load() {
            request.setValue("Bearer \(session.token)", forHTTPHeaderField: "Authorization")
        }
    }

    private func decodeSession(_ data: Data, _ response: URLResponse) throws -> DeviceSession {
        let status = (response as? HTTPURLResponse)?.statusCode ?? 0
        if status == 401 { throw AuthError.unauthenticated }
        guard (200..<300).contains(status) else { throw AuthError.server(status: status) }

        let decoder = JSONDecoder()
        decoder.keyDecodingStrategy = .convertFromSnakeCase   // expires_at -> expiresAt
        decoder.dateDecodingStrategy = .iso8601               // 2026-11-23T12:00:00+00:00
        return try decoder.decode(DeviceSession.self, from: data)
    }
}
```

### Android (Kotlin)

Uses OkHttp 4, kotlinx.serialization and `androidx.security:security-crypto` (`EncryptedSharedPreferences`, whose values are encrypted with a key in the Android Keystore). That library is marked deprecated by Google but still functions; if you would rather not depend on it, encrypt the token with your own Keystore-backed AES key and store the ciphertext instead. `BuildConfig.VERSION_NAME` requires `buildConfig = true` in your module's `android.buildFeatures`.

```kotlin
import android.content.Context
import android.content.SharedPreferences
import android.os.Build
import androidx.security.crypto.EncryptedSharedPreferences
import androidx.security.crypto.MasterKey
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.put
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import java.io.IOException
import java.time.Duration
import java.time.OffsetDateTime

@Serializable
data class TokenResponse(
    val token: String,
    @SerialName("expires_at") val expiresAt: String,   // ISO 8601 with offset
)

class UnauthenticatedException : IOException("The token was rejected; sign in again.")

class AuthClient(context: Context, private val client: OkHttpClient = OkHttpClient()) {
    private val baseUrl = "https://api.example.com"
    private val json = Json { ignoreUnknownKeys = true }
    private val jsonMediaType = "application/json".toMediaType()

    private val prefs: SharedPreferences = EncryptedSharedPreferences.create(
        context,
        "device_session",
        MasterKey.Builder(context).setKeyScheme(MasterKey.KeyScheme.AES256_GCM).build(),
        EncryptedSharedPreferences.PrefKeyEncryptionScheme.AES256_SIV,
        EncryptedSharedPreferences.PrefValueEncryptionScheme.AES256_GCM,
    )

    suspend fun login(email: String, password: String) {
        val body = buildJsonObject {
            put("email", email)
            put("password", password)
            put("device_name", "${Build.MANUFACTURER} ${Build.MODEL}")
            put("platform", "android")
            put("app_version", BuildConfig.VERSION_NAME)
        }.toString().toRequestBody(jsonMediaType)

        val request = Request.Builder()
            .url("$baseUrl/api/auth/token")
            .post(body)
            .header("Accept", "application/json")
            .build()

        store(execute(request))
    }

    /**
     * Call when the app comes to the foreground. Rotates when the token is
     * within three days of expiring. On 401 the stored session is cleared and
     * the caller should show the sign-in screen.
     */
    suspend fun rotateIfNeeded() {
        val token = prefs.getString("token", null) ?: return
        val expiresAt = OffsetDateTime.parse(prefs.getString("expires_at", null) ?: return)
        if (Duration.between(OffsetDateTime.now(), expiresAt) > Duration.ofDays(3)) return

        val request = Request.Builder()
            .url("$baseUrl/api/auth/token/rotate")
            .post("".toRequestBody(jsonMediaType))
            .header("Authorization", "Bearer $token")
            .header("Accept", "application/json")
            .build()

        try {
            store(execute(request))
        } catch (e: UnauthenticatedException) {
            prefs.edit().clear().apply()
            throw e
        }
    }

    /** The stored token for an Authorization header, or null when signed out. */
    fun bearerToken(): String? = prefs.getString("token", null)

    private suspend fun execute(request: Request): TokenResponse = withContext(Dispatchers.IO) {
        client.newCall(request).execute().use { response ->
            when {
                response.code == 401 -> throw UnauthenticatedException()
                !response.isSuccessful -> throw IOException("HTTP ${response.code}")
                else -> json.decodeFromString<TokenResponse>(response.body!!.string())
            }
        }
    }

    private fun store(response: TokenResponse) {
        prefs.edit()
            .putString("token", response.token)
            .putString("expires_at", response.expiresAt)
            .apply()
    }
}
```

## Configuration

`config/device-tokens.php`:

| Key | Default | Meaning |
| --- | --- | --- |
| `ttl` | `43200` (30 days, in minutes; env `DEVICE_TOKENS_TTL`) | Lifetime of a token issued or rotated by this package, counted from the moment it is issued. `null` leaves `expires_at` empty and defers to Sanctum's `expiration` setting, which is itself `null` by default, meaning the token never expires. |
| `abilities` | `['*']` | Abilities given when the caller passes none. The login route always uses this list. |
| `revoke_on_password_reset` | `false` (env `DEVICE_TOKENS_REVOKE_ON_PASSWORD_RESET`) | Listen for `Illuminate\Auth\Events\PasswordReset` and delete all of the user's tokens. Recommended `true`. |
| `login.provider` | `'users'` | Auth user provider (from `config/auth.php`) the login route uses to find and verify the user. |
| `login.username` | `'email'` | Request field and column the login route looks the user up by. Also the field the login rate limiter keys on. |
| `routes.enabled` | `false` (env `DEVICE_TOKENS_ROUTES`) | Register the ready-made routes. |
| `routes.prefix` | `'api/auth'` | URI prefix for those routes. The `api` segment is included because package routes do not pass through `routes/api.php`. Use `'api/v1/auth'` if you version your API in the path. |
| `routes.middleware` | `['api']` | Middleware around every route. The `api` group exists once `install:api` has run. |
| `routes.auth_middleware` | `['auth:sanctum']` | Middleware around every route except login. |
| `routes.login_throttle` | `'throttle:device-tokens.login'` | Middleware on the login route. The named limiter allows 5 attempts per minute per username and IP, and 20 per minute per IP. |
| `routes.rotate_throttle` | `'throttle:device-tokens.rotate'` | Middleware on the rotate route. The named limiter allows 10 rotations per minute per user. |

Both named limiters are registered by the package. To change the limits, define limiters with the same names in your `AppServiceProvider::boot()`; application providers boot after package providers, so yours win. To remove throttling from a route, set its throttle key to `null`.

## API reference

### HTTP routes

Registered only when `routes.enabled` is `true`. All paths are relative to `routes.prefix`. All routes except login require `Authorization: Bearer <token>` and answer `401 Unauthorized` with `{"message":"Unauthenticated."}` otherwise.

| Method and path | Route name | Request | Success | Errors |
| --- | --- | --- | --- | --- |
| `POST token` | `device-tokens.token.issue` | JSON: `email` (or the configured `login.username`), `password`, `device_name` (max 100), optional `platform` and `app_version` (max 50 each) | `201 Created`, token response | `422` for validation or bad credentials; `429` when rate limited |
| `POST token/rotate` | `device-tokens.token.rotate` | none | `200 OK`, token response with `"current": true` | `400` when authenticated by cookie session rather than a bearer token; `429` when rate limited |
| `DELETE token` | `device-tokens.token.revoke` | none | `204 No Content` | `400` when authenticated by cookie session |
| `GET devices` | `device-tokens.devices.index` | none | `200 OK`, `{"data": [device, ...]}` newest first | |
| `DELETE devices/{id}` | `device-tokens.devices.destroy` | none | `204 No Content` | `404` with `{"message":"Device not found."}` when the id does not exist or belongs to another user |

The token response is `{"token", "token_type": "Bearer", "expires_at", "device"}`, where `device` has the shape below. The plain-text `token` is returned once, from login or rotation, and cannot be retrieved afterwards.

A device, as returned by every route:

| Field | Type | Meaning |
| --- | --- | --- |
| `id` | integer | The token's primary key. Use it with `DELETE devices/{id}`. |
| `name` | string | The `device_name` given at login. |
| `platform` | string or null | As given at login, for example `ios`, `android`. |
| `app_version` | string or null | As given at login. |
| `abilities` | array of strings | Sanctum abilities on the token. |
| `current` | boolean | `true` when this token authenticated the request. Always `false` in the login response, because the login request itself is unauthenticated. |
| `last_used_at` | ISO 8601 or null | Updated by Sanctum on each authenticated request. |
| `expires_at` | ISO 8601 or null | `null` only when `ttl` is `null`. |
| `created_at` | ISO 8601 | |

### `HasDeviceTokens` trait

All methods return Sanctum's own types.

`issueDeviceToken(string $deviceName, ?array $abilities = null, int|DateTimeInterface|null $ttl = null, array $metadata = []): NewAccessToken`

Deletes any token the user already has with this device name, then creates a new one; both statements run in one transaction. `$abilities` defaults to `config('device-tokens.abilities')`. `$ttl` is minutes, an absolute `DateTimeInterface`, or `null` for `config('device-tokens.ttl')`. `$metadata` accepts `platform` and `app_version`; other keys are ignored and empty values are not stored.

```php
$token = $user->issueDeviceToken(
    $request->input('device_name'),
    ['orders:read', 'orders:write'],
    60 * 24 * 30,
    ['platform' => 'ios', 'app_version' => $request->header('X-App-Version')],
);

return [
    'token' => $token->plainTextToken,
    'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
];
```

`rotateCurrentToken(int|DateTimeInterface|null $ttl = null): NewAccessToken`

Issues a token with the same device name, abilities, platform and app version as the one authenticating the current request, deletes that one, and returns the new `NewAccessToken`. Both statements run in one transaction, so a failure between them cannot leave the device with zero tokens or two. The expiry is counted from now, not from the original login. `currentAccessToken()` on the user is updated to the new token. Throws `Puneetpal\DeviceTokens\Exceptions\NoDeviceTokenException` when the request was not authenticated with a personal access token.

```php
Route::post('/auth/token/rotate', function (Request $request) {
    $fresh = $request->user()->rotateCurrentToken();

    return [
        'token' => $fresh->plainTextToken,
        'expires_at' => $fresh->accessToken->expires_at?->toIso8601String(),
    ];
})->middleware(['auth:sanctum', 'throttle:device-tokens.rotate']);
```

`deviceTokens(): MorphMany`

The user's tokens that can still authenticate, newest first. Rows past their `expires_at`, or older than Sanctum's global `expiration` setting when one is configured, are excluded. It is a relation query, so it can be chained.

`currentDeviceToken(): PersonalAccessToken`

The `PersonalAccessToken` authenticating the current request. Throws `NoDeviceTokenException` when there is none.

`revokeCurrentDeviceToken(): bool`

Deletes the token authenticating the current request. Returns `false` when the request carries no personal access token.

`revokeDeviceToken(int|string $tokenId): bool`

Deletes one of the user's tokens by id. Returns `false` when the id does not belong to this user, so a controller can answer 404 without a separate ownership query.

`revokeOtherDeviceTokens(): int`

Deletes every token except the current one and returns the number deleted. Use it for "sign out other devices".

`revokeAllDeviceTokens(): int`

Deletes every token the user has and returns the number deleted. Use it for "sign out everywhere", email changes and account recovery.

### Other classes

`Puneetpal\DeviceTokens\Exceptions\NoDeviceTokenException` extends `RuntimeException`. Thrown by `rotateCurrentToken()` and `currentDeviceToken()` when the request has no personal access token, typically because the user authenticated through Sanctum's SPA cookie flow (which attaches a `TransientToken`) or through another guard.

`Puneetpal\DeviceTokens\Listeners\RevokeTokensOnPasswordReset` calls `revokeAllDeviceTokens()` on the user carried by `Illuminate\Auth\Events\PasswordReset`. Registered automatically when `revoke_on_password_reset` is `true`; it can also be attached to your own events.

`Puneetpal\DeviceTokens\Http\Resources\DeviceResource` is the JSON resource for a single device shown above, usable in your own controllers.

### Why columns on the token table

Device metadata needs a home. Encoding it in the token `name` is fragile, because the name is the label users see on a sessions screen. A companion table keyed by token id leaves Sanctum's table untouched but makes every device list a join, every rotation two inserts, and can be orphaned whenever a token is deleted outside the package (by `tokens()->delete()`, by `sanctum:prune-expired`, or by a missing cascade). Two nullable columns on `personal_access_tokens` have none of those problems: Sanctum already publishes that migration into your application, so the table is yours to extend, and deleting, rotating or pruning a token takes its metadata with it. The package writes the columns with `forceFill()` because they are not in `PersonalAccessToken`'s fillable list.

## Security considerations

- Only SHA-256 hashes of tokens are stored. The plain text is returned once, from login or rotation, and is never logged, stored or recoverable from the database.
- Set an expiry. The default `ttl` is 30 days. With `ttl` and Sanctum's `expiration` both `null`, a token from a lost phone is valid until someone notices.
- Rotation is not a refresh token. The rotate endpoint requires a valid, unexpired token; an expired one cannot be renewed and the user logs in again. OAuth2 refresh tokens outlive the access token by design. If you need a long-lived credential that survives access token expiry, use Laravel Passport rather than raising `ttl` indefinitely.
- Rotation is rate limited to 10 per minute per user so that a captured token cannot be renewed in a tight loop. Login is rate limited to 5 per minute per username and IP and 20 per minute per IP; the per-IP ceiling is looser because mobile carriers put many users behind one address.
- Login does not reveal whether an account exists. An unknown username and a wrong password return the same 422 body. Password verification is skipped for an unknown username, so a timing difference remains, as it does with Laravel's own `Auth::attempt()`.
- Revoking a device by id answers 404 for ids that belong to other users, the same as for ids that do not exist, so token ids cannot be probed.
- Revoke on risk events. Turn on `revoke_on_password_reset` and call `revokeAllDeviceTokens()` from your email-change and account-recovery flows.
- Abilities are stored, not enforced. Enforce them on routes with Sanctum's `CheckAbilities` and `CheckForAnyAbility` middleware, which Laravel does not alias by default:

  ```php
  // bootstrap/app.php, inside withMiddleware()
  $middleware->alias([
      'abilities' => \Laravel\Sanctum\Http\Middleware\CheckAbilities::class,
      'ability' => \Laravel\Sanctum\Http\Middleware\CheckForAnyAbility::class,
  ]);
  ```

- `device_name`, `platform` and `app_version` are user-controlled input. They are length-limited and stored as given. Escape them when rendering a sessions screen in HTML.
- On the client, store the token in the iOS Keychain or Android Keystore-backed storage. Never in `UserDefaults`, plain `SharedPreferences` or AsyncStorage.
- Report vulnerabilities privately as described in [SECURITY.md](SECURITY.md).

## Troubleshooting

**401 Unauthorized immediately after a rotate.** The client is still sending the old token. Rotation deletes it in the same transaction that creates the new one, so the response body must be stored before the next request. Check for a second code path (a background refresh, a second `URLSession` or OkHttp client) that read the token into memory before the rotation and did not re-read it. Two rotations racing from one device have the same effect: the second fails with 401 because the first already deleted its token, so serialise rotation behind a lock in the client.

**401 on rotate after the app was closed for a while.** The token expired. An expired token cannot be rotated; the app returns to sign-in. Rotate earlier (the samples rotate when fewer than three days remain) or raise `ttl`.

**404 on every `/api/auth/...` route.** `routes.enabled` is `false` or the config is cached. Set `DEVICE_TOKENS_ROUTES=true` and run `php artisan config:clear` and `php artisan route:clear`. Confirm with `php artisan route:list --name=device-tokens`. If the response is a 500 with `Target class [api] does not exist`, the `api` middleware group is not defined; run `php artisan install:api` or set `routes.middleware` to the groups you do have.

**`SQLSTATE[HY000]: General error: 1 table personal_access_tokens has no column named platform`** (or the MySQL and PostgreSQL equivalents). The migration was not published or not run. Run `php artisan vendor:publish --tag=device-tokens-migrations` and `php artisan migrate`. If the migration file exists but `migrate` reports nothing to do, it was marked as run against a different database; check the `migrations` table. With a custom token model, make sure `Sanctum::usePersonalAccessTokenModel()` is called in a service provider's `register()` so the migration reads the right table name.

**400 on rotate or logout with "requires a request authenticated with a device token".** The request was authenticated by a Sanctum SPA cookie session, which attaches a `TransientToken`, not by a bearer token. There is no token to rotate or revoke. Use these endpoints from the mobile app with `Authorization: Bearer`; for the web session use Laravel's normal logout. In your own controllers the same situation surfaces as `Puneetpal\DeviceTokens\Exceptions\NoDeviceTokenException` from `rotateCurrentToken()` or `currentDeviceToken()`; catch it or check `currentAccessToken() instanceof PersonalAccessToken` first.

**429 Too Many Requests.** The response carries a `Retry-After` header in seconds. Login allows 5 attempts per minute per username and IP; rotation allows 10 per minute per user. A client that rotates on every launch or every request will hit the second limit; rotate only when the token is close to expiry. To change the limits, register limiters named `device-tokens.login` and `device-tokens.rotate` in your `AppServiceProvider::boot()`.

**`LogicException: The authenticated model must use the Puneetpal\DeviceTokens\HasDeviceTokens trait`.** The user model behind the `login.provider` provider, or the model `auth:sanctum` resolved, does not use the trait. Add it next to `HasApiTokens`.

**422 on login with correct credentials.** `login.username` must match both the request field and the column the provider queries. With `login.provider` pointing at a provider whose model is not the one that holds these users, `retrieveByCredentials` finds nothing and the response is the same 422 as a wrong password, by design.

**`expires_at` is `null` on every token.** `ttl` is `null`, or `DEVICE_TOKENS_TTL` is set to an empty value in `.env`. Set it in minutes.

## Upgrading and versioning

The package follows [Semantic Versioning](https://semver.org/). While the major version is 0, a minor release (0.2.0) may change public behaviour and will say so in [CHANGELOG.md](CHANGELOG.md) with upgrade notes; a patch release (0.1.1) does not. From 1.0.0, breaking changes happen only in major releases, deprecated behaviour is announced at least one minor release before removal, and each new Laravel major is supported in a minor release of this package while the previous Laravel major keeps its support until Laravel drops security fixes for it.

The public surface covered by this promise is: the `HasDeviceTokens` method signatures, the config keys and defaults, the route paths, names and response shapes, the migration's column names, and the exception and listener class names. Protected methods on the trait are not covered.

## Contributing

```bash
git clone https://github.com/puneetpals271/laravel-device-tokens.git
cd laravel-device-tokens
composer install
vendor/bin/phpunit
```

The suite runs on Orchestra Testbench against an in-memory SQLite database and covers issuing, per-device replacement, rotation, expiry, each route and status code, the cookie-session case, rate limits, publishing, and password-reset revocation. Pull requests should include a test for the behaviour they add or fix and keep `composer validate --strict` passing. Bugs go in [GitHub issues](https://github.com/puneetpals271/laravel-device-tokens/issues); security problems go to the address in [SECURITY.md](SECURITY.md).

## Background

This package implements the approach described in [Laravel Sanctum vs Passport for Mobile Apps (2026)](https://puneetpal.dev/insights/laravel-sanctum-vs-passport-mobile-apps-2026), which goes through the reasoning for choosing Sanctum for a first-party app, what it gives up without refresh tokens, and when Passport is the right choice instead. The wider context, including API versioning, response shape and push notifications, is in [Laravel API for Mobile Apps: Backend Design Guide](https://puneetpal.dev/insights/laravel-backend-for-mobile-apps-2026). For help applying it to your own API, see [backend API development](https://puneetpal.dev/services/backend-api-development).

## License

MIT. See [LICENSE](LICENSE).
