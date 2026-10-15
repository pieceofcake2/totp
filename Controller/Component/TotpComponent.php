<?php

App::uses('Component', 'Controller');
App::uses('TotpService', 'Totp.Lib');

/**
 * Two-step login with a time-based one-time password, and helpers to set up two-factor
 * authentication.
 *
 * Two-step login: `login()` checks the username and password with the authenticate objects of
 * `AuthComponent` (for example `Form`). A user without a secret is logged in. For a user with a
 * secret, the login is kept pending in the session until `verifyLogin()` receives the current code
 * of the authenticator app on a second page.
 *
 * Settings, in addition to the `TotpService` settings (`issuer`, `window`, `secretLength`,
 * which override `Configure::read('Totp')`):
 *
 * - `userModel`: The user model (default `User`).
 * - `fields`: `secret` (default `secret`) and `timeStep` (default `totp_time_step`) columns.
 * - `scope`: Conditions the user must still match when the code is entered.
 * - `replayProtection`: Accept each code only once (default false). Requires the `timeStep` column.
 * - `expires`: Seconds the pending login is valid (default 300).
 * - `maxAttempts`: Wrong codes allowed before the user must enter the password again (default 5).
 * - `sessionKey`: Session key of the pending login (default `Totp.pending`).
 *
 * @property AuthComponent $Auth
 * @property SessionComponent $Session
 */
class TotpComponent extends Component
{
    /**
     * `login()` result: the user is logged in (no second step).
     */
    public const LOGGED_IN = 'loggedIn';

    /**
     * `login()` result: the password is correct and the code must be entered on the second step.
     */
    public const CODE_REQUIRED = 'codeRequired';

    /**
     * `login()` result: the username or the password is wrong.
     */
    public const FAILED = 'failed';

    public $components = ['Auth', 'Session'];

    /**
     * Default settings.
     *
     * @var array<string, mixed>
     */
    protected array $defaults = [
        'userModel' => 'User',
        'fields' => [
            'secret' => 'secret',
            'timeStep' => 'totp_time_step',
        ],
        'scope' => [],
        'replayProtection' => false,
        'expires' => 300,
        'maxAttempts' => 5,
        'sessionKey' => 'Totp.pending',
    ];

    /**
     * TOTP service.
     */
    protected ?TotpService $service = null;

    /**
     * Constructor.
     *
     * @param ComponentCollection $collection The component collection.
     * @param array<string, mixed> $settings Settings.
     */
    public function __construct(ComponentCollection $collection, $settings = [])
    {
        parent::__construct($collection, Hash::merge($this->defaults, (array)$settings));
    }

