<?php

/**
 * Open Solutions' ViMbAdmin Project.
 *
 * This file is part of Open Solutions' ViMbAdmin Project which is a
 * project which provides an easily manageable web based virtual
 * mailbox administration system.
 *
 * Copyright (c) 2011 Open Source Solutions Limited
 *
 * ViMbAdmin is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * ViMbAdmin is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with ViMbAdmin.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @copyright Copyright (c) 2011 Open Source Solutions Limited
 * @license http://opensource.org/licenses/gpl-3.0.html GNU General Public License, version 3 (GPLv3)
 */

/**
 * Thin client for Dovecot's doveadm HTTP API (POST /doveadm/v1).
 *
 * Lets ViMbAdmin run mailbox lifecycle operations (force-resync / index /
 * purge / backup / mailbox delete / quota recalc) against the Dovecot
 * container over HTTP+JSON, instead of sharing the /srv/vmail filesystem.
 *
 * Wire protocol (Dovecot 2.4):
 *   request : [ [ "<cmdName>", { <param>: <value>, ... }, "<tag>" ] ]
 *   response: [ [ "doveadmResponse", [ <rows> ], "<tag>" ] ]   on success
 *             [ [ "error", { "type": "...", "exitCode": N }, "<tag>" ] ] on error
 *   auth    : Authorization: X-Dovecot-API <base64(api_key)>
 *
 * Command names are case-insensitive and dash/space/camelCase-equivalent on the
 * server (i_strccdascmp), so "forceResync" == "force-resync", "mailboxDelete"
 * == "mailbox delete".
 *
 * Configured via application.ini:
 *   doveadm.http.url     = "http://dovecot:8081/doveadm/v1"
 *   doveadm.http.api_key = "..."   ; the X-Dovecot-API bearer
 *
 * @package ViMbAdmin
 * @subpackage Library
 */
class ViMbAdmin_Doveadm
{
    /** @var string */
    private $_url;

    /** @var string */
    private $_apiKey;

    /** @var int seconds */
    private $_timeout;

    /**
     * @param string $url     Full endpoint URL, e.g. http://dovecot:8081/doveadm/v1
     * @param string $apiKey  The doveadm_api_key (sent base64-encoded as X-Dovecot-API)
     * @param int    $timeout Request timeout in seconds (backup/resync can be slow)
     */
    public function __construct( $url, $apiKey, $timeout = 900 )
    {
        $this->_url     = (string) $url;
        $this->_apiKey  = (string) $apiKey;
        $this->_timeout = (int) $timeout;
    }

    /**
     * Build an instance from the application options (Zend_Registry 'options').
     *
     * @param array|null $options
     * @return ViMbAdmin_Doveadm
     * @throws ViMbAdmin_Exception when not configured
     */
    public static function fromOptions( $options = null )
    {
        if( $options === null )
            $options = Zend_Registry::get( 'options' );

        if( empty( $options['doveadm']['http']['url'] ) || !isset( $options['doveadm']['http']['api_key'] ) )
            throw new ViMbAdmin_Exception( _( 'doveadm HTTP API is not configured (doveadm.http.url / doveadm.http.api_key)' ) );

        $timeout = isset( $options['doveadm']['http']['timeout'] )
            ? (int) $options['doveadm']['http']['timeout'] : 900;

        return new self(
            $options['doveadm']['http']['url'],
            $options['doveadm']['http']['api_key'],
            $timeout
        );
    }

    /**
     * Run a single doveadm command and return its decoded response rows.
     *
     * @param string $cmd    doveadm command name (e.g. "force-resync", "mailbox delete")
     * @param array  $params Named parameters as the HTTP API expects them
     * @return array         The decoded doveadmResponse payload (rows / scalar)
     * @throws ViMbAdmin_Exception on transport, auth, or command error
     */
    public function run( $cmd, array $params = [] )
    {
        $tag     = 'vimb' . substr( md5( uniqid( '', true ) ), 0, 8 );
        $payload = json_encode( [ [ $cmd, (object) $params, $tag ] ] );

        list( $status, $body ) = $this->_post( $payload );

        if( $status === 401 || $status === 403 )
            throw new ViMbAdmin_Exception( sprintf( _( 'doveadm HTTP auth rejected (HTTP %d) — check doveadm.http.api_key' ), $status ) );

        if( $status < 200 || $status >= 300 )
            throw new ViMbAdmin_Exception( sprintf( _( 'doveadm HTTP error (HTTP %d): %s' ), $status, substr( (string) $body, 0, 500 ) ) );

        $decoded = json_decode( (string) $body, true );
        if( !is_array( $decoded ) || !isset( $decoded[0] ) || !is_array( $decoded[0] ) )
            throw new ViMbAdmin_Exception( sprintf( _( 'doveadm HTTP: unparseable response: %s' ), substr( (string) $body, 0, 500 ) ) );

        $type    = isset( $decoded[0][0] ) ? $decoded[0][0] : '';
        $content = isset( $decoded[0][1] ) ? $decoded[0][1] : null;

        if( $type === 'error' )
        {
            $msg = is_array( $content ) && isset( $content['type'] ) ? $content['type'] : 'unknown';
            $ec  = is_array( $content ) && isset( $content['exitCode'] ) ? $content['exitCode'] : '?';
            throw new ViMbAdmin_Exception( sprintf( _( "doveadm '%s' failed: %s (exit %s)" ), $cmd, $msg, $ec ) );
        }

        if( $type !== 'doveadmResponse' )
            throw new ViMbAdmin_Exception( sprintf( _( "doveadm '%s': unexpected response type '%s'" ), $cmd, $type ) );

        return $content;
    }

