<?php //-->
/**
 * This file is part of the Eden PHP Library.
 * (c) 2014-2016 Openovate Labs
 *
 * Copyright and license information can be found at LICENSE.txt
 * distributed with this package.
 */

namespace Bladeroot\Mail;

/**
 * Base Class
 *
 * @vendor   Eden
 * @package  Mail
 * @author   Christian Blanquera <cblanquera@openovate.com>
 * @standard PSR-2
 */
abstract class Server extends Base
{
    /**
     * @const int TIMEOUT Connection timeout
     */
    const TIMEOUT = 30;

    /**
     * @const string NO_SUBJECT Default subject
     */
    const NO_SUBJECT = '(no subject)';

    /**
     * @var string $host The server Host
     */
    protected $host = null;

    /**
     * @var string|null $port The server port
     */
    protected $port = null;

    /**
     * @var array $ports The Server ports
     */
    protected $ports = [];


    /**
     * @var bool $ssl Whether to use SSL
     */
    protected $ssl = false;

    /**
     * @var bool $tls Whether to use TLS
     */
    protected $tls = false;

    /**
     * @var string|null $username The mailbox user name
     */
    protected $username = null;

    /**
     * @var string|null $password The mailbox password
     */
    protected $password = null;

    /**
     * @var [RESOURCE] $socket The socket connection
     */
    protected $socket = null;

    /**
     * @var bool $debugging If true outputs the logs
     */
    protected $debugging = false;

    /**
     * Constructor - Store connection information
     *
     * @param *string   $host       The Server host
     * @param *string   $user       The mailbox user name
     * @param *string   $pass       The mailbox password
     * @param int|null  $port       The Server port
     * @param bool      $ssl        Whether to use SSL
     * @param bool      $tls        Whether to use TLS
     * @param bool      $debugging 
     */
    public function __construct(
        $host,
        $username,
        $password,
        $port = null,
        $ssl = false,
        $tls = false,
        $debugging = false
    ) {
        Argument::i()
            ->test(1, 'string')
            ->test(2, 'string')
            ->test(3, 'string')
            ->test(4, 'int', 'null')
            ->test(5, 'bool')
            ->test(6, 'bool')
            ->test(7, 'bool');

        foreach (['host', 'username', 'password', 'port', 'ssl', 'tls', 'debugging'] as $arg) {
            $this->{"$arg"} = ${$arg};
        }

        if ($this->port === null) {
            $this->port = $this->ssl ? $this->ports['secured'] : $this->ports['default'];
        }
    }

    /**
     * @return host with schrema if needed
     */
    protected function connectionAddHostSchrema()
    {
        if ($this->ssl) {
            return "ssl://{$this->host}";
        }

        return $this->host;
    }

    /**
     * Connects to the server
     *
     * @param int  $timeout The connection timeout
     * @param bool $test    Whether to output the logs
     *
     * @return Bladeroot\Mail\Server
     */
    public function connect($timeout = self::TIMEOUT, $test = false)
    {
        Argument::i()
            ->test(1, 'int')
            ->test(2, 'bool');

        if ($this->socket && $this->isLoggedIn) {
            return $this;
        }

        $host = $this->connectionAddHostSchrema();

        $errno  =  0;
        $errstr = '';

        $this->socket = @fsockopen($host, $this->port, $errno, $errstr, $timeout);

        if (!$this->socket || strlen($errstr) > 0 || $errno > 0) {
            $this->disconnect();
            Exception::i()
                ->setMessage(Exception::SERVER_ERROR)
                ->addVariable($host.':'.$this->port)
                ->trigger();
        }

        $this->connectCheckAnswer();

        if ($this->tls) {
            $this->connectEnableTLS();
        }

        if ($test) {
            $this->disconnect();
        }

        return $this->login();
    }

    abstract protected function connectCheckAnswer();

    abstract protected function connectEnableTLS();

    abstract protected function authorized();

    /**
     * Login to server
     *
     * @return Bladeroot\Mail\Server
     */
    public function login($timeout = self::TIMEOUT, $test = false)
    {
        if ($this->isLoggedIn === true && $this->socket) {
            return $this;
        }

        if (!$this->socket) {
            $this->connect($timeout, $test);
        }

        if ($this->isLoggedIn !== true) {
            return $this->authorized();
        }

        return $this;
    }

    abstract protected function logout();

    /**
     * Disconnects from the server
     *
     * @return Bladeroot\Mail\Server
     */
    public function disconnect()
    {
        if ($this->isLoggedIn === true) {
            try {
                $this->logout();
            } catch (Argument $e) {
            }

            $this->isLoggedIn = false;
        }

        if ($this->socket) {
            fclose($this->socket);
            $this->socket = null;
        }

        return $this;
    }
}

