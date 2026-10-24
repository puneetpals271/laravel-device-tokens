# Security policy

## Supported versions

Security fixes are released for the latest minor version of the package. Older
minor versions receive fixes only when upgrading is not reasonable for most
users, which will be stated in the release notes.

## Reporting a vulnerability

Please do not open a public issue for a security problem.

Email puneet@wedevelopers.us with:

- a description of the issue and its impact,
- the package version and Laravel version,
- steps or a small test case that reproduces it.

You will receive an acknowledgement within three working days. Once the issue
is confirmed, a fix is prepared, released, and credited to you in the
CHANGELOG unless you ask otherwise. Please allow up to 90 days for a fix before
disclosing publicly.

## Scope

Issues in this package's own code: the `HasDeviceTokens` trait, the optional
routes and controllers, the rate limiters, the migration and the listener.
Problems in Laravel or Sanctum themselves should be reported to the Laravel
team at https://laravel.com/docs/contributions#security-vulnerabilities.
