<?php

App::uses('FormAuthenticate', 'Controller/Component/Auth');
App::uses('TotpService', 'Totp.Lib');

/**
 * An authentication adapter for AuthComponent that adds a time-based one-time password
 * (TOTP, RFC 6238) to the username and password login.
 *
 * Users with an empty secret log in with the username and password only, like `FormAuthenticate`.
 * Users with a secret must also send the current code of their authenticator app.
 *
 * ```
 * $this->Auth->authenticate = [
 *     'Totp.Totp' => [
 *         'fields' => [
 *             'username' => 'username',
 *             'password' => 'password',
 *             'code' => 'code', // field in the login form
 *             'secret' => 'totp_secret', // column of the users table
 *         ],
 *         'userModel' => 'User',
 *         'scope' => ['User.active' => 1],
 *     ],
 * ];
 * ```
 *
 * Settings, in addition to the `FormAuthenticate` settings:
 *
 * - `fields.code`: Field of the login form with the code (default `code`).
 * - `fields.secret`: Column of the user model with the secret (default `secret`).
 * - `fields.timeStep`: Column of the user model that stores the time step of the last accepted
 *   code (integer, nullable), used by `replayProtection` (default `totp_time_step`).
 * - `replayProtection`: Reject a code that was already used to log in (default false).
 *   Requires the `fields.timeStep` column.
 * - `window`, `issuer`: Settings of `TotpService`. They override `Configure::read('Totp')`.
 *
 * The secret and the time step are removed from the user data stored in the session.
 */
class TotpAuthenticate extends FormAuthenticate
{
    /**
     * Settings for this object.
     *
     * @var array
     */
    public $settings = [
        'fields' => [
            'username' => 'username',
            'password' => 'password',
            'code' => 'code',
            'secret' => 'secret',
            'timeStep' => 'totp_time_step',
        ],
        'userModel' => 'User',
        'userFields' => null,
        'scope' => [],
        'recursive' => 0,
        'contain' => null,
        'passwordHasher' => 'Simple',
        'replayProtection' => false,
        'window' => null,
    ];

    /**
     * TOTP service.
     *
     * @var TotpService|null
     */
    protected ?TotpService $service = null;

    /**
     * Constructor
     *
     * @param ComponentCollection $collection The Component collection used on this request.
     * @param array $settings Array of settings to use.
     */
    public function __construct(ComponentCollection $collection, $settings)
    {
        parent::__construct($collection, $settings);

        // Make sure the secret and the time step are read when only some fields are selected.
        if ($this->settings['userFields'] !== null) {
            [, $model] = pluginSplit($this->settings['userModel']);
            $this->settings['userFields'][] = $model . '.' . $this->settings['fields']['secret'];
            if ($this->settings['replayProtection']) {
                $this->settings['userFields'][] = $model . '.' . $this->settings['fields']['timeStep'];
            }
        }
    }

    /**
     * The TOTP service.
     *
     * @return TotpService
     */
    public function getService(): TotpService
    {
        if ($this->service === null) {
            $this->service = new TotpService(['window' => $this->settings['window']]);
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
     * Authenticates the identity contained in a request: the username and password, and the code
     * when the user has a secret.
     *
     * @param CakeRequest $request The request that contains login information.
     * @param CakeResponse $response Unused response object.
     * @return array|false False on login failure. An array of User data on success.
     */
    public function authenticate(CakeRequest $request, CakeResponse $response)
    {
        [, $model] = pluginSplit($this->settings['userModel']);
        $fields = $this->settings['fields'];

        $user = parent::authenticate($request, $response);
        if (!$user) {
            return false;
        }

        $secret = $user[$fields['secret']] ?? null;
        $lastTimeStep = $user[$fields['timeStep']] ?? null;
        unset($user[$fields['secret']], $user[$fields['timeStep']]);

        if ($secret === null || $secret === '') {
            return $user;
        }

        $code = $request->data($model . '.' . $fields['code']);
        if (!is_string($code) && !is_int($code)) {
            return false;
        }

        if (!$this->settings['replayProtection']) {
            return $this->getService()->verify($secret, $code) ? $user : false;
        }

        $timeStep = $this->getService()->verifyNewer(
            $secret,
            $code,
            $lastTimeStep === null ? null : (int)$lastTimeStep,
        );
        if ($timeStep === false || !$this->_saveTimeStep($user, $timeStep)) {
            return false;
        }

        return $user;
    }

    /**
     * Store the time step of the accepted code. Fails when a concurrent request already stored
     * the same or a newer time step, so that a code can be used only once.
     *
     * @param array $user The user data.
     * @param int $timeStep The time step of the accepted code.
     * @return bool
     */
    protected function _saveTimeStep(array $user, int $timeStep): bool
    {
        $userModel = $this->settings['userModel'];
        [, $model] = pluginSplit($userModel);
        $column = $model . '.' . $this->settings['fields']['timeStep'];

        /** @var Model $User */
        $User = ClassRegistry::init($userModel);
        if (!isset($user[$User->primaryKey])) {
            throw new CakeException(sprintf('The user data does not contain the primary key "%s".', $User->primaryKey));
        }
        $User->updateAll([$column => $timeStep], [
            $model . '.' . $User->primaryKey => $user[$User->primaryKey],
            'OR' => [
                [$column => null],
                [$column . ' <' => $timeStep],
            ],
        ]);

        return $User->getAffectedRows() > 0;
    }
}
