# CakePHP 2 Totp Plugin

[![GitHub License](https://img.shields.io/github/license/pieceofcake2/totp?label=License)](LICENSE)
[![Packagist Version](https://img.shields.io/packagist/v/pieceofcake2/totp?label=Packagist)](https://packagist.org/packages/pieceofcake2/totp)
![PHP](https://img.shields.io/packagist/dependency-v/pieceofcake2/totp/php?logo=php&logoColor=%23FFFFFF&label=PHP&labelColor=%23777BB4&color=%23FFFFFF)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%205-brightgreen?label=PHPStan)](https://github.com/pieceofcake2/totp/actions/workflows/CI.yml)
![CakePHP](https://img.shields.io/packagist/dependency-v/pieceofcake2/totp/pieceofcake2/cakephp?logo=cakephp&logoColor=%23FFFFFF&label=CakePHP&labelColor=%23D33C43&color=%23FFFFFF)
[![CI](https://img.shields.io/github/actions/workflow/status/pieceofcake2/totp/CI.yml?label=CI)](https://github.com/pieceofcake2/totp/actions/workflows/CI.yml)
[![Codecov](https://img.shields.io/codecov/c/gh/pieceofcake2/totp?label=Coverage)](https://codecov.io/gh/pieceofcake2/totp)

Two-factor authentication for the login of [pieceofcake2/cakephp](https://github.com/pieceofcake2/cakephp)
applications with time-based one-time passwords (TOTP, RFC 6238). The codes work with any
authenticator app: Google Authenticator, Microsoft Authenticator, 1Password, Bitwarden and others.

- `TotpAuthenticate` checks the code of the authenticator app after the username and password
- `TotpComponent` generates secrets and verifies codes when a user sets up two-factor authentication
- `TotpHelper` shows the secret as a QR code (generated on the server) and as text
- Optional replay protection: each code can be used only once

## Documentation

- Website: https://pieceofcake2.github.io/totp/ (日本語: https://pieceofcake2.github.io/totp/ja/)
- Markdown: [docs/README.md](docs/README.md) (日本語: [docs/ja/README.md](docs/ja/README.md)) (for AI tools and offline reading)

It covers the configuration, the database columns, the login and setup pages, replay protection,
security notes, the API reference and the migration from `ceeram/google-authenticate`.

The Markdown documentation is also included in the Composer package, in the plugin's directory: `plugins/Totp/docs/README.md` (the location depends on your `installer-paths`).

## Requirements

* PHP 8.0+
* pieceofcake2/cakephp 2.12+
* bacon/bacon-qr-code 2.0+ (optional, for the QR code)

## Installation

```bash
composer require pieceofcake2/totp
composer require bacon/bacon-qr-code
```

Load the plugin in `config/bootstrap.php` (legacy layout: `app/Config/bootstrap.php`):

```php
CakePlugin::load('Totp');
```

See [Installation](docs/README.md#installation) for the `installer-paths` setting.

## Usage

```php
public $components = [
    'Auth' => [
        'authenticate' => [
            'Totp.Totp' => [
                'fields' => ['username' => 'email', 'code' => 'code', 'secret' => 'secret'],
                'passwordHasher' => 'Blowfish',
            ],
        ],
    ],
];
```

Users with a secret in the `secret` column must enter the current code of their authenticator
app in the `code` field of the login form. Users without a secret log in with the password only.
See [Setting Up Two-Factor Authentication](docs/README.md#setting-up-two-factor-authentication).

## Contributing

See the [contribution guidelines](https://github.com/pieceofcake2/.github/blob/master/CONTRIBUTING.md).

## Origin

This package is a maintained fork of
[ceeram/GoogleAuthenticate](https://github.com/ceeram/GoogleAuthenticate)
(`ceeram/google-authenticate` on Packagist), originally by M. Ypes (Ceeram), renamed because the
codes are standard TOTP codes. It is maintained for CakePHP 2 on modern PHP by
[pieceofcake2](https://github.com/pieceofcake2). Changes from the original are listed in the
[commit history since the fork](https://github.com/pieceofcake2/totp/compare/df25ff4...1.x).

## License

Licensed under the MIT License. See [LICENSE](LICENSE).
