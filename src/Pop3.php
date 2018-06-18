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
 * General available methods for common POP3 functionality
 *
 * @vendor   Eden
 * @package  Mail
 * @author   Christian Blanquera <cblanquera@openovate.com>
 * @author   Airon Paul Dumael <airon.dumael@gmail.com>
 * @standard PSR-2
 */
class Pop3 extends ReceiveServer
{
    /**
     * @var array $ports The POP3 port
     */
    protected $ports = [
        'secured' => 995,
        'default' => 110,
    ];

    /**
     * @var string|null $timestamp Default timestamp
     */
    protected $timestamp = null;

    protected function connectCheckAnswer()
    {
        $welcome = $this->receive();

        strtok($welcome, '<');
        $this->timestamp = strtok('>');
        if (!strpos($this->timestamp, '@')) {
            $this->timestamp = null;
        } else {
            $this->timestamp = '<' . $this->timestamp . '>';
        }

        return $this;
    }

    protected function connectEnableTLS()
    {
        $this->call('STLS');
        if (!stream_socket_enable_crypto(
            $this->socket,
            true,
            STREAM_CRYPTO_METHOD_TLS_CLIENT
        )) {
            $this->disconnect();
            //throw exception
            Exception::i()
                ->setMessage(Exception::TLS_ERROR)
                ->addVariable($host.':'.$this->port)
                ->trigger();
        }

    }

    protected function authorized()
    {
        //login
        if ($this->timestamp) {
            try {
                $this->call(
                    'APOP '.$this->username
                    . ' '
                    . md5($this->timestamp . $this->password)
                );
                return;
            } catch (Argument $e) {
                // ignore
            }
        }

        $this->call('USER '.$this->username);
        if ($this->call('PASS '. $this->password) === false) {
            $this->disconnect();
            Exception::i(Exception::LOGIN_ERROR)->trigger();
        }

        $this->isLoggedIn = true;

        return $this;
    }

    protected function logout()
    {
        $this->send('QUIT');
    }

    /**
     * Returns a list of emails given the range
     *
     * @param number $start Pagination start
     * @param number $range Pagination range
     *
     * @return array
     */
    public function getEmails($start = 0, $range = 10)
    {
        $this->login();
        Argument::i()
            ->test(1, 'int')
            ->test(2, 'int');

        $total = $this->getEmailTotal();

        if ($total == 0) {
            return [];
        }

        if (!is_array($start)) {
            $range = $range > 0 ? $range : 1;
            $start = $start >= 0 ? $start : 0;
            $max = $total - $start;

            if ($max < 1) {
                $max = $total;
            }

            $min = $max - $range + 1;

            if ($min < 1) {
                $min = 1;
            }

            $set = $min . ':' . $max;

            if ($min == $max) {
                $set = $min;
            }
        }

        $emails = array();
        for ($i = $min; $i <= $max; $i++) {
            $emails[$i] = $this->call("RETR $i", true);
        }

        return $emails;
    }

    /**
     * Returns the total number of emails in a mailbox
     *
     * @return number
     */
    public function getEmailTotal()
    {
        $this->login();
        @list($messages, $octets) = explode(' ', $this->call('STAT'));
        $messages = is_numeric($messages) ? $messages : 0;

        return $messages;
    }

    /**
     * Remove an email from a mailbox
     *
     * @param *number $msgno The mail UID to remove
     *
     * @return Eden\Mail\Pop3
     */
    public function remove($msgno)
    {
        Argument::i()->test(1, 'int', 'string');

        if (!$this->isLoggedIn || !$this->socket) {
            $this->login();
        }

        if (!is_array($msgno)) {
            $msgno = [$msgno];
        }

        foreach ($msgno as $number) {
            $this->call("DELE $number");
        }

        return $this;
    }

    /**
     * Send it out and return the response
     *
     * @param *string $command   The raw POP3 command
     * @param bool    $multiline Whether to expect a multiline response
     *
     * @return string|false
     */
    protected function call($command, $multiline = false)
    {
        if (!$this->send($command)) {
            return false;
        }

        return $this->receive($multiline);
    }

