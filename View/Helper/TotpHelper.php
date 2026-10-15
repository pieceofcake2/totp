<?php

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

App::uses('AppHelper', 'View/Helper');
App::uses('TotpService', 'Totp.Lib');

/**
 * Shows the secret to the user when setting up two-factor authentication: as a QR code for the
 * authenticator app, and as text for manual entry.
 *
 * The QR code is generated on the server (the secret is not sent to a third-party service) and
 * requires [bacon/bacon-qr-code](https://github.com/Bacon/BaconQrCode).
 *
 * Settings are the `TotpService` settings (`issuer`, `window`, `secretLength`). They override
 * `Configure::read('Totp')`.
 */
class TotpHelper extends AppHelper
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
     * The `otpauth://` URI that authenticator apps read from the QR code.
     *
     * @param string $secret The secret.
     * @param string $account Account name shown in the app, e.g. the user's email address.
     * @param string|null $issuer Issuer shown in the app. Defaults to the `issuer` setting.
     * @return string
     */
    public function otpauthUri(string $secret, string $account, ?string $issuer = null): string
    {
        return $this->getService()->otpauthUri($secret, $account, $issuer);
    }

    /**
     * The QR code as an `<img>` tag with an SVG data URI.
     *
     * With a Content Security Policy, allow `data:` in `img-src`.
     *
     * ### Options
     *
     * - `issuer`: Issuer shown in the app. Defaults to the `issuer` setting.
     * - `size`: Width and height in pixels (default 200).
     * - `margin`: Quiet zone around the code in modules (default 1).
     * - `alt`: Alternative text (default `QR code for the authenticator app`).
     * - Other options are HTML attributes of the `<img>` tag.
     *
     * @param string $secret The secret.
     * @param string $account Account name shown in the app, e.g. the user's email address.
     * @param array<string, mixed> $options Options and HTML attributes.
     * @return string
     */
    public function qrCode(string $secret, string $account, array $options = []): string
    {
        $options += [
            'issuer' => null,
            'size' => 200,
            'margin' => 1,
            'alt' => __d('totp', 'QR code for the authenticator app'),
        ];
        $svg = $this->qrCodeSvg($secret, $account, $options);

        $attributes = array_diff_key($options, ['issuer' => true, 'size' => true, 'margin' => true]);
        $attributes = [
            'src' => 'data:image/svg+xml;base64,' . base64_encode($svg),
            'width' => $options['size'],
            'height' => $options['size'],
        ] + $attributes;

        return '<img' . $this->_parseAttributes($attributes) . ' />';
    }

    /**
     * The QR code as an SVG document.
     *
     * ### Options
     *
     * - `issuer`: Issuer shown in the app. Defaults to the `issuer` setting.
     * - `size`: Width and height in pixels (default 200).
     * - `margin`: Quiet zone around the code in modules (default 1).
     *
     * @param string $secret The secret.
     * @param string $account Account name shown in the app, e.g. the user's email address.
     * @param array<string, mixed> $options Options.
     * @return string
     * @throws CakeException When bacon/bacon-qr-code is not installed.
     */
    public function qrCodeSvg(string $secret, string $account, array $options = []): string
    {
        if (!class_exists(Writer::class)) {
            throw new CakeException('TotpHelper::qrCode() requires bacon/bacon-qr-code: composer require bacon/bacon-qr-code');
        }
        $options += ['issuer' => null, 'size' => 200, 'margin' => 1];

        $renderer = new ImageRenderer(
            new RendererStyle((int)$options['size'], (int)$options['margin']),
            new SvgImageBackEnd(),
        );

        return (new Writer($renderer))->writeString($this->otpauthUri($secret, $account, $options['issuer']));
    }

    /**
     * The secret for manual entry, in groups of four characters, HTML-escaped.
     *
     * @param string $secret The secret.
     * @param string $separator Separator between the groups (default a space).
     * @return string
     */
    public function secret(string $secret, string $separator = ' '): string
    {
        return h(implode($separator, str_split($secret, 4)));
    }
}