    /**
     * The TOTP service.
     *
     * @return TotpService
     */
    public function getService(): TotpService
    {
        if ($this->service === null) {
            $this->service = new TotpService(array_intersect_key(
                $this->settings,
                ['issuer' => true, 'window' => true, 'secretLength' => true],
            ));
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

    /**
     * First step of the login: check the username and password with the authenticate objects of
     * `AuthComponent`.
     *
     * @return string `LOGGED_IN`, `CODE_REQUIRED` or `FAILED`.
     */
    public function login(): string
    {
        $this->cancelLogin();
        $controller = $this->_Collection->getController();
        $user = $this->Auth->identify($controller->request, $controller->response);
        if (!$user) {
            return static::FAILED;
        }

        $model = $this->_model();
        $id = $user[$model->primaryKey] ?? null;
        if ($id === null) {
            throw new CakeException(sprintf('The user data does not contain the primary key "%s".', $model->primaryKey));
        }
        unset($user[$this->settings['fields']['secret']], $user[$this->settings['fields']['timeStep']]);

        $secret = $this->_findTotp($id)[$this->settings['fields']['secret']] ?? null;
        if ($secret === null || $secret === '') {
            $this->Auth->login($user);

            return static::LOGGED_IN;
        }

        $this->Session->write($this->settings['sessionKey'], [
            'user' => $user,
            'expires' => time() + (int)$this->settings['expires'],
            'attempts' => 0,
        ]);

        return static::CODE_REQUIRED;
    }

    /**
     * Second step of the login: check the code and log the user in.
     *
     * After `maxAttempts` wrong codes or when the pending login has expired, the pending login is
     * cancelled and the user must enter the password again (`hasPendingLogin()` returns false).
     *
     * @param string|int|null $code The code entered by the user.
     * @return bool True if the user is logged in.
     */
    public function verifyLogin(string|int|null $code): bool
    {
        $pending = $this->_pending();
        if ($pending === null) {
            return false;
        }

        $model = $this->_model();
        $id = $pending['user'][$model->primaryKey];
        $totp = $this->_findTotp($id);
        if ($totp === null) {
            $this->cancelLogin();

            return false;
        }

        $secret = $totp[$this->settings['fields']['secret']] ?? null;
        if ($secret !== null && $secret !== '' && !$this->_checkCode($model, $id, $secret, $totp, $code)) {
            $pending['attempts']++;
            if ($pending['attempts'] >= (int)$this->settings['maxAttempts']) {
                $this->cancelLogin();
            } else {
                $this->Session->write($this->settings['sessionKey'], $pending);
            }

            return false;
        }

        $this->cancelLogin();

        return $this->Auth->login($pending['user']);
    }

    /**
     * Whether a login is waiting for the code (and has not expired).
     *
     * @return bool
     */
    public function hasPendingLogin(): bool
    {
        return $this->_pending() !== null;
    }

    /**
     * The user data of the pending login, e.g. to show the username on the second page.
     *
     * @return array|null
     */
    public function getPendingUser(): ?array
    {
        return $this->_pending()['user'] ?? null;
    }

    /**
     * Cancel the pending login.
     *
     * @return void
     */
    public function cancelLogin(): void
    {
        $this->Session->delete($this->settings['sessionKey']);
    }

    /**
     * The pending login, or null if there is none or it has expired.
     *
     * @return array|null
     */
    protected function _pending(): ?array
    {
        $pending = $this->Session->read($this->settings['sessionKey']);
        if (!is_array($pending) || !isset($pending['user'], $pending['expires'])) {
            return null;
        }
        if ($pending['expires'] < time()) {
            $this->cancelLogin();

            return null;
        }

        return $pending;
    }

    /**
     * Check the code, with replay protection if enabled.
     *
     * @param Model $model The user model.
     * @param string|int $id The primary key of the user.
     * @param string $secret The secret.
     * @param array $totp The secret and time step columns of the user.
     * @param string|int|null $code The code.
     * @return bool
     */
    protected function _checkCode(Model $model, int|string $id, string $secret, array $totp, string|int|null $code): bool
    {
        if (!$this->settings['replayProtection']) {
            return $this->getService()->verify($secret, $code);
        }
        $field = $this->settings['fields']['timeStep'];
        $lastTimeStep = $totp[$field] ?? null;
        $timeStep = $this->getService()->verifyNewer($secret, $code, $lastTimeStep === null ? null : (int)$lastTimeStep);

        return $timeStep !== false && $this->getService()->saveTimeStep($model, $id, $field, $timeStep);
    }

    /**
     * Read the secret and time step columns of a user that matches the scope.
     *
     * @param string|int $id The primary key of the user.
     * @return array|null Null if the user does not exist or does not match the scope.
     */
    protected function _findTotp(int|string $id): ?array
    {
        $model = $this->_model();
        $fields = [$model->alias . '.' . $this->settings['fields']['secret']];
        if ($this->settings['replayProtection']) {
            $fields[] = $model->alias . '.' . $this->settings['fields']['timeStep'];
        }
        $result = $model->find('first', [
            'conditions' => [$model->alias . '.' . $model->primaryKey => $id] + (array)$this->settings['scope'],
            'fields' => $fields,
            'recursive' => -1,
        ]);

        return $result[$model->alias] ?? null;
    }

    /**
     * The user model.
     *
     * @return Model
     * @throws CakeException When the user model is not a Model.
     */
    protected function _model(): Model
    {
        $model = ClassRegistry::init($this->settings['userModel']);
        if (!$model instanceof Model) {
            throw new CakeException(sprintf('%s is not a Model.', $this->settings['userModel']));
        }

        return $model;
    }
}
