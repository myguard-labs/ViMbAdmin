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
    /** Maximum time the multi-handle loop may wait before renewing liveness. */
    public const TRANSFER_POLL_SECONDS = 1.0;
    private const CURL_MULTI_OK = 0;
    private const CURL_MULTI_CALL_AGAIN = -1;

    private static function stringValue(mixed $value, string $name): string
    {
        if (!is_string($value)) {
            throw new ViMbAdmin_Exception($name . ' must be a string');
        }
        return $value;
    }

    /** @return array<string,mixed> */
    private static function mapValue(mixed $value, string $name): array
    {
        if (!is_array($value)) {
            throw new ViMbAdmin_Exception($name . ' must be an array');
        }
        foreach ($value as $key => $_item) {
            if (!is_string($key)) {
                throw new ViMbAdmin_Exception($name . ' must use string keys');
            }
        }
        return $value;
    }

    private static function positiveIntValue(mixed $value, string $name): int
    {
        if (is_string($value) && preg_match('/^[0-9]+$/D', $value)) {
            $normalized = ltrim($value, '0');
            $value = filter_var($normalized === '' ? '0' : $normalized, FILTER_VALIDATE_INT);
        }
        if (!is_int($value) || $value < 1) {
            throw new ViMbAdmin_Exception($name . ' must be a positive integer');
        }
        return $value;
    }
    /** @var non-empty-string */
    private $_url;

    /** @var string */
    private $_apiKey;

    /** @var int seconds */
    private $_timeout;

    /** @var callable|null */
    private $_progress;

    /** @var \CurlHandle|null */
    private $_handle = null;

    /** @var \CurlMultiHandle|null  Lazily initialized on first _post(), closed in __destruct() */
    private $_multi = null;

    /**
     * @param string $url     Full endpoint URL, e.g. http://dovecot:8081/doveadm/v1
     * @param string $apiKey  The doveadm_api_key (sent base64-encoded as X-Dovecot-API)
     * @param int    $timeout Request timeout in seconds (backup/resync can be slow)
     * @param callable|null $progress Invoked while a request is in progress
     */
    public function __construct($url, $apiKey, $timeout = 900, $progress = null)
    {
        if ($progress !== null && !is_callable($progress)) {
            throw new ViMbAdmin_Exception('doveadm progress callback must be callable');
        }
        $url = self::stringValue($url, 'doveadm.http.url');
        if ($url === '') {
            throw new ViMbAdmin_Exception('doveadm.http.url must not be empty');
        }
        $this->_url = $url;
        $this->_apiKey = self::stringValue($apiKey, 'doveadm.http.api_key');
        $this->_timeout = self::positiveIntValue($timeout, 'doveadm.http.timeout');
        $this->_progress = $progress;
    }

    public function __destruct()
    {
        if ($this->_multi !== null) {
            curl_multi_close($this->_multi);
        }
        $this->_multi = null;
        // No curl_close(): since PHP 8.0 the easy handle is an object freed by
        // refcount, the call has had no effect, and it is deprecated in 8.5 --
        // where the notice it emits lands after the runner's terminal verdict
        // and fails the suite. Dropping the reference is what releases it.
        $this->_handle = null;
    }

    /**
     * Build an instance from the application options.
     *
     * @param array<string, mixed>|null $options
     * @param callable():mixed|null $progress
     * @return ViMbAdmin_Doveadm
     * @throws ViMbAdmin_Exception when not configured
     */
    public static function fromOptions($options = null, $progress = null)
    {
        if ($options === null) {
            $options = OSS_Runtime::options();
        }
        $options = self::mapValue($options, 'doveadm options');
        if (!array_key_exists('doveadm', $options)) {
            throw new ViMbAdmin_Exception(_('doveadm HTTP API is not configured (doveadm.http.url / doveadm.http.api_key)'));
        }
        $doveadm = self::mapValue($options['doveadm'] ?? null, 'doveadm options.doveadm');
        if (!array_key_exists('http', $doveadm)) {
            throw new ViMbAdmin_Exception(_('doveadm HTTP API is not configured (doveadm.http.url / doveadm.http.api_key)'));
        }
        $http = self::mapValue($doveadm['http'] ?? null, 'doveadm options.doveadm.http');
        if (!array_key_exists('url', $http) || !array_key_exists('api_key', $http)) {
            throw new ViMbAdmin_Exception(_('doveadm HTTP API is not configured (doveadm.http.url / doveadm.http.api_key)'));
        }
        $url = self::stringValue($http['url'] ?? null, 'doveadm.http.url');
        $apiKey = self::stringValue($http['api_key'] ?? null, 'doveadm.http.api_key');
        if ($url === '' || $apiKey === '') {
            throw new ViMbAdmin_Exception(_('doveadm HTTP API is not configured (doveadm.http.url / doveadm.http.api_key)'));
        }
        $timeout = array_key_exists('timeout', $http) ? $http['timeout'] : 900;
        $timeout = self::positiveIntValue($timeout, 'doveadm.http.timeout');

        return new self(
            $url,
            $apiKey,
            $timeout,
            $progress
        );
    }

    /**
     * Run a single doveadm command and return its decoded response rows.
     *
     * @param string $cmd    doveadm command name (e.g. "force-resync", "mailbox delete")
     * @param array<string, mixed> $params Named parameters as the HTTP API expects them
     * @return array<mixed>         The decoded doveadmResponse payload
     * @throws ViMbAdmin_Exception on transport, auth, or command error
     */
    public function run($cmd, array $params = [])
    {
        $this->reportProgress();
        $tag     = 'vimb' . bin2hex(random_bytes(4));
        $payload = json_encode([ [ $cmd, (object) $params, $tag ] ]);

        if ($payload === false) {
            throw new ViMbAdmin_Exception(_('doveadm HTTP: failed to encode request'));
        }

        list($status, $body) = $this->_post($payload);

        if ($status === 401 || $status === 403) {
            throw new ViMbAdmin_Exception(sprintf(_('doveadm HTTP auth rejected (HTTP %d) — check doveadm.http.api_key'), $status));
        }

        if ($status < 200 || $status >= 300) {
            throw new ViMbAdmin_Exception(sprintf(_('doveadm HTTP error (HTTP %d): %s'), $status, substr((string) $body, 0, 500)));
        }

        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded) || !isset($decoded[0]) || !is_array($decoded[0])) {
            throw new ViMbAdmin_Exception(sprintf(_('doveadm HTTP: unparseable response: %s'), substr((string) $body, 0, 500)));
        }

        $type    = isset($decoded[0][0]) && is_string($decoded[0][0]) ? $decoded[0][0] : '';
        $content = isset($decoded[0][1]) ? $decoded[0][1] : null;

        if ($type === 'error') {
            $msg = is_array($content) && is_string($content['type'] ?? null) ? $content['type'] : 'unknown';
            $ec  = is_array($content) && is_int($content['exitCode'] ?? null) ? $content['exitCode'] : null;
            throw new ViMbAdmin_Doveadm_CommandException($cmd, $msg, $ec);
        }

        if ($type !== 'doveadmResponse') {
            throw new ViMbAdmin_Exception(sprintf(_("doveadm '%s': unexpected response type '%s'"), $cmd, $type));
        }

        if (!is_array($content)) {
            throw new ViMbAdmin_Exception(_('doveadm HTTP: response payload is not an array'));
        }
        return $content;
    }

    /**
     * POST the JSON body to the doveadm endpoint.
     *
     * @param string $payload JSON request body
     * @return array{0:int,1:string} [ httpStatus, responseBody ]
     * @throws ViMbAdmin_Exception on transport failure, including a cURL
     *         result code reported through the multi info queue (a refused
     *         connection, DNS failure or timeout), which never surfaces on
     *         the easy handle's curl_errno()
     */
    protected function _post($payload)
    {
        $authHeader = 'X-Dovecot-API ' . base64_encode($this->_apiKey);

        if (function_exists('curl_init')) {
            if ($this->_handle === null) {
                $this->_handle = curl_init();
                if ($this->_handle === false) {
                    throw new ViMbAdmin_Exception(_('doveadm HTTP: failed to initialize cURL request'));
                }
            }
            $ch = $this->_handle;
            // The easy handle is reused across calls, so curl_reset() drops
            // every option set for the previous request. All options below —
            // the protocol pinning included — must therefore be re-applied on
            // each call; hoisting them to handle creation would silently
            // un-pin the handle from the second request onwards.
            curl_reset($ch);

            // Pin the transfer to HTTP/HTTPS and refuse redirects. A doveadm
            // URL that is attacker-influenced (or a redirect from a
            // compromised endpoint) must not be able to reach file://,
            // scp://, gopher:// or any other libcurl protocol, and must not
            // be able to bounce the Authorization header carrying the API key
            // to a third-party host.
            //
            // SECURITY NOTE: the shipped default endpoint
            // (doveadm.http.url) is plain http://, so the X-Dovecot-API key
            // in the Authorization header travels in cleartext over that
            // link. That is only acceptable while the endpoint is reachable
            // exclusively over a trusted network (a container link or a
            // loopback/private segment). Deployments crossing any untrusted
            // path must configure an https:// endpoint.
            $protocolsPinned = defined('CURLOPT_PROTOCOLS_STR')
                // Preferred on PHP 8.2+: CURLOPT_PROTOCOLS is deprecated
                // upstream and warns on newer PHP/libcurl combinations.
                ? curl_setopt($ch, CURLOPT_PROTOCOLS_STR, 'http,https')
                    && curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS_STR, 'http,https')
                : curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS)
                    && curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);

            $configured = $protocolsPinned
                && curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false)
                && curl_setopt($ch, CURLOPT_URL, $this->_url)
                && curl_setopt($ch, CURLOPT_POST, true)
                && curl_setopt($ch, CURLOPT_POSTFIELDS, $payload)
                && curl_setopt($ch, CURLOPT_RETURNTRANSFER, true)
                && curl_setopt($ch, CURLOPT_TIMEOUT, $this->_timeout)
                && curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Content-Type: application/json',
                    'Authorization: ' . $authHeader,
                ]);
            if (!$configured) {
                throw new ViMbAdmin_Exception(_('doveadm HTTP: failed to configure cURL request'));
            }

            // curl_multi_init() returns a CurlMultiHandle on PHP 8 and never
            // false, so there is no failure branch to test here.
            if ($this->_multi === null) {
                $this->_multi = curl_multi_init();
            }
            $multi = $this->_multi;
            $added = false;
            try {
                $multiStatus = curl_multi_add_handle($multi, $ch);
                if ($multiStatus !== CURLM_OK) {
                    throw new ViMbAdmin_Exception(_('doveadm HTTP: failed to start cURL request'));
                }
                $added = true;

                $this->driveTransfer(
                    static function () use ($multi): array {
                        $running = 0;
                        $status = curl_multi_exec($multi, $running);
                        if (!is_int($running)) {
                            throw new ViMbAdmin_Exception(_('doveadm HTTP request returned invalid cURL state'));
                        }
                        return [ $status, $running ];
                    },
                    static function () use ($multi): int {
                        return curl_multi_select($multi, self::TRANSFER_POLL_SECONDS);
                    }
                );

                // The transfer ran on the multi interface, so curl_errno()/
                // curl_error() on the easy handle do NOT carry its result:
                // they stay CURLE_OK/'' even for a refused connection, a DNS
                // failure or a timeout. The authoritative CURLE_* code lives
                // only in the multi info queue, which must be read before
                // curl_multi_remove_handle(), which discards any message still
                // pending for that handle. Without this, a total transport
                // failure returned [ 0, '' ] to the caller as a successful —
                // merely empty — doveadm reply.
                $errno = null;
                while (($info = curl_multi_info_read($multi)) !== false) {
                    // Only CURLMSG_DONE carries a result today, and messages
                    // for any other handle belong to that handle, not to us.
                    if (($info['msg'] ?? null) !== CURLMSG_DONE) {
                        continue;
                    }
                    if (($info['handle'] ?? null) !== $ch) {
                        continue;
                    }
                    $result = $info['result'] ?? null;
                    if (is_int($result)) {
                        $errno = $result;
                    }
                }
                // No message matched (should not happen for a completed
                // transfer): fall back to the easy handle rather than
                // silently assuming success.
                if ($errno === null) {
                    $errno = curl_errno($ch);
                }
                $err    = $errno === CURLE_OK ? '' : curl_strerror($errno);
                if (!is_string($err) || $err === '') {
                    $err = curl_error($ch);
                }

                $body   = curl_multi_getcontent($ch);
                $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            } finally {
                if ($added) {
                    curl_multi_remove_handle($multi, $ch);
                }
            }

            if (!is_string($body) || $errno !== CURLE_OK) {
                throw new ViMbAdmin_Exception(_('doveadm HTTP request failed (curl): ') . $err);
            }
            return [ $status, $body ];
        }

        throw new ViMbAdmin_Exception(_('No HTTP client available (cURL extension missing)'));
    }

    /**
     * Drive one cURL transfer while reporting liveness after every bounded
     * wait. Unlike CURLOPT_XFERINFOFUNCTION, this cadence does not depend on
     * the peer sending or receiving bytes.
     *
     * @param callable():array{0:int,1:int} $perform
     * @param callable():int $wait
     * @return void
     */
    protected function driveTransfer(callable $perform, callable $wait)
    {
        $running = 0;
        do {
            do {
                [ $multiStatus, $running ] = $perform();
            } while ($multiStatus === self::CURL_MULTI_CALL_AGAIN);

            if ($multiStatus !== self::CURL_MULTI_OK) {
                throw new ViMbAdmin_Exception(_('doveadm HTTP request failed (curl multi)'));
            }

            if ($running > 0) {
                $this->reportProgress();
                $selected = $wait();
                if ($selected < -1) {
                    throw new ViMbAdmin_Exception(_('doveadm HTTP request wait failed (curl multi)'));
                }
                if ($selected === -1) {
                    usleep(100000);
                }
            }
        } while ($running > 0);
    }

    /** @return void */
    protected function reportProgress()
    {
        if ($this->_progress !== null) {
            ($this->_progress)();
        }
    }

    // =====================================================================
    //  Convenience wrappers
    // =====================================================================

    /**
     * Rebuild a mailbox's index/cache for a user (repair). Non-destructive.
     *
     * @param string $user  Full mailbox username (user@domain)
     * @param string $mbox  Mailbox mask (default all)
     * @return array<mixed>
     */
    public function forceResync($user, $mbox = '*')
    {
        return $this->run('force-resync', [ 'user' => $user, 'mailboxMask' => $mbox ]);
    }

    /**
     * (Re)build the full-text/normal index for a user. Non-destructive.
     *
     * @param string $user
     * @param string $mbox  Mailbox mask (default all)
     * @return array<mixed>
     */
    public function index($user, $mbox = '*')
    {
        return $this->run('index', [ 'user' => $user, 'mailboxMask' => $mbox ]);
    }

    /**
     * Compact storage, reclaiming space from expunged mail (optimize).
     * Non-destructive to live mail.
     *
     * @param string $user
     * @return array<mixed>
     */
    public function purge($user)
    {
        return $this->run('purge', [ 'user' => $user ]);
    }

    /**
     * Recalculate quota usage for a user.
     *
     * @param string $user
     * @return array<mixed>
     */
    public function quotaRecalc($user)
    {
        return $this->run('quota recalc', [ 'user' => $user ]);
    }

    /**
     * One-way dsync backup of a user's whole mailbox to a destination location
     * (e.g. "maildir:/backups/domain/user"). Used as the safety copy
     * before archive/delete.
     *
     * @param string $user
     * @param string $dest  dsync destination URI / path
     * @return array<mixed>
     */
    public function backup($user, $dest)
    {
        return $this->run('backup', [ 'user' => $user, 'destination' => $dest ]);
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
     * @return array<mixed>
     */
    public function fsDelete($path, $filter = 'posix')
    {
        $path = self::_stripDriverPrefix($path);

        return $this->run('fsDelete', [
            'recursive'  => true,
            'filterName' => $filter,
            'path'       => [ $path ],
        ]);
    }

    /**
     * Does a maildir actually CONTAIN mail (not just the empty cur/new/tmp +
     * index skeleton a DELETE leaves behind)? True if cur/ or new/ has any
     * file, or there is at least one IMAP sub-folder (.Foldername). REST-only,
     * cheap (a couple of fs iter calls). Used to keep emptied maildirs out of
     * the orphan scan.
     *
     * @param string $maildir  the maildir root path (no trailing slash needed)
     * @param string $filter
     * @return bool
     */
    public function maildirHasMail($maildir, $filter = 'posix')
    {
        $maildir = self::_stripDriverPrefix($maildir);
        $maildir = rtrim($maildir, '/');

        // Files directly in cur/ or new/.
        foreach ([ 'cur', 'new' ] as $box) {
            $files = $this->run('fsIter', [ 'filterName' => $filter, 'path' => $maildir . '/' . $box . '/' ]);
            if (is_array($files) && count($files) > 0) {
                return true;
            }
        }

        // Any IMAP sub-folder (maildir++ uses .Folder dirs) with content.
        foreach ($this->fsListDirs($maildir) as $d) {
            if (strlen($d) > 0 && $d[0] === '.') {    // .INBOX.Foo / .Sent / ...
                foreach ([ 'cur', 'new' ] as $box) {
                    $files = $this->run('fsIter', [ 'filterName' => $filter, 'path' => $maildir . '/' . $d . '/' . $box . '/' ]);
                    if (is_array($files) && count($files) > 0) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /**
     * List the immediate subdirectory NAMES of a path via `fs iter-dirs`
     * (REST). Used to enumerate the per-user maildirs under the mail-home root
     * for the orphan scan. `path` must be a STRING (array crashes the worker).
     *
     * @param string $path
     * @param string $filter
     * @return string[]
     */
    public function fsListDirs($path, $filter = 'posix')
    {
        $path = self::_stripDriverPrefix($path);
        $path = rtrim($path, '/');

        return $this->_fsList('fsIterDirs', $path, $filter);
    }

    /**
     * Recursive ON-DISK byte size of a directory, entirely via the doveadm
     * REST API. The maildir is zstd-compressed by Dovecot's mail_compress, so
     * this is the COMPRESSED footprint. Used by the low-priority MEASURE_SIZE
     * queue task that runs after an archive backup.
     *
     * doveadm `fs` over HTTP:
     *   - `fsIter`     lists FILES in a dir (NOT subdirectories);
     *   - `fsIterDirs` lists the SUBDIRECTORIES;
     *   - `fsStat`     gives a file's byte size.
     *   - for all three the `path` param must be a STRING (an array crashes the
     *     worker -> empty reply). `fsDelete` differs (it takes an array).
     * One `fsStat` per file, so this is slow (~1 min for a large mailbox) --
     * fine for a background queue task. null on error.
     *
     * @param string $path    filesystem path or `<driver>:<path>` dest URI
     * @param string $filter  the fs filter name (default "posix")
     * @return int|null
     */
    public function fsDirSize($path, $filter = 'posix')
    {
        $path = self::_stripDriverPrefix($path);
        $path = rtrim($path, '/');

        try {
            return $this->_fsDirSize($path, $filter, 0);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * List one directory level via a doveadm fs command (bare entry names).
     *
     * @param string $cmd     'fsIter' (files) or 'fsIterDirs' (subdirs)
     * @param string $dir
     * @param string $filter
     * @return string[]
     */
    private function _fsList($cmd, $dir, $filter)
    {
        $rows = $this->run($cmd, [ 'filterName' => $filter, 'path' => $dir . '/' ]);
        $out  = [];
        if (is_array($rows)) {
            foreach ($rows as $r) {
                $name = is_array($r) ? ($r['path'] ?? null) : $r;
                if ($name === null || $name === '' || $name === '.' || $name === '..') {
                    continue;
                }
                if (!is_string($name)) {
                    throw new ViMbAdmin_Exception('doveadm HTTP: filesystem response name must be a string');
                }
                $out[] = $name;
            }
        }
        return $out;
    }

    /**
     * @param string $dir
     * @param string $filter
     * @param int    $depth
     * @return int
     */
    private function _fsDirSize($dir, $filter, $depth)
    {
        if ($depth > 16) {
            return 0;
        }

        $total = 0;

        // Files -> sum their stat sizes.
        foreach ($this->_fsList('fsIter', $dir, $filter) as $name) {
            $child = $dir . '/' . ltrim($name, '/');
            try {
                $st = $this->run('fsStat', [ 'filterName' => $filter, 'path' => $child ]);
                if (is_array($st) && isset($st[0]) && is_array($st[0]) && is_int($st[0]['size'] ?? null)) {
                    $total += $st[0]['size'];
                } elseif (is_array($st) && is_int($st['size'] ?? null)) {
                    $total += $st['size'];
                }
            } catch (\Throwable $e) { /* file vanished mid-walk */
            }
        }

        // Subdirectories -> recurse.
        foreach ($this->_fsList('fsIterDirs', $dir, $filter) as $name) {
            $child = $dir . '/' . ltrim($name, '/');
            try {
                $total += $this->_fsDirSize($child, $filter, $depth + 1);
            } catch (\Throwable $e) { /* skip unreadable subtree */
            }
        }

        return $total;
    }

    /**
     * Restore a backup into a user's live store: `doveadm sync -u <user> <src>`.
     * This is the inverse of backup() — it merges the mail at $src (the
     * `maildir:/backups/...` dest a prior backup wrote) back into the user's
     * mailbox. sync is additive (a merge), so it will not delete mail the user
     * already has; restoring into a freshly (re)created empty account brings the
     * whole archive back.
     *
     * @param string $user  the live user to restore INTO (must exist in userdb)
     * @param string $src   the backup source location (maildir:/backups/...)
     * @return array<mixed>
     */
    public function restoreFrom($user, $src)
    {
        return $this->run('sync', [
            'user'        => $user,
            'destination' => [ $src ],
        ]);
    }

    /**
     * Flush Dovecot's authentication cache. With no users, flushes the whole
     * cache; pass user(s) to flush only those entries. Non-destructive — forces
     * the next auth to re-read from the userdb/passdb (e.g. after a password or
     * active-flag change in the panel).
     *
     * @param list<string> $users  Optional list of usernames to flush (default: all)
     * @return array<mixed>
     */
    public function authCacheFlush(array $users = [])
    {
        $params = [];
        if ($users) {
            $params['user'] = array_values($users);
        }
        return $this->run('auth cache flush', $params);
    }

    /**
     * List a user's mailboxes (folders). Returns a flat array of mailbox names.
     *
     * @param string $user
     * @return string[]
     */
    public function mailboxList($user)
    {
        $rows  = $this->run('mailbox list', [ 'user' => $user ]);
        $names = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (is_array($row) && isset($row['mailbox'])) {
                    if (!is_string($row['mailbox'])) {
                        throw new ViMbAdmin_Exception('doveadm HTTP: mailbox name must be a string');
                    }
                    $names[] = $row['mailbox'];
                } elseif (is_string($row)) {
                    $names[] = $row;
                }
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
    public function mailboxDelete($user)
    {
        $names = $this->mailboxList($user);

        // Sort DEEPEST-FIRST so children are deleted before their parents.
        // We delete recursively (-r), so deleting a parent also removes its
        // children; if the loop then reached an already-removed child it would
        // get "Mailbox doesn't exist" (doveadm exit 68) and abort the whole
        // delete (this broke accounts with nested folders like
        // INBOX.Foo + INBOX.Foo.2020). Deepest-first minimises that, and
        // _isAlreadyGone() below tolerates it when it still happens.
        usort($names, function ($a, $b) {
            return substr_count($b, '.') <=> substr_count($a, '.');
        });

        // Delete every non-INBOX box first, INBOX last (INBOX == maildir root,
        // can't be deleted as a box but the expunge already emptied the store).
        $inboxLast = [];
        foreach ($names as $name) {
            if (strcasecmp($name, 'INBOX') === 0) {
                $inboxLast[] = $name;
                continue;
            }
            try {
                $this->_mailboxDeleteOne($user, $name);
            } catch (ViMbAdmin_Exception $e) {
                // Already removed by a recursive parent delete -> not an error.
                if (!self::_isAlreadyGone($e)) {
                    throw $e;
                }
            }
        }

        foreach ($inboxLast as $name) {
            try {
                $this->_mailboxDeleteOne($user, $name);
            } catch (ViMbAdmin_Exception $e) {
                // INBOX can't be deleted as a box (exit 65), or it's already
                // gone (exit 68) — both fine, the mail is already expunged.
                if (!self::_hasExitCode($e, 65) && !self::_isAlreadyGone($e)) {
                    throw $e;
                }
            }
        }
    }

    /**
     * Did a mailbox-delete fail only because the box was already removed (by a
     * recursive parent delete)? doveadm reports "Mailbox doesn't exist" with
     * exit code 68. That's a no-op success for our purpose (emptying the store).
     *
     * @param \Throwable $e
     * @return bool
     */
    private static function _isAlreadyGone(\Throwable $e)
    {
        return self::_hasExitCode($e, 68);
    }

    private static function _hasExitCode(\Throwable $e, int $exitCode): bool
    {
        return $e instanceof ViMbAdmin_Doveadm_CommandException
            && $e->getExitCode() === $exitCode;
    }

    private static function _stripDriverPrefix(mixed $path): string
    {
        if (!is_string($path)) {
            throw new \TypeError('doveadm filesystem path must be a string');
        }
        $stripped = preg_replace('#^[a-z][a-z0-9+.-]*:#i', '', $path);
        if (!is_string($stripped)) {
            throw new \LogicException('doveadm driver-prefix pattern failed');
        }
        return $stripped;
    }

    /**
     * Delete a single named mailbox, recursively + unsafe (no empty-check).
     *
     * @param string $user
     * @param string $mailbox
     * @return array<mixed>
     */
    private function _mailboxDeleteOne($user, $mailbox)
    {
        // doveadm "mailbox delete" params (2.4): recursive (-r), unsafe (-Z),
        // require-empty (-e), subscriptions (-s), mailbox (positional array).
        return $this->run('mailbox delete', [
            'user'      => $user,
            'mailbox'   => [ $mailbox ],
            'recursive' => true,
            'unsafe'    => true,
        ]);
    }
}
