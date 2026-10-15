<?php

use PragmaRX\Google2FA\Google2FA;

App::uses('Controller', 'Controller');
App::uses('ComponentCollection', 'Controller');
App::uses('TotpComponent', 'Totp.Controller/Component');
App::uses('TotpService', 'Totp.Lib');

class TotpComponentTestController extends Controller
{
    public $uses = [];
}

class TotpComponentTest extends CakeTestCase
{
    protected const SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    public $fixtures = ['plugin.totp.totp_user'];

    protected TotpComponentTestController $Controller;

    protected TotpComponent $Totp;

    public function setUp(): void
    {
        parent::setUp();
        $User = ClassRegistry::init('TotpUser');
        $User->updateAll(['password' => $User->getDataSource()->value(Security::hash('password', null, true))]);
        AuthComponent::$sessionKey = 'Auth.User';
        $this->Totp = $this->createComponent(['issuer' => 'My App']);
    }

    public function tearDown(): void
    {
        CakeSession::delete('Auth');
        CakeSession::delete('Totp');
        Configure::delete('Totp');
        parent::tearDown();
    }

    protected function createComponent(array $settings = [], array $authenticate = []): TotpComponent
    {
        $this->Controller = new TotpComponentTestController(new CakeRequest('users/login', false), new CakeResponse());
        $this->Controller->components = [
            'Session',
            'Auth' => ['authenticate' => ['Form' => $authenticate + ['userModel' => 'TotpUser']]],
            'Totp.Totp' => $settings + ['userModel' => 'TotpUser'],
        ];
        $this->Controller->constructClasses();
        $this->Controller->Components->init($this->Controller);

        return $this->Controller->Totp;
    }

    protected function post(array $data): void
    {
        $this->Controller->request->data = ['TotpUser' => $data];
    }

    protected function code(int $offset = 0): string
    {
        return (new Google2FA())->oathTotp(self::SECRET, (int)floor(time() / 30) + $offset);
    }

    protected function loggedInUser(): ?array
    {
        return CakeSession::read('Auth.User');
    }

    public function testSettings(): void
    {
        Configure::write('Totp', ['issuer' => 'Example', 'window' => 2]);
        $service = $this->createComponent(['window' => 0, 'issuer' => null])->getService();

        $this->assertSame('Example', $service->getSetting('issuer'));
        $this->assertSame(0, $service->getSetting('window'));
        $this->assertSame(['secret' => 'secret', 'timeStep' => 'totp_time_step'], $this->Totp->settings['fields']);
    }

    public function testGenerateSecret(): void
    {
        $this->assertMatchesRegularExpression('/\A[A-Z2-7]{32}\z/', $this->Totp->generateSecret());
        $this->assertMatchesRegularExpression('/\A[A-Z2-7]{16}\z/', $this->Totp->generateSecret(16));
    }

