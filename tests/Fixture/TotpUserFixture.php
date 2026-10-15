<?php

class TotpUserFixture extends CakeTestFixture
{
    /**
     * Fields.
     *
     * @var array
     */
    public $fields = [
        'id' => ['type' => 'integer', 'key' => 'primary'],
        'username' => ['type' => 'string', 'null' => false],
        'email' => ['type' => 'string', 'null' => false],
        'password' => ['type' => 'string', 'null' => false],
        'secret' => ['type' => 'string', 'length' => 64, 'null' => true],
        'totp_time_step' => ['type' => 'integer', 'null' => true],
        'active' => ['type' => 'boolean', 'null' => false, 'default' => true],
    ];

    /**
     * Records. The passwords are set in the tests.
     *
     * @var array
     */
    public $records = [
        ['id' => 1, 'username' => 'mariano', 'email' => 'mariano@example.com', 'password' => '', 'secret' => 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP', 'totp_time_step' => null, 'active' => true],
        ['id' => 2, 'username' => 'nate', 'email' => 'nate@example.com', 'password' => '', 'secret' => null, 'totp_time_step' => null, 'active' => true],
        ['id' => 3, 'username' => 'larry', 'email' => 'larry@example.com', 'password' => '', 'secret' => '', 'totp_time_step' => null, 'active' => true],
        ['id' => 4, 'username' => 'garrett', 'email' => 'garrett@example.com', 'password' => '', 'secret' => 'JBSWY3DPEHPK3PXP', 'totp_time_step' => null, 'active' => false],
    ];
}
