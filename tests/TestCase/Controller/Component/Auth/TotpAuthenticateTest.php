<?php

use PragmaRX\Google2FA\Google2FA;

App::uses('ComponentCollection', 'Controller');
App::uses('TotpAuthenticate', 'Totp.Controller/Component/Auth');
App::uses('TotpService', 'Totp.Lib');

class TotpAuthenticateTest extends CakeTestCase
{
    protected const SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    public $fixtures = ['plugin.totp.totp_user'];

    protected TotpAuthenticate $auth;

    protected CakeResponse $response;

    public function setUp(): void
    {
        parent::setUp();
        $User = ClassRegistry::init('TotpUser');
        $User->updateAll(['password' => $User->getDataSource()->value(Security::hash('password', null, true))]);
        $this->auth = $this->createAuth();
        $this->response = new CakeResponse();
    }

    public function tearDown(): void
    {
        Configure::delete('Totp');
        parent::tearDown();
    }

    protected function createAuth(array $settings = []): TotpAuthenticate
    {
        return new TotpAuthenticate(new ComponentCollection(), $settings + ['userModel' => 'TotpUser']);
    }

    protected function request(array $data): CakeRequest
    {
        $request = new CakeRequest('users/login', false);
        $request->data = ['TotpUser' => $data];

        return $request;
    }

    protected function currentTimeStep(): int
    {
        return (int)floor(time() / 30);
    }

    protected function code(string $secret = self::SECRET): string
    {
        return (new Google2FA())->getCurrentOtp($secret);
    }

    public function testAuthenticateNoData(): void
    {
        $this->assertFalse($this->auth->authenticate(new CakeRequest('users/login', false), $this->response));
    }

    public function testAuthenticateWrongPassword(): void
    {
        $request = $this->request(['username' => 'mariano', 'password' => 'wrong', 'code' => $this->code()]);

        $this->assertFalse($this->auth->authenticate($request, $this->response));
    }

    public function testAuthenticateUnknownUser(): void
    {
        $request = $this->request(['username' => 'unknown', 'password' => 'password', 'code' => '123456']);

        $this->assertFalse($this->auth->authenticate($request, $this->response));
    }

    public function testAuthenticateWithCode(): void
    {
        $request = $this->request(['username' => 'mariano', 'password' => 'password', 'code' => $this->code()]);
        $result = $this->auth->authenticate($request, $this->response);

        $this->assertSame(['id', 'username', 'email', 'active'], array_keys($result));
        $this->assertSame('mariano', $result['username']);
    }

    public function testAuthenticateMissingCode(): void
    {
        $this->assertFalse($this->auth->authenticate($this->request(['username' => 'mariano', 'password' => 'password']), $this->response));
        $this->assertFalse($this->auth->authenticate($this->request(['username' => 'mariano', 'password' => 'password', 'code' => '']), $this->response));
        $this->assertFalse($this->auth->authenticate($this->request(['username' => 'mariano', 'password' => 'password', 'code' => ['123456']]), $this->response));
    }

    public function testAuthenticateWrongCode(): void
    {
        $code = $this->code();
        $wrong = str_pad((string)(((int)$code + 1) % 1000000), 6, '0', STR_PAD_LEFT);
        $request = $this->request(['username' => 'mariano', 'password' => 'password', 'code' => $wrong]);

        $this->assertFalse($this->auth->authenticate($request, $this->response));
    }

    public function testAuthenticateWithoutSecret(): void
    {
        $result = $this->auth->authenticate($this->request(['username' => 'nate', 'password' => 'password']), $this->response);
        $this->assertSame('nate', $result['username']);
        $this->assertArrayNotHasKey('secret', $result);

        $result = $this->auth->authenticate($this->request(['username' => 'larry', 'password' => 'password']), $this->response);
        $this->assertSame('larry', $result['username']);
    }

    public function testAuthenticateScope(): void
    {
        $auth = $this->createAuth(['scope' => ['TotpUser.active' => true]]);
        $request = $this->request(['username' => 'garrett', 'password' => 'password', 'code' => $this->code('JBSWY3DPEHPK3PXP')]);

        $this->assertFalse($auth->authenticate($request, $this->response));
    }