    /**
     * Returns the response when all of it is received
     *
     * @param bool $multiline Whether to expect a multiline response
     *
     * @return string
     */
    protected function receive($multiline = false)
    {
        $result = @fgets($this->socket);
        $status = $result = trim($result);
        $message = '';

        if (strpos($result, ' ')) {
            list($status, $message) = explode(' ', $result, 2);
        }

        if ($status != '+OK') {
            return false;
        }

        if ($multiline) {
            $message = '';
            $line = fgets($this->socket);
            while ($line && rtrim($line, "\r\n") != '.') {
                if ($line[0] == '.') {
                    $line = substr($line, 1);
                }
                $this->debug('Receiving: '.$line);
                $message .= $line;
                $line = fgets($this->socket);
            };
        }

        return $message;
    }

    /**
     * Sends out the command
     *
     * @param *string $command The raw POP3 command
     *
     * @return bool
     */
    protected function send($command)
    {
        $this->debug('Sending: '.$command);

        return fputs($this->socket, $command . "\r\n");
    }

    /**
     * Debugging
     *
     * @param *string $string The string to output
     *
     * @return Eden\Mail\Imap
     */
    private function debug($string)
    {
        if ($this->debugging) {
            $string = htmlspecialchars($string);


            echo '<pre>'.$string.'</pre>'."\n";
        }
        return $this;
    }

    /**
     * Secret Sauce - Transform an email string
     * response to array key value format
     *
     * @param *string $email The actual email
     * @param array   $flags Any mail flags
     *
     * @return array
     */
    private function getEmailFormat($email, array $flags = array())
    {
        //if email is an array
        if (is_array($email)) {
            //make it into a string
            $email = implode("\n", $email);
        }

        //split the head and the body
        $parts = preg_split("/\n\s*\n/", $email, 2);

        $head = $parts[0];
        $body = null;
        if (isset($parts[1]) && trim($parts[1]) != ')') {
            $body = $parts[1];
        }

        $lines = explode("\n", $head);
        $head = [];
        foreach ($lines as $line) {
            if (trim($line) && preg_match("/^\s+/", $line)) {
                $head[count($head)-1] .= ' '.trim($line);
                continue;
            }

            $head[] = trim($line);
        }

        $head = implode("\n", $head);

        $recipientsTo = $recipientsCc = $recipientsBcc = $sender = [];

        //get the headers
        $headers1   = imap_rfc822_parse_headers($head);
        $headers2   = $this->getHeaders($head);

        //set the from
        $sender['name'] = null;
        if (isset($headers1->from[0]->personal)) {
            $sender['name'] = $headers1->from[0]->personal;
            //if the name is iso or utf encoded
            if (preg_match("/^\=\?[a-zA-Z]+\-[0-9]+.*\?/", strtolower($sender['name']))) {
                //decode the subject
                $sender['name'] = str_replace('_', ' ', mb_decode_mimeheader($sender['name']));
            }
        }

        $sender['email'] = $headers1->from[0]->mailbox . '@' . $headers1->from[0]->host;

        //if subject is not set
        if (!isset($headers1->subject) || strlen(trim($headers1->subject)) === 0) {
            //set subject
            $headers1->subject = self::NO_SUBJECT;
        }

        //trim the subject
        $headers1->subject = str_replace(array('<', '>'), '', trim($headers1->subject));

        //if the subject is iso or utf encoded
        if (preg_match("/^\=\?[a-zA-Z]+\-[0-9]+.*\?/", strtolower($headers1->subject))) {
            //decode the subject
            $headers1->subject = str_replace('_', ' ', mb_decode_mimeheader($headers1->subject));
        }

        //set thread details
        $topic  = isset($headers2['thread-topic']) ? $headers2['thread-topic'] : $headers1->subject;
        $parent = isset($headers2['in-reply-to']) ? str_replace('"', '', $headers2['in-reply-to']) : null;

        //set date
        $date = isset($headers1->date) ? strtotime($headers1->date) : null;

        //set message id
        if (isset($headers2['message-id'])) {
            $messageId = str_replace('"', '', $headers2['message-id']);
        } else {
            $messageId = '<eden-no-id-'.md5(uniqid()).'>';
        }

        $attachment = isset($headers2['content-type'])
            && strpos($headers2['content-type'], 'multipart/mixed') === 0;

        $format = [
            'id'            => $messageId,
            'parent'        => $parent,
            'topic'         => $topic,
            'mailbox'       => 'INBOX',
            'date'          => $date,
            'subject'       => str_replace('’', '\'', $headers1->subject),
            'from'          => $sender,
            'flags'         => $flags,
            'to'            => $this->getEmailRecipients($header1, 'to'),
            'cc'            => $this->getEmailRecipients($header1, 'cc'),
            'bcc'           => $this->getEmailRecipients($header1, 'bcc'),
            'attachment'    => $attachment,
            'raw'           => $email,
        ];

        if (trim($body) && $body != ')') {
            //get the body parts
            $parts = $this->getParts($email);

            //if there are no parts
            if (empty($parts)) {
                //just make the body as a single part
                $parts = array('text/plain' => $body);
            }

            //set body to the body parts
            $body = $parts;

            //look for attachments
            $attachment = [];
            //if there is an attachment in the body
            if (isset($body['attachment'])) {
                //take it out
                $attachment = $body['attachment'];
                unset($body['attachment']);
            }

            $format['body']         = $body;
            $format['attachment']   = $attachment;
        }

        return $format;
    }

