<?php

App::uses('ComponentCollection', 'Controller');
App::uses('TotpComponent', 'Totp.Controller/Component');
App::uses('TotpService', 'Totp.Lib');

class TotpComponentTest extends CakeTestCase
{
    protected const SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    protected TotpComponent $Totp;

    public function setUp(): void
    {
        parent::setUp();
        $this->Totp = new TotpComponent(new ComponentCollection(), ['issuer' => 'My App']);
    }

    public function tearDown(): void
    {
        Configure::delete('Totp');
        parent::tearDown();
    }

    public function testSettings(): void
    {
        Configure::write('Totp', ['issuer' => 'Example', 'window' => 2]);
        $service = (new TotpComponent(new ComponentCollection(), ['window' => 0]))->getService();

        $this->assertSame('Example', $service->getSetting('issuer'));
        $this->assertSame(0, $service->getSetting('window'));
    }

    public function testGenerateSecret(): void
    {
        $this->assertMatchesRegularExpression('/\A[A-Z2-7]{32}\z/', $this->Totp->generateSecret());
        $this->assertMatchesRegularExpression('/\A[A-Z2-7]{16}\z/', $this->Totp->generateSecret(16));
    }

    public function testVerify(): void
    {
        $code = $this->Totp->getService()->getCurrentCode(self::SECRET);

        $this->assertTrue($this->Totp->verify(self::SECRET, $code));
        $this->assertFalse($this->Totp->verify(self::SECRET, 'abcdef'));
        $this->assertFalse($this->Totp->verify(null, $code));
    }

    public function testOtpauthUri(): void
    {
        $this->assertStringStartsWith('otpauth://totp/My%20App:mariano?secret=' . self::SECRET, $this->Totp->otpauthUri(self::SECRET, 'mariano'));
        $this->assertStringStartsWith('otpauth://totp/Other:mariano?', $this->Totp->otpauthUri(self::SECRET, 'mariano', 'Other'));
    }

    public function testSetService(): void
    {
        $service = new TotpService();
        $this->Totp->setService($service);

        $this->assertSame($service, $this->Totp->getService());
    }
}
