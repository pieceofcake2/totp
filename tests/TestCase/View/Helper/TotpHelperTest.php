<?php

App::uses('View', 'View');
App::uses('TotpHelper', 'Totp.View/Helper');
App::uses('TotpService', 'Totp.Lib');

class TotpHelperTest extends CakeTestCase
{
    protected const SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    protected TotpHelper $Totp;

    public function setUp(): void
    {
        parent::setUp();
        $this->Totp = new TotpHelper(new View(), ['issuer' => 'My App']);
    }

    public function tearDown(): void
    {
        Configure::delete('Totp');
        parent::tearDown();
    }

    public function testOtpauthUri(): void
    {
        $this->assertStringStartsWith('otpauth://totp/My%20App:mariano%40example.com?secret=' . self::SECRET, $this->Totp->otpauthUri(self::SECRET, 'mariano@example.com'));
        $this->assertStringStartsWith('otpauth://totp/Other:mariano?', $this->Totp->otpauthUri(self::SECRET, 'mariano', 'Other'));
    }

    public function testQrCode(): void
    {
        $result = $this->Totp->qrCode(self::SECRET, 'mariano@example.com');

        $this->assertMatchesRegularExpression(
            '#\A<img src="data:image/svg\+xml;base64,[A-Za-z0-9+/=]+" width="200" height="200" alt="QR code for the authenticator app" />\z#',
            $result,
        );
        preg_match('/base64,([^"]+)"/', $result, $matches);
        $svg = base64_decode($matches[1]);
        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringContainsString('width="200"', $svg);
    }

    public function testQrCodeOptions(): void
    {
        $result = $this->Totp->qrCode(self::SECRET, 'mariano', [
            'issuer' => 'Other',
            'size' => 300,
            'margin' => 4,
            'alt' => 'Scan <me>',
            'class' => 'qr',
        ]);

        $this->assertStringContainsString(' width="300" height="300" alt="Scan &lt;me&gt;" class="qr" />', $result);
        $this->assertStringNotContainsString('issuer=', $result);
        $this->assertStringNotContainsString('margin=', $result);
    }

    public function testQrCodeSvg(): void
    {
        $svg = $this->Totp->qrCodeSvg(self::SECRET, 'mariano', ['size' => 120]);

        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringContainsString('width="120"', $svg);
    }

    public function testSecret(): void
    {
        $this->assertSame('JBSW Y3DP EHPK 3PXP JBSW Y3DP EHPK 3PXP', $this->Totp->secret(self::SECRET));
        $this->assertSame('JBSW-Y3DP-EHPK-3PXP', $this->Totp->secret('JBSWY3DPEHPK3PXP', '-'));
        $this->assertSame('&lt;b&gt;', $this->Totp->secret('<b>'));
    }

    public function testSettingsFromConfigure(): void
    {
        Configure::write('Totp.issuer', 'Example');
        $helper = new TotpHelper(new View());

        $this->assertStringStartsWith('otpauth://totp/Example:mariano?', $helper->otpauthUri(self::SECRET, 'mariano'));
    }

    public function testSetService(): void
    {
        $service = new TotpService();
        $this->Totp->setService($service);

        $this->assertSame($service, $this->Totp->getService());
    }
}
