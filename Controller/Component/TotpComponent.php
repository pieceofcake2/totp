<?php

App::uses('Component', 'Controller');
App::uses('TotpService', 'Totp.Lib');

/**
 * Generates secrets and verifies codes in controllers, e.g. to set up two-factor authentication
 * for a user (confirm the first code before storing the secret).
 *
 * Settings are the `TotpService` settings (`issuer`, `window`, `secretLength`). They override
 * `Configure::read('Totp')`.
 */
class TotpComponent extends Component
{
    /**
     * TOTP service.
     */
    protected ?TotpService $service = null;

    /**
     * The TOTP service.
     *
     * @return TotpService
     */
    public function getService(): TotpService
    {
        if ($this->service === null) {
            $this->service = new TotpService($this->settings);
        }

        return $this->service;
    }

    /**
     * Set the TOTP service.
     *
     * @param TotpService $service The service.
     * @return void
     */
    public function setService(TotpService $service): void
    {
        $this->service = $service;
    }

    /**
     * Generate a new random secret (Base32).
     *
     * @param int|null $length Length in Base32 characters: 16, 32 or 64. Defaults to the `secretLength` setting.
     * @return string
     * @see TotpService::generateSecret()
     */
    public function generateSecret(?int $length = null): string
    {
        return $this->getService()->generateSecret($length);
    }

    /**
     * Check a code against a secret.
     *
     * @param string|null $secret The secret.
     * @param string|int|null $code The code entered by the user.
     * @return bool
     * @see TotpService::verify()
     */
    public function verify(?string $secret, string|int|null $code): bool
    {
        return $this->getService()->verify($secret, $code);
    }

    /**
     * The `otpauth://` URI that authenticator apps read from the QR code.
     *
     * @param string $secret The secret.
     * @param string $account Account name shown in the app, e.g. the user's email address.
     * @param string|null $issuer Issuer shown in the app. Defaults to the `issuer` setting.
     * @return string
     * @see TotpService::otpauthUri()
     */
    public function otpauthUri(string $secret, string $account, ?string $issuer = null): string
    {
        return $this->getService()->otpauthUri($secret, $account, $issuer);
    }
}
