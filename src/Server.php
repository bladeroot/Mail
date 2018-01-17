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

        return $this->authorized();
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

// if IMAP PHP is not authorizedinstalled we still need these functions
if (!function_exists('imap_rfc822_parse_headers')) {
    function imap_rfc822_parse_headers_decode($from)
    {
        if (preg_match('#\<([^\>]*)#', html_entity_decode($from))) {
            preg_match('#([^<]*)\<([^\>]*)\>#', html_entity_decode($from), $From);
            $from = array(
                'personal'  => trim($From[1]),
                'email'     => trim($From[2]));
        } else {
            $from = array(
                'personal'  => '',
                'email'     => trim($from));
        }

        preg_match('#([^\@]*)@(.*)#', $from['email'], $from);

        if (empty($from[1])) {
            $from[1] = '';
        }

        if (empty($from[2])) {
            $from[2] = '';
        }

        $__from = array(
            'mailbox'   => trim($from[1]),
            'host'      => trim($from[2]));

        return (object) array_merge($from, $__from);
    }

    function imap_rfc822_parse_headers($header)
    {
        $header = htmlentities($header);
        $headers = new \stdClass();
        $tos = $ccs = $bccs = array();
        $headers->to = $headers->cc = $headers->bcc = array();

        preg_match('#Message\-(ID|id|Id)\:([^\n]*)#', $header, $ID);
        $headers->ID = trim($ID[2]);
        unset($ID);

        preg_match('#\nTo\:([^\n]*)#', $header, $to);
        if (isset($to[1])) {
            $tos = array(trim($to[1]));
            if (strpos($to[1], ',') !== false) {
                explode(',', trim($to[1]));
            }
        }

        $headers->from = array(new \stdClass());
        preg_match('#\nFrom\:([^\n]*)#', $header, $from);
        $headers->from[0] = imap_rfc822_parse_headers_decode(trim($from[1]));

        preg_match('#\nCc\:([^\n]*)#', $header, $cc);
        if (isset($cc[1])) {
            $ccs = array(trim($cc[1]));
            if (strpos($cc[1], ',') !== false) {
                explode(',', trim($cc[1]));
            }
        }

        preg_match('#\nBcc\:([^\n]*)#', $header, $bcc);
        if (isset($bcc[1])) {
            $bccs = array(trim($bcc[1]));
            if (strpos($bcc[1], ',') !== false) {
                explode(',', trim($bcc[1]));
            }
        }

        preg_match('#\nSubject\:([^\n]*)#', $header, $subject);
        $headers->subject = trim($subject[1]);
        unset($subject);

        preg_match('#\nDate\:([^\n]*)#', $header, $date);
        $date = substr(trim($date[0]), 6);

        $date = preg_replace('/\(.*\)/', '', $date);

        $headers->date = trim($date);
        unset($date);

        foreach ($ccs as $k => $cc) {
            $headers->cc[$k] = imap_rfc822_parse_headers_decode(trim($cc));
        }

        foreach ($bccs as $k => $bcc) {
            $headers->bcc[$k] = imap_rfc822_parse_headers_decode(trim($bcc));
        }

        foreach ($tos as $k => $to) {
            $headers->to[$k] = imap_rfc822_parse_headers_decode(trim($to));
        }

        return $headers;
    }
}