    public function testAuthenticateCustomFields(): void
    {
        $auth = $this->createAuth(['fields' => ['username' => 'email', 'code' => 'otp']]);
        $request = $this->request(['email' => 'mariano@example.com', 'password' => 'password', 'otp' => $this->code()]);

        $this->assertSame('mariano', $auth->authenticate($request, $this->response)['username']);
    }

    public function testAuthenticateUserFields(): void
    {
        $auth = $this->createAuth(['userFields' => ['TotpUser.id', 'TotpUser.username']]);
        $request = $this->request(['username' => 'mariano', 'password' => 'password', 'code' => '000000']);
        $this->assertFalse($auth->authenticate($request, $this->response));

        $request = $this->request(['username' => 'mariano', 'password' => 'password', 'code' => $this->code()]);
        $this->assertEquals(['id' => 1, 'username' => 'mariano'], $auth->authenticate($request, $this->response));
    }

    public function testAuthenticateWindowSetting(): void
    {
        $previous = (new Google2FA())->oathTotp(self::SECRET, $this->currentTimeStep() - 1);
        $request = $this->request(['username' => 'mariano', 'password' => 'password', 'code' => $previous]);

        $this->assertNotFalse($this->auth->authenticate($request, $this->response));
        $this->assertFalse($this->createAuth(['window' => 0])->authenticate($request, $this->response));
    }

    public function testAuthenticateReplayAllowedByDefault(): void
    {
        $request = $this->request(['username' => 'mariano', 'password' => 'password', 'code' => $this->code()]);

        $this->assertNotFalse($this->auth->authenticate($request, $this->response));
        $this->assertNotFalse($this->auth->authenticate($request, $this->response));
        $this->assertNull(ClassRegistry::init('TotpUser')->field('totp_time_step', ['id' => 1]));
    }

    public function testAuthenticateReplayProtection(): void
    {
        $auth = $this->createAuth(['replayProtection' => true]);
        $request = $this->request(['username' => 'mariano', 'password' => 'password', 'code' => $this->code()]);

        $result = $auth->authenticate($request, $this->response);
        $this->assertSame('mariano', $result['username']);
        $this->assertArrayNotHasKey('totp_time_step', $result);
        $this->assertGreaterThanOrEqual($this->currentTimeStep() - 1, (int)ClassRegistry::init('TotpUser')->field('totp_time_step', ['id' => 1]));

        $this->assertFalse($auth->authenticate($request, $this->response), 'A used code must be rejected.');
    }

    public function testAuthenticateReplayProtectionRejectsOlderCode(): void
    {
        $step = $this->currentTimeStep();
        ClassRegistry::init('TotpUser')->updateAll(['totp_time_step' => $step + 1], ['id' => 1]);
        $auth = $this->createAuth(['replayProtection' => true]);
        $request = $this->request(['username' => 'mariano', 'password' => 'password', 'code' => $this->code()]);

        $this->assertFalse($auth->authenticate($request, $this->response));
    }

    public function testAuthenticateReplayProtectionWithUserFields(): void
    {
        $auth = $this->createAuth(['replayProtection' => true, 'userFields' => ['TotpUser.id', 'TotpUser.username']]);
        $request = $this->request(['username' => 'mariano', 'password' => 'password', 'code' => $this->code()]);

        $this->assertEquals(['id' => 1, 'username' => 'mariano'], $auth->authenticate($request, $this->response));
        $this->assertFalse($auth->authenticate($request, $this->response));
    }

    public function testAuthenticateReplayProtectionWithoutPrimaryKey(): void
    {
        $auth = $this->createAuth(['replayProtection' => true, 'userFields' => ['TotpUser.username']]);
        $request = $this->request(['username' => 'mariano', 'password' => 'password', 'code' => $this->code()]);

        $this->expectException(CakeException::class);
        $auth->authenticate($request, $this->response);
    }

    public function testGetAndSetService(): void
    {
        Configure::write('Totp.window', 3);
        $this->assertSame(3, $this->auth->getService()->getSetting('window'));
        $this->assertSame(0, $this->createAuth(['window' => 0])->getService()->getSetting('window'));

        $service = new TotpService();
        $this->auth->setService($service);
        $this->assertSame($service, $this->auth->getService());
    }
}
