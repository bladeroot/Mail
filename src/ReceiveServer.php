<?php
/**
 * This file is part of the Bladeroot PHP Library.
 * (c) 2018 HiDEV
 * This is fork of Eden Mail Library
 * (c) 2014-2016 Openovate Labs
 *
 * Copyright and license information can be found at LICENSE.txt
 * distributed with this package.
 */

namespace Bladeroot\Mail;

/**
 * Receive Class
 *
 * @vendor   Bladeroot
 * @package  Mail
 * @author   Yurii Myronchuk <bladeroot@gmail.com>
 * @standard PSR-2
 */
class ReceiveServer extends Server
{
   /**
     * @param   object  $header
     * @param   string  $destination
     *
     * @return  array
     */
    protected function getEmailRecipients($header, $destination)
    {
        $recipients = [];
        if (!isset($header->{$destination})) {
            return $recipients;
        }

        foreach ($header->{$destination} as $dst) {
            $recipient = ['name'=>null];
            if (isset($dst->personal)) {
                $recipient['name'] = $dst->personal;

                if (preg_match("/^\=\?[a-zA-Z]+\-[0-9]+.*\?/", strtolower($recipient['name']))) {
                    $recipient['name'] = str_replace('_', ' ', mb_decode_mimeheader($recipient['name']));
                }
            }

            $recipient['email'] = $dst->mailbox . '@' . $dst->host;

            $recipients[] = $recipient;
        }

        return $recipients;
    }

}

// if php-imap is not installed we still need these functions
if (!function_exists('imap_rfc822_parse_headers')) {
    function imap_rfc822_parse_headers_decode($from)
    {
        if (preg_match('#\<([^\>]*)#', html_entity_decode($from))) {
            preg_match('#([^<]*)\<([^\>]*)\>#', html_entity_decode($from), $From);
            $from = [
                'personal'  => trim($From[1]),
                'email'     => trim($From[2]),
            ];
        } else {
            $from = [
                'personal'  => '',
                'email'     => trim($from)
            ];
        }

        preg_match('#([^\@]*)@(.*)#', $from['email'], $from);

        if (empty($from[1])) {
            $from[1] = '';
        }

        if (empty($from[2])) {
            $from[2] = '';
        }

        $__from = [
            'mailbox'   => trim($from[1]),
            'host'      => trim($from[2])
        ];

        return (object) array_merge($from, $__from);
    }
}

if (!function_exists('imap_rfc822_parse_headers')) {

    function imap_rfc822_parse_headers($header)
    {
        $header = htmlentities($header);
        $headers = new \stdClass();
        $tos = $ccs = $bccs = [];
        $headers->to = $headers->cc = $headers->bcc = [];

        preg_match('#Message\-(ID|id|Id)\:([^\n]*)#', $header, $ID);
        $headers->ID = trim($ID[2]);
        unset($ID);

        preg_match('#\nTo\:([^\n]*)#', $header, $to);
        if (isset($to[1])) {
            $tos = [trim($to[1])];
            if (strpos($to[1], ',') !== false) {
                explode(',', trim($to[1]));
            }
        }

        $headers->from = [new \stdClass()];
        preg_match('#\nFrom\:([^\n]*)#', $header, $from);
        $headers->from[0] = imap_rfc822_parse_headers_decode(trim($from[1]));

        preg_match('#\nCc\:([^\n]*)#', $header, $cc);
        if (isset($cc[1])) {
            $ccs = [trim($cc[1])];
            if (strpos($cc[1], ',') !== false) {
                explode(',', trim($cc[1]));
            }
        }

        preg_match('#\nBcc\:([^\n]*)#', $header, $bcc);
        if (isset($bcc[1])) {
            $bccs = [trim($bcc[1])];
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