    /**
     * Returns email reponse headers
     * array key value format
     *
     * @param *string $rawData The data to parse
     *
     * @return array
     */
    private function getHeaders($rawData)
    {
        if (is_string($rawData)) {
            $rawData = explode("\n", $rawData);
        }

        $key = null;
        $headers = [];
        foreach ($rawData as $line) {
            $line = trim($line);
            if (preg_match("/^([a-zA-Z0-9-]+):/i", $line, $matches)) {
                $key = strtolower($matches[1]);
                if (isset($headers[$key])) {
                    if (!is_array($headers[$key])) {
                        $headers[$key] = array($headers[$key]);
                    }

                    $headers[$key][] = trim(str_replace($matches[0], '', $line));
                    continue;
                }

                $headers[$key] = trim(str_replace($matches[0], '', $line));
                continue;
            }

            if (!is_null($key) && isset($headers[$key])) {
                if (is_array($headers[$key])) {
                    $headers[$key][count($headers[$key])-1] .= ' '.$line;
                    continue;
                }

                $headers[$key] .= ' '.$line;
            }
        }

        return $headers;
    }

    /**
     * Splits out body parts
     * ie. plain, HTML, attachment
     *
     * @param string $content The content to parse
     * @param array  $parts   The existing parts
     *
     * @return array
     */
    private function getParts($content, array $parts = [])
    {
        //separate the head and the body
        list($head, $body) = preg_split("/\n\s*\n/", $content, 2);
        //get the headers
        $head = $this->getHeaders($head);
        //if content type is not set
        if (!isset($head['content-type'])) {
            return $parts;
        }

        //split the content type
        if (is_array($head['content-type'])) {
            $type = array($head['content-type'][1]);
            if (strpos($type[0], ';') !== false) {
                $type = explode(';', $type[0], 2);
            }
        } else {
            $type = explode(';', $head['content-type'], 2);
        }

        //see if there are any extra stuff
        $extra = [];
        if (count($type) == 2) {
            $extra = explode('; ', str_replace(array('"', "'"), '', trim($type[1])));
        }

        //the content type is the first part of this
        $type = trim($type[0]);


        //foreach extra
        foreach ($extra as $i => $attr) {
            //transform the extra array to a key value pair
            $attr = explode('=', $attr, 2);
            if (count($attr) > 1) {
                list($key, $value) = $attr;
                $extra[strtolower($key)] = $value;
            }
            unset($extra[$i]);
        }

        //if a boundary is set
        if (isset($extra['boundary'])) {
            //split the body into sections
            $sections = explode('--'.str_replace(array('"', "'"), '', $extra['boundary']), $body);
            //we only want what's in the middle of these sections
            array_pop($sections);
            array_shift($sections);

            //foreach section
            foreach ($sections as $section) {
                //get the parts of that
                $parts = $this->getParts($section, $parts);
            }
        } else {
            //if name is set, it's an attachment
            //if encoding is set
            if (isset($head['content-transfer-encoding'])) {
                //the goal here is to make everytihg utf-8 standard
                switch (strtolower($head['content-transfer-encoding'])) {
                    case 'binary':
                        $body = imap_binary($body);
                        break;
                    case 'base64':
                        $body = base64_decode($body);
                        break;
                    case 'quoted-printable':
                        $body = quoted_printable_decode($body);
                        break;
                    case '7bit':
                        $body = mb_convert_encoding($body, 'UTF-8', 'ISO-2022-JP');
                        break;
                    default:
                        $body = str_replace(array("\n", ' '), '', $body);
                        break;
                }
            }

            if (isset($extra['name'])) {
                //add to parts
                $parts['attachment'][$extra['name']][$type] = $body;
            } else {
                //it's just a regular body
                //add to parts
                $parts[$type] = $body;
            }
        }
        return $parts;
    }
}