    public function testVerify(): void
    {
        $this->assertTrue($this->Totp->verify(self::SECRET, $this->code()));
        $this->assertFalse($this->Totp->verify(self::SECRET, 'abcdef'));
        $this->assertFalse($this->Totp->verify(null, $this->code()));
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

    public function testLoginWrongPassword(): void
    {
        $this->post(['username' => 'mariano', 'password' => 'wrong']);

        $this->assertSame(TotpComponent::FAILED, $this->Totp->login());
        $this->assertFalse($this->Totp->hasPendingLogin());
        $this->assertNull($this->loggedInUser());
    }

    public function testLoginWithoutSecret(): void
    {
        $this->post(['username' => 'nate', 'password' => 'password']);

        $this->assertSame(TotpComponent::LOGGED_IN, $this->Totp->login());
        $this->assertFalse($this->Totp->hasPendingLogin());
        $this->assertSame('nate', $this->loggedInUser()['username']);
        $this->assertArrayNotHasKey('secret', $this->loggedInUser());
    }

    public function testLoginWithSecretRequiresCode(): void
    {
        $this->post(['username' => 'mariano', 'password' => 'password']);

        $this->assertSame(TotpComponent::CODE_REQUIRED, $this->Totp->login());
        $this->assertNull($this->loggedInUser(), 'The user must not be logged in before the code.');
        $this->assertTrue($this->Totp->hasPendingLogin());
        $this->assertSame('mariano', $this->Totp->getPendingUser()['username']);
        $this->assertArrayNotHasKey('secret', $this->Totp->getPendingUser());
        $this->assertArrayNotHasKey('totp_time_step', $this->Totp->getPendingUser());
    }

    public function testLoginDecidesBySecretInDatabase(): void
    {
        // userFields without the secret must not skip the second step
        $totp = $this->createComponent([], ['userFields' => ['TotpUser.id', 'TotpUser.username']]);
        $this->post(['username' => 'mariano', 'password' => 'password']);

        $this->assertSame(TotpComponent::CODE_REQUIRED, $totp->login());
    }

    public function testLoginWithoutPrimaryKey(): void
    {
        $totp = $this->createComponent([], ['userFields' => ['TotpUser.username']]);
        $this->post(['username' => 'mariano', 'password' => 'password']);

        $this->expectException(CakeException::class);
        $totp->login();
    }

    public function testVerifyLogin(): void
    {
        $this->post(['username' => 'mariano', 'password' => 'password']);
        $this->Totp->login();

        $this->assertTrue($this->Totp->verifyLogin($this->code()));
        $this->assertSame('mariano', $this->loggedInUser()['username']);
        $this->assertArrayNotHasKey('secret', $this->loggedInUser());
        $this->assertFalse($this->Totp->hasPendingLogin());
    }

    public function testVerifyLoginWithoutPendingLogin(): void
    {
        $this->assertFalse($this->Totp->verifyLogin($this->code()));
        $this->assertNull($this->Totp->getPendingUser());
        $this->assertNull($this->loggedInUser());
    }

    public function testVerifyLoginWrongCode(): void
    {
        $this->post(['username' => 'mariano', 'password' => 'password']);
        $this->Totp->login();

        $this->assertFalse($this->Totp->verifyLogin('abcdef'));
        $this->assertFalse($this->Totp->verifyLogin($this->code(5)));
        $this->assertNull($this->loggedInUser());
        $this->assertTrue($this->Totp->hasPendingLogin());
        $this->assertSame(2, CakeSession::read('Totp.pending.attempts'));

        $this->assertTrue($this->Totp->verifyLogin($this->code()));
    }

    public function testVerifyLoginMaxAttempts(): void
    {
        $totp = $this->createComponent(['maxAttempts' => 3]);
        $this->post(['username' => 'mariano', 'password' => 'password']);
        $totp->login();

        $this->assertFalse($totp->verifyLogin('000000'));
        $this->assertFalse($totp->verifyLogin('000000'));
        $this->assertTrue($totp->hasPendingLogin());
        $this->assertFalse($totp->verifyLogin('000000'));
        $this->assertFalse($totp->hasPendingLogin(), 'The password must be entered again.');
        $this->assertFalse($totp->verifyLogin($this->code()));
        $this->assertNull($this->loggedInUser());
    }

    public function testVerifyLoginExpired(): void
    {
        $this->post(['username' => 'mariano', 'password' => 'password']);
        $this->Totp->login();
        CakeSession::write('Totp.pending.expires', time() - 1);

        $this->assertFalse($this->Totp->hasPendingLogin());
        $this->assertNull(CakeSession::read('Totp.pending'));
        $this->assertFalse($this->Totp->verifyLogin($this->code()));
    }

    public function testVerifyLoginScope(): void
    {
        $totp = $this->createComponent(['scope' => ['TotpUser.active' => true]]);
        $this->post(['username' => 'mariano', 'password' => 'password']);
        $totp->login();
        ClassRegistry::init('TotpUser')->updateAll(['active' => false], ['id' => 1]);

        $this->assertFalse($totp->verifyLogin($this->code()));
        $this->assertFalse($totp->hasPendingLogin());
        $this->assertNull($this->loggedInUser());
    }

    public function testVerifyLoginSecretRemovedMeanwhile(): void
    {
        $this->post(['username' => 'mariano', 'password' => 'password']);
        $this->Totp->login();
        ClassRegistry::init('TotpUser')->updateAll(['secret' => null], ['id' => 1]);

        $this->assertTrue($this->Totp->verifyLogin(null));
        $this->assertSame('mariano', $this->loggedInUser()['username']);
    }

    public function testVerifyLoginReplayProtection(): void
    {
        $totp = $this->createComponent(['replayProtection' => true]);
        $code = $this->code();

        $this->post(['username' => 'mariano', 'password' => 'password']);
        $totp->login();
        $this->assertTrue($totp->verifyLogin($code));
        $this->assertGreaterThanOrEqual((int)floor(time() / 30) - 1, (int)ClassRegistry::init('TotpUser')->field('totp_time_step', ['id' => 1]));

        CakeSession::delete('Auth');
        $totp->login();
        $this->assertFalse($totp->verifyLogin($code), 'A used code must be rejected.');
        $this->assertNull($this->loggedInUser());
    }

    public function testCancelLogin(): void
    {
        $this->post(['username' => 'mariano', 'password' => 'password']);
        $this->Totp->login();
        $this->Totp->cancelLogin();

        $this->assertFalse($this->Totp->hasPendingLogin());
        $this->assertFalse($this->Totp->verifyLogin($this->code()));
    }

    public function testLoginCancelsPreviousPendingLogin(): void
    {
        $this->post(['username' => 'mariano', 'password' => 'password']);
        $this->Totp->login();
        $this->post(['username' => 'mariano', 'password' => 'wrong']);

        $this->assertSame(TotpComponent::FAILED, $this->Totp->login());
        $this->assertFalse($this->Totp->hasPendingLogin());
    }
}
