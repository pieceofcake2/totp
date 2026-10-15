<?php

use PragmaRX\Google2FA\Google2FA;

App::uses('TotpService', 'Totp.Lib');

class TotpServiceTest extends CakeTestCase
{
    protected const SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    protected TotpService $service;

    public function setUp(): void
    {
        parent::setUp();
        $this->service = new TotpService();
    }

    public function tearDown(): void
    {
        Configure::delete('Totp');
        parent::tearDown();
    }

    /**
     * Code for a time step of the secret.
     */
    protected function codeAt(int $timeStep): string
    {
        return (new Google2FA())->oathTotp(self::SECRET, $timeStep);
    }

    protected function currentTimeStep(): int
    {
        return (int)floor(time() / 30);
    }

    public function testDefaultSettings(): void
    {
        $this->assertSame(['issuer' => null, 'window' => 1, 'secretLength' => 32], $this->service->getSetting());
        $this->assertSame(1, $this->service->getSetting('window'));
        $this->assertNull($this->service->getSetting('unknown'));
    }

    public function testSettingsFromConfigureAndConstructor(): void
    {
        Configure::write('Totp', ['issuer' => 'Example', 'window' => 2]);
        $service = new TotpService(['window' => 0, 'issuer' => null]);

        $this->assertSame('Example', $service->getSetting('issuer'));
        $this->assertSame(0, $service->getSetting('window'));
    }

    public function testGetGoogle2FA(): void
    {
        $google2fa = new Google2FA();
        $service = new TotpService([], $google2fa);

        $this->assertSame($google2fa, $service->getGoogle2FA());
    }

    public function testGenerateSecret(): void
    {
        $secret = $this->service->generateSecret();
        $this->assertMatchesRegularExpression('/\A[A-Z2-7]{32}\z/', $secret);
        $this->assertNotSame($secret, $this->service->generateSecret());

        $this->assertMatchesRegularExpression('/\A[A-Z2-7]{16}\z/', $this->service->generateSecret(16));
        $this->assertMatchesRegularExpression('/\A[A-Z2-7]{64}\z/', (new TotpService(['secretLength' => 64]))->generateSecret());
    }

    public function testVerifyCurrentCode(): void
    {
        $code = $this->service->getCurrentCode(self::SECRET);

        $this->assertMatchesRegularExpression('/\A\d{6}\z/', $code);
        $this->assertTrue($this->service->verify(self::SECRET, $code));
    }

    public function testVerifyIgnoresSpacesAndHyphens(): void
    {
        $code = $this->service->getCurrentCode(self::SECRET);

        $this->assertTrue($this->service->verify(self::SECRET, substr($code, 0, 3) . ' ' . substr($code, 3)));
        $this->assertTrue($this->service->verify(self::SECRET, substr($code, 0, 3) . '-' . substr($code, 3)));
    }

    public function testVerifyIntegerCode(): void
    {
        $code = $this->service->getCurrentCode(self::SECRET);
        if ($code[0] === '0') {
            $this->markTestSkipped('The current code starts with 0.');
        }

        $this->assertTrue($this->service->verify(self::SECRET, (int)$code));
    }

    public function testVerifyWindow(): void
    {
        $step = $this->currentTimeStep();

        $this->assertTrue($this->service->verify(self::SECRET, $this->codeAt($step - 1)));
        $this->assertTrue($this->service->verify(self::SECRET, $this->codeAt($step + 1)));
        $this->assertFalse($this->service->verify(self::SECRET, $this->codeAt($step - 3)));
        $this->assertFalse($this->service->verify(self::SECRET, $this->codeAt($step + 3)));
    }

    public function testVerifyInvalidInput(): void
    {
        $code = $this->service->getCurrentCode(self::SECRET);
        $wrong = str_pad((string)(((int)$code + 1) % 1000000), 6, '0', STR_PAD_LEFT);

        $this->assertFalse($this->service->verify(self::SECRET, $wrong));
        $this->assertFalse($this->service->verify(self::SECRET, null));
        $this->assertFalse($this->service->verify(self::SECRET, ''));
        $this->assertFalse($this->service->verify(self::SECRET, '12345'));
        $this->assertFalse($this->service->verify(self::SECRET, '1234567'));
        $this->assertFalse($this->service->verify(self::SECRET, 'abcdef'));
        $this->assertFalse($this->service->verify(self::SECRET, $code . "\n"));
        $this->assertFalse($this->service->verify(null, $code));
        $this->assertFalse($this->service->verify('', $code));
    }

    public function testVerifyInvalidSecret(): void
    {
        $this->assertFalse($this->service->verify('not base32!', '123456'));
        $this->assertFalse($this->service->verify('ABC', '123456'));
    }

    public function testVerifyNewer(): void
    {
        $step = $this->currentTimeStep();
        $code = $this->codeAt($step);

        $this->assertSame($step, $this->service->verifyNewer(self::SECRET, $code, null));
        $this->assertSame($step, $this->service->verifyNewer(self::SECRET, $code, $step - 1));
        $this->assertFalse($this->service->verifyNewer(self::SECRET, $code, $step));
        $this->assertFalse($this->service->verifyNewer(self::SECRET, $code, $step + 1));
    }

    public function testVerifyNewerRejectsOlderCodeAfterNewerOne(): void
    {
        $step = $this->currentTimeStep();

        $this->assertSame($step + 1, $this->service->verifyNewer(self::SECRET, $this->codeAt($step + 1), null));
        $this->assertFalse($this->service->verifyNewer(self::SECRET, $this->codeAt($step), $step + 1));
    }

    public function testOtpauthUri(): void
    {
        $uri = $this->service->otpauthUri(self::SECRET, 'mariano@example.com', 'My App');

        $this->assertSame(
            'otpauth://totp/My%20App:mariano%40example.com?secret=' . self::SECRET . '&issuer=My%20App&algorithm=SHA1&digits=6&period=30',
            $uri,
        );
    }

    public function testOtpauthUriIssuerFromSettings(): void
    {
        Configure::write('Totp.issuer', 'Example');

        $this->assertStringStartsWith('otpauth://totp/Example:mariano?', (new TotpService())->otpauthUri(self::SECRET, 'mariano'));
    }

    public function testOtpauthUriIssuerFromBaseUrl(): void
    {
        $fullBaseUrl = Router::fullBaseUrl();
        Router::fullBaseUrl('https://app.example.com');
        try {
            $this->assertStringStartsWith('otpauth://totp/app.example.com:mariano?', $this->service->otpauthUri(self::SECRET, 'mariano'));
        } finally {
            Router::fullBaseUrl($fullBaseUrl);
        }
    }
}
