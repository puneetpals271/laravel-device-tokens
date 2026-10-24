# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## 0.1.0 - 2026-10-24

First release.

### Added

- `HasDeviceTokens` trait: `issueDeviceToken()` (one token per device name, replaces the previous one), `rotateCurrentToken()` (same device, abilities and metadata, old token deleted in the same transaction), `deviceTokens()`, `currentDeviceToken()`, `revokeCurrentDeviceToken()`, `revokeDeviceToken()`, `revokeOtherDeviceTokens()`, `revokeAllDeviceTokens()`.
- Nullable `platform` and `app_version` columns on `personal_access_tokens`, published as a migration.
- Opt-in routes: `POST token`, `POST token/rotate`, `DELETE token`, `GET devices`, `DELETE devices/{id}`, with named rate limiters for login and rotation.
- Opt-in listener that revokes every token on `Illuminate\Auth\Events\PasswordReset`.
- Config file `device-tokens.php` with token ttl, default abilities, login provider and username field, route prefix and middleware.
- Support for PHP 8.2, 8.3 and 8.4, Laravel 12 and 13 (13 requires PHP 8.3+), and Sanctum 4.