    /**
     * POST the JSON body to the doveadm endpoint.
     *
     * Uses Zend_Http_Client when available, falling back to the cURL extension.
     *
     * @param string $payload JSON request body
     * @return array{0:int,1:string} [ httpStatus, responseBody ]
     * @throws ViMbAdmin_Exception on transport failure
     */
    private function _post( $payload )
    {
        $authHeader = 'X-Dovecot-API ' . base64_encode( $this->_apiKey );

        if( class_exists( 'Zend_Http_Client' ) )
        {
            try
            {
                $client = new Zend_Http_Client( $this->_url, [
                    'timeout'      => $this->_timeout,
                    'keepalive'    => false,
                    'maxredirects' => 0,
                ] );
                $client->setHeaders( [
                    'Content-Type'  => 'application/json',
                    'Authorization' => $authHeader,
                ] );
                $client->setRawData( $payload, 'application/json' );
                $resp = $client->request( Zend_Http_Client::POST );
                return [ (int) $resp->getStatus(), $resp->getBody() ];
            }
            catch( Exception $e )
            {
                throw new ViMbAdmin_Exception( _( 'doveadm HTTP request failed: ' ) . $e->getMessage() );
            }
        }

        if( function_exists( 'curl_init' ) )
        {
            $ch = curl_init( $this->_url );
            curl_setopt_array( $ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $this->_timeout,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'Authorization: ' . $authHeader,
                ],
            ] );
            $body   = curl_exec( $ch );
            $status = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
            $err    = curl_error( $ch );
            curl_close( $ch );
            if( $body === false )
                throw new ViMbAdmin_Exception( _( 'doveadm HTTP request failed (curl): ' ) . $err );
            return [ $status, $body ];
        }

        throw new ViMbAdmin_Exception( _( 'No HTTP client available (neither Zend_Http_Client nor cURL)' ) );
    }

    // =====================================================================
    //  Convenience wrappers
    // =====================================================================

    /**
     * Rebuild a mailbox's index/cache for a user (repair). Non-destructive.
     *
     * @param string $user  Full mailbox username (user@domain)
     * @param string $mbox  Mailbox mask (default all)
     * @return array
     */
    public function forceResync( $user, $mbox = '*' )
    {
        return $this->run( 'force-resync', [ 'user' => $user, 'mailboxMask' => $mbox ] );
    }

    /**
     * (Re)build the full-text/normal index for a user. Non-destructive.
     *
     * @param string $user
     * @param string $mbox  Mailbox mask (default all)
     * @return array
     */
    public function index( $user, $mbox = '*' )
    {
        return $this->run( 'index', [ 'user' => $user, 'mailboxMask' => $mbox ] );
    }

    /**
     * Compact storage, reclaiming space from expunged mail (optimize).
     * Non-destructive to live mail.
     *
     * @param string $user
     * @return array
     */
    public function purge( $user )
    {
        return $this->run( 'purge', [ 'user' => $user ] );
    }

    /**
     * Recalculate quota usage for a user.
     *
     * @param string $user
     * @return array
     */
    public function quotaRecalc( $user )
    {
        return $this->run( 'quota recalc', [ 'user' => $user ] );
    }

    /**
     * One-way dsync backup of a user's whole mailbox to a destination location
     * (e.g. "maildir:/srv/vmail-backup/domain/user"). Used as the safety copy
     * before archive/delete.
     *
     * @param string $user
     * @param string $dest  dsync destination URI / path
     * @return array
     */
    public function backup( $user, $dest )
    {
        return $this->run( 'backup', [ 'user' => $user, 'destination' => $dest ] );
    }

    /**
     * Recursively delete a filesystem path via doveadm's `fs delete`, using a
     * configured posix fs filter (dovecot conf.d: `fs posix { driver = posix }`).
     * Used by autoprune to remove a /backups maildir off the dovecot host
     * without sharing its filesystem with ViMbAdmin.
     *
     * Accepts either a bare path or a `maildir:/path` / `mdbox:/path` dest URI
     * (the leading `<driver>:` is stripped — fs operates on the raw path).
     *
     * @param string $path  filesystem path or `<driver>:<path>` dest URI
     * @param string $filter  the fs filter name (default "posix")
     * @return array
     */
    public function fsDelete( $path, $filter = 'posix' )
    {
        // Strip a leading mail-driver prefix (maildir:/..., mdbox:/...).
        if( preg_match( '#^[a-z0-9]+:(/.*)$#i', $path, $m ) )
            $path = $m[1];

        return $this->run( 'fsDelete', [
            'recursive'  => true,
            'filterName' => $filter,
            'path'       => [ $path ],
        ] );
    }

    /**
     * Flush Dovecot's authentication cache. With no users, flushes the whole
     * cache; pass user(s) to flush only those entries. Non-destructive — forces
     * the next auth to re-read from the userdb/passdb (e.g. after a password or
     * active-flag change in the panel).
     *
     * @param array $users  Optional list of usernames to flush (default: all)
     * @return array
     */
    public function authCacheFlush( array $users = [] )
    {
        $params = [];
        if( $users )
            $params['user'] = array_values( $users );
        return $this->run( 'auth cache flush', $params );
    }

    /**
     * List a user's mailboxes (folders). Returns a flat array of mailbox names.
     *
     * @param string $user
     * @return string[]
     */
    public function mailboxList( $user )
    {
        $rows  = $this->run( 'mailbox list', [ 'user' => $user ] );
        $names = [];
        if( is_array( $rows ) )
        {
            foreach( $rows as $row )
            {
                if( is_array( $row ) && isset( $row['mailbox'] ) )
                    $names[] = (string) $row['mailbox'];
                elseif( is_string( $row ) )
                    $names[] = $row;
            }
        }
        return $names;
    }

    /**
     * Empty a user's whole mail store, leaving no trace on disk.
     *
     * "mailbox delete *" does NOT work — doveadm treats "*" as a literal name,
     * not a wildcard (HTTP API exit 68 "Mailbox doesn't exist: *"). So we list
     * the real mailbox names and delete each recursively + unsafe.
     *
     * INBOX is special: in this maildir layout INBOX == the maildir root, so
     * doveadm "mailbox delete INBOX" returns exit 65 ("can't delete INBOX") even
     * though the recursive expunge it performs first DOES wipe the mail and the
     * maildir directory is GC'd to nothing. We delete it LAST and tolerate that
     * one specific failure — every other box is fatal-on-error. After this the
     * on-disk maildir dir is gone; a subsequent "mailbox list" only shows the
     * namespace's auto-synthesised INBOX/special-use placeholders, which are
     * in-memory and never written back.
     *
     * @param string $user
     * @return void
     * @throws ViMbAdmin_Exception if a non-INBOX mailbox delete fails
     */
    public function mailboxDelete( $user )
    {
        $names = $this->mailboxList( $user );

        // Delete every non-INBOX box first (fatal on error), INBOX last.
        $inboxLast = [];
        foreach( $names as $name )
        {
            if( strcasecmp( $name, 'INBOX' ) === 0 )
                $inboxLast[] = $name;
            else
                $this->_mailboxDeleteOne( $user, $name );
        }

        foreach( $inboxLast as $name )
        {
            try
            {
                $this->_mailboxDeleteOne( $user, $name );
            }
            catch( ViMbAdmin_Exception $e )
            {
                // Expected: INBOX (== maildir root) cannot be deleted as a box,
                // but the recursive expunge already emptied the store. Swallow
                // only this case; the mail is gone and the dir is GC'd.
                if( stripos( $e->getMessage(), 'inbox' ) === false
                    && stripos( $e->getMessage(), 'exit 65' ) === false )
                    throw $e;
            }
        }
    }

    /**
     * Delete a single named mailbox, recursively + unsafe (no empty-check).
     *
     * @param string $user
     * @param string $mailbox
     * @return array
     */
    private function _mailboxDeleteOne( $user, $mailbox )
    {
        // doveadm "mailbox delete" params (2.4): recursive (-r), unsafe (-Z),
        // require-empty (-e), subscriptions (-s), mailbox (positional array).
        return $this->run( 'mailbox delete', [
            'user'      => $user,
            'mailbox'   => [ $mailbox ],
            'recursive' => true,
            'unsafe'    => true,
        ] );
    }
}
