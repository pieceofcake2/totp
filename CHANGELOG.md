# CHANGELOG

## Unreleased

First release of `pieceofcake2/totp`, a maintained fork of
[ceeram/GoogleAuthenticate](https://github.com/ceeram/GoogleAuthenticate) (`ceeram/google-authenticate`).
See [Migrating from ceeram/google-authenticate](docs/README.md#migrating-from-ceeram-google-authenticate).

- Renamed the plugin from `GoogleAuthenticate` to `Totp`: the codes are standard TOTP (RFC 6238)
  and work with any authenticator app. The authenticate object is now `Totp.Totp` (`TotpAuthenticate`)
- Replaced the bundled `GoogleAuthenticator` and `FixedBitNotation` classes (Apache-2.0) with
  [pragmarx/google2fa](https://github.com/antonioribeiro/google2fa)
- Requires PHP 8.0+ and pieceofcake2/cakephp 2.12+
- `TotpAuthenticate` extends `FormAuthenticate` and supports its settings (`userFields`, `passwordHasher`, ...)
- The secret is no longer stored in the session with the user data
- Codes are compared in constant time, must be numeric, and may contain spaces or hyphens
- New `replayProtection` setting rejects a code that was already used to log in
- New `window` setting (and `Configure::write('Totp', [...])`) for the accepted clock drift
- New `TotpService` to generate secrets, verify codes and build `otpauth://` URIs
- New `TotpComponent` to set up two-factor authentication in controllers
- New `TotpHelper` renders the QR code on the server with bacon/bacon-qr-code (optional); the
  removed `getUrl()` used the discontinued Google Chart API and sent the secret to a third party
- PHPUnit tests, PHP_CodeSniffer and PHPStan (level 5) in CI
