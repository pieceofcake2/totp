<?php

use PragmaRX\Google2FA\Exceptions\Contracts\Google2FA as Google2FAException;
use PragmaRX\Google2FA\Google2FA;

App::uses('Router', 'Routing');

/**
 * Generates secrets and verifies time-based one-time passwords (TOTP, RFC 6238).
 *
 * The codes are compatible with Google Authenticator and the other authenticator apps
 * (Microsoft Authenticator, 1Password, Bitwarden, Authy, ...). The calculation is done by
 * [pragmarx/google2fa](https://github.com/antonioribeiro/google2fa).
 *
 * Settings (they override `Configure::read('Totp')`):
 *
 * - `issuer`: Name of the application or company shown in the authenticator app.
 *   Defaults to the host of the full base URL.
 * - `window`: Number of 30 second periods accepted before and after the current one,
 *   to allow for clock drift (default 1).
 * - `secretLength`: Length of new secrets in Base32 characters: 16, 32 or 64 (default 32, 160 bits).
 */
class TotpService
{
    /**
     * Default settings.
     *
     * @var array<string, mixed>
     */
    protected array $defaults = [
        'issuer' => null,
        'window' => 1,
        'secretLength' => 32,
    ];

    /**
     * Settings.
     *
     * @var array<string, mixed>
     */
    protected array $settings;

    /**
     * The TOTP implementation.
     */
    protected Google2FA $google2fa;

    /**
     * Constructor.
     *
     * @param array<string, mixed> $settings Settings. They override `Configure::read('Totp')`.
     * @param Google2FA|null $google2fa The TOTP implementation.
     */
    public function __construct(array $settings = [], ?Google2FA $google2fa = null)
    {
        $this->settings = array_merge($this->defaults, (array)Configure::read('Totp'), array_filter(
            $settings,
            fn($value) => $value !== null,
        ));
        $this->google2fa = $google2fa ?? new Google2FA();
    }

    /**
     * Get a setting, or all the settings.
     *
     * @param string|null $key Setting name. Null for all the settings.
     * @return mixed
     */
    public function getSetting(?string $key = null): mixed
    {
        if ($key === null) {
            return $this->settings;
        }

        return $this->settings[$key] ?? null;
    }

    /**
     * The TOTP implementation, for settings that this class does not cover.
     *
     * @return Google2FA
     */
    public function getGoogle2FA(): Google2FA
    {
        return $this->google2fa;
    }

    /**
     * Generate a new random secret (Base32).
     *
     * Store it with the user and show it to the user once, as a QR code (`TotpHelper::qrCode()`)
     * and as text (`TotpHelper::secret()`).
     *
     * @param int|null $length Length in Base32 characters: 16, 32 or 64. Defaults to the `secretLength` setting.
     * @return string
     */
    public function generateSecret(?int $length = null): string
    {
        return $this->google2fa->generateSecretKey($length ?? (int)$this->settings['secretLength']);
    }

    /**
     * Check a code against a secret.
     *
     * Spaces and hyphens in the code are ignored. An invalid secret is treated as a failed check.
     *
     * @param string|null $secret The user's secret.
     * @param string|int|null $code The code entered by the user.
     * @return bool
     */
    public function verify(?string $secret, string|int|null $code): bool
    {
        return $this->verifyNewer($secret, $code, null) !== false;
    }

    /**
     * Check a code and return its time step, rejecting codes not newer than `$lastTimeStep`.
     *
     * Store the returned time step with the user and pass it as `$lastTimeStep` on the next
     * login: a code that was already used cannot be used again (replay protection).
     *
     * @param string|null $secret The user's secret.
     * @param string|int|null $code The code entered by the user.
     * @param int|null $lastTimeStep The time step of the last accepted code, null if none.
     * @return int|false The time step (Unix time / 30) of the accepted code, or false.
     */
    public function verifyNewer(?string $secret, string|int|null $code, ?int $lastTimeStep): int|false
    {
        $code = $this->normalizeCode($code);
        if ($secret === null || $secret === '' || $code === null) {
            return false;
        }
        try {
            $result = $this->google2fa->verifyKeyNewer(
                $secret,
                $code,
                $lastTimeStep ?? -1,
                (int)$this->settings['window'],
            );
        } catch (Google2FAException) {
            return false;
        }

        return is_int($result) ? $result : false;
    }

    /**
     * Store the time step of an accepted code for replay protection.
     *
     * The column is updated only when it is empty or older, so that of two concurrent requests
     * with the same code only one succeeds.
     *
     * @param Model $model The user model.
     * @param string|int $id The primary key of the user.
     * @param string $field The column of the time step.
     * @param int $timeStep The time step returned by `verifyNewer()`.
     * @return bool False if the same or a newer time step was already stored.
     */
    public function saveTimeStep(Model $model, int|string $id, string $field, int $timeStep): bool
    {
        $column = $model->alias . '.' . $field;
        $model->updateAll([$column => $timeStep], [
            $model->alias . '.' . $model->primaryKey => $id,
            'OR' => [
                [$column => null],
                [$column . ' <' => $timeStep],
            ],
        ]);

        return $model->getAffectedRows() > 0;
    }

    /**
     * The current code for a secret, e.g. for tests.
     *
     * @param string $secret The secret.
     * @return string
     */
    public function getCurrentCode(string $secret): string
    {
        return $this->google2fa->getCurrentOtp($secret);
    }

    /**
     * The `otpauth://` URI that authenticator apps read from the QR code.
     *
     * @param string $secret The secret.
     * @param string $account Account name shown in the app, e.g. the user's email address.
     * @param string|null $issuer Issuer shown in the app. Defaults to the `issuer` setting.
     * @return string
     */
    public function otpauthUri(string $secret, string $account, ?string $issuer = null): string
    {
        $issuer ??= $this->settings['issuer'] ?? (string)parse_url(Router::fullBaseUrl(), PHP_URL_HOST);

        return $this->google2fa->getQRCodeUrl($issuer, $account, $secret);
    }

    /**
     * Normalize a code entered by the user.
     *
     * @param string|int|null $code The code.
     * @return string|null The digits, or null if the code is not a valid code.
     */
    protected function normalizeCode(string|int|null $code): ?string
    {
        if ($code === null) {
            return null;
        }
        $code = str_replace([' ', '-'], '', (string)$code);
        $length = $this->google2fa->getOneTimePasswordLength();
        if (!preg_match('/\A\d{' . $length . '}\z/', $code)) {
            return null;
        }

        return $code;
    }
}
