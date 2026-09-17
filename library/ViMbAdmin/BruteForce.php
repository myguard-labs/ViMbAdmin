<?php

/**
 * ViMbAdmin brute-force login protection.
 *
 * Tracks failed-login pressure per source *network prefix* and locks that
 * prefix out for a cooldown window once it crosses a threshold. State is kept
 * as one small JSON file per prefix under a state directory (no DB coupling,
 * survives across requests, trivially clearable). Keying on the prefix rather
 * than the exact address is what stops an attacker who holds a whole IPv6 /64
 * -- the normal residential and hosting allocation -- from multiplying the
 * attempt budget by 2^64. The directory is capped and evicted, so it cannot be
 * grown without bound either.
 *
 * Model: every login POST is counted as a pending attempt in _preLogin();
 * a successful auth (password + 2FA) clears the counter. While the counter
 * for a source is at/over the threshold within the window, logins from that
 * source are refused with HTTP 429 -- unless the source IP is allowlisted.
 *
 * Configuration (application.ini, [bruteforce] -> $opts):
 *   bruteforce.enabled      = 1
 *   bruteforce.max_attempts = 5         ; failures before lock
 *   bruteforce.window       = 900       ; seconds the counter accumulates over
 *   bruteforce.lockout      = 900       ; seconds a source stays locked
 *   bruteforce.ipv4_prefix  = 24        ; counting granularity, 8..32
 *   bruteforce.ipv6_prefix  = 64        ; counting granularity, 16..128
 *   bruteforce.max_entries  = 4096      ; state files kept before eviction
 *   bruteforce.statedir     = "/opt/vimbadmin/var/bruteforce"
 *   bruteforce.whitelist[]  = "127.0.0.1"
 *   bruteforce.whitelist[]  = "10.0.0.0/8"
 *
 * @package ViMbAdmin
 */
class ViMbAdmin_BruteForce
{
    private const LOCK_TIMEOUT_NANOSECONDS = 1_000_000_000;
    private const LOCK_RETRY_MICROSECONDS = 1000;
    private const LOCK_MAX_RETRY_MICROSECONDS = 64000;
    private const REAP_SCAN_LIMIT = 128;
    private const DEFAULT_IPV4_PREFIX = 24;
    private const DEFAULT_IPV6_PREFIX = 64;
    private const DEFAULT_MAX_ENTRIES = 4096;
    private const EVICT_SAMPLE_LIMIT = 512;
    private const REAP_INTERVAL_SECONDS = 60;

    /** @return array<string,mixed> */
    private static function stringMap(mixed $value, string $name): array
    {
        if (!is_array($value)) {
            throw new LogicException($name . ' must be an array');
        }
        foreach ($value as $key => $_item) {
            if (!is_string($key)) {
                throw new LogicException($name . ' must use string keys');
            }
        }
        return $value;
    }

    private static function boolValue(mixed $value, string $name): bool
    {
        if ($value === true || $value === 1 || $value === '1') {
            return true;
        }
        if ($value === false || $value === 0 || $value === '' || $value === '0') {
            return false;
        }
        throw new LogicException($name . ' must be boolean');
    }

    private static function stringValue(mixed $value, string $name): string
    {
        if (!is_string($value)) {
            throw new LogicException($name . ' must be a string');
        }
        return $value;
    }

    private static function intValue(mixed $value, string $name, int $minimum = 0): int
    {
        if (is_string($value) && preg_match('/^[0-9]+$/D', $value)) {
            $normalized = ltrim($value, '0');
            $value = filter_var($normalized === '' ? '0' : $normalized, FILTER_VALIDATE_INT);
        }
        if (!is_int($value) || $value < $minimum) {
            throw new LogicException($name . ' must be a non-negative integer');
        }
        return $value;
    }

    /** @return list<string> */
    private static function stringList(mixed $value, string $name): array
    {
        $values = is_array($value) ? $value : [ $value ];
        $result = [];
        foreach ($values as $item) {
            if (!is_string($item)) {
                throw new LogicException($name . ' must contain strings');
            }
            $result[] = $item;
        }
        return $result;
    }
    /** @var bool */
    private $_enabled   = true;
    /** @var int */
    private $_max       = 5;
    /** @var int */
    private $_window    = 900;
    /** @var int */
    private $_lockout   = 900;
    /** @var string */
    private $_statedir  = null;
    /** @var list<string> */
    private $_whitelist = [];
    /** @var string */
    private $_proxyMode = 'auto';
    /** @var list<string> */
    private $_proxies   = [];
    /** @var int */
    private $_v4prefix  = self::DEFAULT_IPV4_PREFIX;
    /** @var int */
    private $_v6prefix  = self::DEFAULT_IPV6_PREFIX;
    /** @var int */
    private $_maxEntries = self::DEFAULT_MAX_ENTRIES;

    /**
     * @param mixed $em   Unused (kept for call-site compatibility).
     * @param array<string, mixed> $opts [bruteforce] options from application.ini.
     */
    public function __construct($em = null, array $opts = [])
    {
        // $em is accepted only for call-site compatibility with the historic
        // (EntityManager-backed) signature; this file-state implementation does
        // not use it. Explicitly discard so it isn't flagged as dead.
        unset($em);

        $opts = self::stringMap($opts, 'bruteforce options');
        if (isset($opts['enabled'])) {
            $this->_enabled = self::boolValue($opts['enabled'], 'bruteforce.enabled');
        }
        if (isset($opts['max_attempts'])) {
            $this->_max     = self::intValue($opts['max_attempts'], 'bruteforce.max_attempts', 1);
        }
        if (isset($opts['window'])) {
            $this->_window  = self::intValue($opts['window'], 'bruteforce.window');
        }
        if (isset($opts['lockout'])) {
            $this->_lockout = self::intValue($opts['lockout'], 'bruteforce.lockout');
        }

        $statedir = isset($opts['statedir']) ? $opts['statedir'] : null;
        if ($statedir !== null && !is_string($statedir)) {
            throw new LogicException('bruteforce.statedir must be a string');
        }
        $this->_statedir = $statedir !== null && $statedir !== ''
            ? rtrim($statedir, '/')
            : sys_get_temp_dir() . '/vimbadmin-bruteforce';

        if (isset($opts['ipv4_prefix'])) {
            $this->_v4prefix = self::intValue($opts['ipv4_prefix'], 'bruteforce.ipv4_prefix');
            if ($this->_v4prefix < 8 || $this->_v4prefix > 32) {
                throw new LogicException('bruteforce.ipv4_prefix must be between 8 and 32');
            }
        }
        if (isset($opts['ipv6_prefix'])) {
            $this->_v6prefix = self::intValue($opts['ipv6_prefix'], 'bruteforce.ipv6_prefix');
            if ($this->_v6prefix < 16 || $this->_v6prefix > 128) {
                throw new LogicException('bruteforce.ipv6_prefix must be between 16 and 128');
            }
        }
        if (isset($opts['max_entries'])) {
            $this->_maxEntries = self::intValue($opts['max_entries'], 'bruteforce.max_entries', 64);
        }

        if (isset($opts['whitelist'])) {
            $this->_whitelist = self::stringList($opts['whitelist'], 'bruteforce.whitelist');
        }

        // Trusted-proxy policy for resolving the real client IP (see [trustedproxy]).
        if (isset($opts['trustedproxy'])) {
            $proxy = self::stringMap($opts['trustedproxy'], 'bruteforce.trustedproxy');
            if (isset($proxy['mode'])) {
                $this->_proxyMode = self::stringValue($proxy['mode'], 'bruteforce.trustedproxy.mode');
            }
            if (isset($proxy['proxies'])) {
                $this->_proxies = self::stringList($proxy['proxies'], 'bruteforce.trustedproxy.proxies');
            }
        }
    }

    // ---- public API ----------------------------------------------------

    /**
     * Abort the request with 429 if the request's source IP is currently
     * locked out. Call early in the login flow (_preLogin).
     *
     * @throws never returns when locked (sends 429 + exits)
     * @throws RuntimeException when state cannot be read or locked
     * @param mixed $request
     * @return void
     */
    public function assertNotLocked($request)
    {
        if (!$this->_enabled) {
            return;
        }

        $ip = $this->_ip($request);
        if ($this->_isWhitelisted($ip)) {
            return;
        }

        $rec = $this->_withLock(
            $ip,
            function () use ($ip): array {
                return $this->_load($ip);
            },
            false
        );
        if ($rec['locked_until'] > time()) {
            header('HTTP/1.1 429 Too Many Requests');
            header('Retry-After: ' . max(1, $rec['locked_until'] - time()));
            echo 'Too many failed login attempts. Try again later.';
            exit;
        }
    }

    /**
     * Record one failed attempt for the request's source IP. Locks the source
     * when it crosses the threshold inside the window. Counting is per source
     * network prefix only; $username is accepted for call-site
     * symmetry/logging but not keyed on, so neither username rotation nor
     * address rotation inside one allocation escapes the same lock.
     *
     * @param mixed $username
     * @param mixed $request
     * @throws RuntimeException when state cannot be persisted or locked
     * @return void
     */
    public function record($username, $request)
    {
        if (!$this->_enabled) {
            return;
        }

        $ip = $this->_ip($request);
        if ($this->_isWhitelisted($ip)) {
            return;
        }

        $this->_maybeReapStale();
        $this->_withLock($ip, function () use ($ip): void {
            $rec = $this->_load($ip);

            // reset the counter if the window has elapsed since the first hit
            if ($rec['first'] === 0 || (time() - $rec['first']) > $this->_window) {
                $rec['first']    = time();
                $rec['attempts'] = 0;
            }

            $rec['attempts']++;
            $rec['last'] = time();

            if ($rec['attempts'] >= $this->_max) {
                $rec['locked_until'] = time() + $this->_lockout;
            }

            $this->_save($ip, $rec);
        });
    }

    /**
     * Clear the counter for a source after a fully successful login.
     *
     * @param mixed $username
     * @param mixed $request
     * @throws RuntimeException when state cannot be removed or locked
     * @return void
     */
    public function clear($username, $request)
    {
        if (!$this->_enabled) {
            return;
        }
        $ip = $this->_ip($request);
        if ($this->_isWhitelisted($ip)) {
            return;
        }
        $this->_withLock($ip, function () use ($ip): void {
            $this->_delete($ip);
        });
    }

    /**
     * Is this source currently locked? (does not change attempt state)
     *
     * @param mixed $request
     * @throws RuntimeException when state cannot be read or locked
     * @return bool
     */
    public function isLocked($request)
    {
        if (!$this->_enabled) {
            return false;
        }
        $ip = $this->_ip($request);
        if ($this->_isWhitelisted($ip)) {
            return false;
        }
        $rec = $this->_withLock(
            $ip,
            function () use ($ip): array {
                return $this->_load($ip);
            },
            false
        );
        return $rec['locked_until'] > time();
    }

    // ---- storage (one JSON file per IP under the state dir) ------------

    /**
     * @param string $ip
     * @return string
     */
    private function _file($ip)
    {
        // Hash the network key so the filename is filesystem-safe and doesn't
        // leak the raw address in a directory listing.
        return $this->_statedir . '/' . hash('sha256', $this->_key($ip)) . '.json';
    }

    /**
     * Collapse a source address onto its network prefix so that rotating
     * addresses inside one allocation cannot buy extra attempts. IPv6 hosts
     * routinely hold a whole /64 (and larger), so per-address counting made the
     * throttle a no-op there; IPv4 defaults to /24. Both widths are
     * configurable, and an unparsable address is keyed verbatim (fail closed
     * onto its own bucket rather than sharing one).
     *
     * @param string $ip
     * @return string
     */
    private function _key($ip)
    {
        $packed = @inet_pton($ip);
        if (!is_string($packed)) {
            return 'raw:' . $ip;
        }

        // An IPv4-mapped address (::ffff:a.b.c.d) is an IPv4 client wearing a
        // 16-byte representation. Masked at the IPv6 width it collapses to
        // ::/64, which would put every such client in one shared bucket and let
        // any one of them lock out all the others. Unwrap to the 4-byte form so
        // it is keyed as the IPv4 address it actually is.
        if (strlen($packed) === 16 && strncmp($packed, "\0\0\0\0\0\0\0\0\0\0\xff\xff", 12) === 0) {
            $packed = substr($packed, 12);
        }

        $bits = strlen($packed) === 4 ? $this->_v4prefix : $this->_v6prefix;
        $full = strlen($packed) * 8;
        if ($bits > $full) {
            $bits = $full;
        }

        $bytes = intdiv($bits, 8);
        $rem   = $bits % 8;
        $masked = substr($packed, 0, $bytes);
        if ($rem !== 0) {
            $masked .= chr(ord($packed[$bytes]) & (0xff << (8 - $rem) & 0xff));
        }
        $masked = str_pad($masked, strlen($packed), "\0");

        $network = @inet_ntop($masked);
        if (!is_string($network)) {
            return 'raw:' . $ip;
        }
        return $network . '/' . $bits;
    }

    /** @return void */
    private function _ensureDir()
    {
        if (!is_string($this->_statedir)) {
            throw new LogicException('bruteforce state directory is not configured');
        }
        if (!is_dir($this->_statedir)
            && (!@mkdir($this->_statedir, 0750, true) && !is_dir($this->_statedir))) {
            throw new RuntimeException('bruteforce state persistence unavailable');
        }
    }

    /**
     * Which of the fixed 256 lock shards serializes this source's record.
     *
     * The record filename is sha256(_key($ip)); taking its first byte (two hex
     * characters) gives a lock sidecar that is stable for a source, cheap to
     * derive, and -- crucially -- drawn from a FIXED set. The whole point of
     * the previous single `.lock` inode was that an attacker choosing source
     * addresses must not be able to create lock files at will; a 256-name
     * alphabet keeps that property while removing the global contention point,
     * because two unrelated prefixes now collide only 1/256 of the time
     * instead of always. Every _withLock() call site (check/record/clear/
     * isLocked) touches exactly one record, so a per-shard lock is sufficient
     * for all of them. The reaper is untouched: it keeps its own directory-wide
     * `.reap-lock` and `.reap-growth` inodes.
     *
     * The sidecars are dotfiles and the reap/eviction scan matches only
     * `^[a-f0-9]{64}\.json$`, so they are never counted as records nor evicted.
     *
     * @param string $ip
     * @return string
     */
    private function _lockFile($ip)
    {
        return $this->_statedir . '/.lock.'
            . substr(hash('sha256', $this->_key($ip)), 0, 2);
    }

    /**
     * Serialize state mutations for ONE source on its shard's stable inode.
     * The JSON files are atomically replaced, so locking them directly would
     * leave a waiter on a stale pre-rename inode; the shard sidecar is never
     * renamed, so it stays a valid rendezvous.
     *
     * Waiting strategy: try a non-blocking acquire first (the uncontended case,
     * which is nearly all of them, then costs one syscall). On contention, fall
     * back to a bounded backoff rather than the previous flat 1 ms spin. PHP's
     * flock() has no timeout, so a plain blocking LOCK_EX would hang the login
     * page forever behind a wedged holder -- the bounded-wait guarantee is
     * load-bearing and is kept. The backoff doubles from 1 ms to
     * LOCK_MAX_RETRY_MICROSECONDS (64 ms), so a full 1 s wait costs ~20
     * wakeups instead of ~1000: two orders of magnitude less FPM CPU burned per
     * wait, while the worst-case latency added by a coarse final sleep is
     * bounded by one 64 ms slice.
     *
     * @template T
     * @param string $ip source address whose record this operation touches
     * @param callable():T $operation
     * @param bool $exclusive
     * @return T
     */
    private function _withLock($ip, callable $operation, $exclusive = true)
    {
        $this->_ensureDir();
        $handle = @fopen($this->_lockFile($ip), 'c');
        if ($handle === false) {
            throw new RuntimeException('bruteforce state persistence unavailable');
        }

        try {
            $mode = ($exclusive ? LOCK_EX : LOCK_SH) | LOCK_NB;
            $deadline = hrtime(true) + self::LOCK_TIMEOUT_NANOSECONDS;
            $backoff = self::LOCK_RETRY_MICROSECONDS;
            while (!@flock($handle, $mode)) {
                if (hrtime(true) >= $deadline) {
                    throw new RuntimeException('bruteforce state persistence unavailable');
                }
                usleep($backoff);
                if ($backoff < self::LOCK_MAX_RETRY_MICROSECONDS) {
                    $backoff *= 2;
                }
            }
            return $operation();
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @param string $ip
     * @return array{attempts:int, first:int, last:int, locked_until:int}
     */
    private function _load($ip)
    {
        $default = [ 'attempts' => 0, 'first' => 0, 'last' => 0, 'locked_until' => 0 ];

        $f = $this->_file($ip);
        if (file_exists($f)) {
            $json = @file_get_contents($f);
            if (!is_string($json)) {
                throw new RuntimeException('bruteforce state persistence unavailable');
            }
            $d = json_decode($json, true);
            if (is_array($d)) {
                foreach ($default as $key => $_zero) {
                    if (!isset($d[$key]) || !is_int($d[$key]) || $d[$key] < 0) {
                        throw new LogicException('bruteforce state is corrupt');
                    }
                }
                $attempts = $d['attempts'];
                $first = $d['first'];
                $last = $d['last'];
                $lockedUntil = $d['locked_until'];
                return [ 'attempts' => $attempts, 'first' => $first, 'last' => $last, 'locked_until' => $lockedUntil ];
            }
            throw new LogicException('bruteforce state is corrupt');
        }
        return $default;
    }

    /**
     * @param string $ip
     * @param array{attempts:int, first:int, last:int, locked_until:int} $rec
     * @return void
     */
    private function _save($ip, array $rec)
    {
        $this->_ensureDir();
        $f   = $this->_file($ip);
        $tmp = $f . '.' . getmypid() . '.tmp';
        $encoded = json_encode($rec);
        if (!is_string($encoded)) {
            throw new RuntimeException('bruteforce state persistence unavailable');
        }

        // Whether this write ADDS a prefix decides how fast the directory can
        // grow, and the durable growth counter is the only thing that can call
        // a sweep in ahead of the routine interval.
        $isNew = !file_exists($f);

        try {
            // $tmp is a fresh, private per-pid path never shared with another
            // writer, so LOCK_EX buys nothing here -- only the final rename()
            // needs to be atomic, and it already is.
            if (@file_put_contents($tmp, $encoded) !== strlen($encoded)) {
                throw new RuntimeException('bruteforce state persistence unavailable');
            }
            if (!@rename($tmp, $f)) {
                throw new RuntimeException('bruteforce state persistence unavailable');
            }
            if ($isNew) {
                $this->_notePrefixCreated();
            }
        } finally {
            // @unlink() already reports absence via its own suppressed return;
            // the successful rename() above already removed $tmp in the common
            // case, so this is just best-effort cleanup after a failed write.
            @unlink($tmp);
        }
    }

    /**
     * @param string $ip
     * @return void
     */
    private function _delete($ip)
    {
        $file = $this->_file($ip);
        if (file_exists($file) && !@unlink($file)) {
            error_log('ViMbAdmin_BruteForce: could not clear state file ' . $file);
            throw new RuntimeException('bruteforce state persistence unavailable');
        }
    }

    /**
     * Record that a new prefix appeared, durably.
     *
     * The login controller builds a fresh ViMbAdmin_BruteForce per request, so
     * a growth counter on $this is always zero and can trigger nothing. The
     * routine sweep is throttled to REAP_INTERVAL_SECONDS, and a flood starting
     * from an empty directory sweeps once -- seeing one file, under cap, writing
     * no overflow marker -- and is then throttled out for a minute while
     * record() keeps creating files. Counting creations on disk is what lets a
     * burst call the sweep back in before the interval.
     *
     * Best-effort: a lost increment only delays a sweep.
     *
     * @return void
     */
    private function _notePrefixCreated()
    {
        $handle = @fopen($this->_statedir . '/.reap-growth', 'c+');
        if ($handle === false) {
            return;
        }
        try {
            if (!@flock($handle, LOCK_EX | LOCK_NB)) {
                return;
            }
            $raw = trim((string) @stream_get_contents($handle));
            $count = preg_match('/^[0-9]{1,18}$/D', $raw) === 1 ? (int) $raw + 1 : 1;
            if (@ftruncate($handle, 0)) {
                @rewind($handle);
                @fwrite($handle, (string) $count);
            }
            @flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
    }

    /**
     * How many prefixes were created since the last sweep. Read-only: the
     * counter is cleared by _clearPrefixGrowth() when a sweep actually runs,
     * because consuming it on every request would keep it pinned near zero and
     * it could never reach the threshold that calls a sweep in.
     */
    private function _readPrefixGrowth(): int
    {
        $path = $this->_statedir . '/.reap-growth';
        clearstatcache(true, $path);
        $raw = @file_get_contents($path);
        if (!is_string($raw) || preg_match('/^[0-9]{1,18}$/D', trim($raw)) !== 1) {
            return 0;
        }
        return (int) trim($raw);
    }

    /** @return void */
    private function _clearPrefixGrowth()
    {
        @unlink($this->_statedir . '/.reap-growth');
    }

    /**
     * Opportunistic maintenance. It deliberately does NOT take the state lock
     * that record()/clear() serialize on: the reaper touches only records that
     * are older than max(window, lockout), it re-stats every file to confirm
     * the inode did not change underneath it, and a losing race merely leaves a
     * stale file for the next request. Holding the exclusive lock across a
     * directory scan is what let a grown state directory turn concurrent failed
     * logins into HTTP 500s.
     *
     * The maintenance lock is non-blocking: one worker sweeps, everybody else
     * moves straight on to the state write.
     *
     * @return void
     */
    private function _maybeReapStale()
    {
        try {
            $this->_ensureDir();
        } catch (RuntimeException) {
            // Fail closed happens in the caller's own state write, which takes
            // the lock and re-runs _ensureDir().
            return;
        }

        $handle = @fopen($this->_statedir . '/.reap-lock', 'c');
        if ($handle === false) {
            return;
        }
        try {
            if (!@flock($handle, LOCK_EX | LOCK_NB)) {
                return;
            }
            try {
                // One readdir pass is O(N) even with the name cursor, so it
                // is amortised over time rather than paid on every failed
                // login: .reap-stamp's mtime records the last sweep.
                $now = time();
                $stamp = $this->_statedir . '/.reap-stamp';
                clearstatcache(true, $stamp);
                $last = @filemtime($stamp);
                // A missing stamp means "never swept" and is always due; a
                // stamp in the future (clock step) is likewise treated as due.
                //
                // The interval only throttles the *routine* sweep. record()
                // creates a state file per new prefix with no brake of its own,
                // so a flood can outrun a once-a-minute drain and grow the
                // directory without bound. .reap-overflow records that the last
                // sweep finished still above the cap; while it is set, every
                // failed login sweeps again until the directory is back under
                // max_entries.
                // The over-cap signal must be DURABLE, not instance state: the
                // login controller builds a new ViMbAdmin_BruteForce per
                // request, so anything remembered on $this is zero again by the
                // next failed login and the re-arm would never fire. This
                // marker file is written by a sweep that ended still above the
                // cap and removed by one that got under it.
                $overflowFlag = $this->_statedir . '/.reap-overflow';
                clearstatcache(true, $overflowFlag);
                $overCap = @is_file($overflowFlag);
                // A burst of NEW prefixes is the only growth record() can
                // cause, so once it could plausibly have filled the cap, sweep
                // now rather than waiting for the next interval.
                $grown = $this->_readPrefixGrowth() >= $this->_maxEntries;
                if (!$overCap && !$grown && is_int($last) && $last <= $now
                    && ($now - $last) < self::REAP_INTERVAL_SECONDS) {
                    return;
                }
                if (@touch($stamp, $now)) {
                    @chmod($stamp, 0640);
                }
                $this->_clearPrefixGrowth();
                if ($this->_reapStale($now)) {
                    // Still above the cap AND this sweep evicted something, so
                    // another one will get further: mark the directory so the
                    // next failed login sweeps again instead of waiting out the
                    // interval. A sweep that could evict nothing does not set
                    // this -- see _evictOverflow().
                    @touch($overflowFlag);
                } elseif ($overCap) {
                    @unlink($overflowFlag);
                }
            } catch (RuntimeException) {
                // Cleanup is opportunistic; the following state write
                // independently acquires the state lock and still fails closed.
            } finally {
                @flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Sweep a fixed-size window of the state directory, resuming from an opaque
     * *name* cursor rather than an ordinal one. `DirectoryIterator::seek($n)` is
     * O(n) readdir steps, so a full pass over N entries cost O(N^2 / 128) — the
     * behaviour that made a grown directory quadratic. Filenames are hex
     * digests, so "the entries whose name sorts after the last one I saw" is a
     * stable, O(N) resumption key: each pass reads the directory once, keeps the
     * REAP_SCAN_LIMIT smallest names above the cursor, and stores the last of
     * them. Every entry is still visited, including active and malformed ones,
     * so no fixed prefix can starve later stale state.
     *
     * @return bool true when the directory is STILL over the entry cap after
     *              this sweep, so the caller can re-arm without waiting out the
     *              reap interval.
     */
    private function _reapStale(int $now): bool
    {
        $directoryStat = @lstat($this->_statedir);
        if (!is_array($directoryStat) || !isset($directoryStat['mode'])
            || !is_int($directoryStat['mode']) || ($directoryStat['mode'] & 0170000) !== 0040000) {
            return false;
        }

        $cursorPath = $this->_statedir . '/.reap-cursor';
        $cursor = '';
        $rawCursor = @file_get_contents($cursorPath);
        if (is_string($rawCursor) && preg_match('/^[a-f0-9]{64}\n$/D', $rawCursor) === 1) {
            $cursor = trim($rawCursor);
        }

        $handle = @opendir($this->_statedir);
        if ($handle === false) {
            return false;
        }

        // One readdir pass; keep only the smallest REAP_SCAN_LIMIT names that
        // sort strictly after the cursor, plus a genuinely oldest-first sample
        // of EVICTABLE entries for the cap. Memory stays
        // O(REAP_SCAN_LIMIT + EVICT_SAMPLE_LIMIT).
        $window = [];
        $windowSorted = false;
        $total = 0;
        $sample = [];
        $sampleMax = null;
        try {
            while (($entry = readdir($handle)) !== false) {
                if (preg_match('/^([a-f0-9]{64})\.json$/D', $entry, $matches) !== 1) {
                    continue;
                }
                $total++;
                $name = $matches[1];

                // Eviction candidates, oldest first. Taking whatever readdir
                // happened to return first is not an ordering at all, and the
                // attacker's own record is usually among the OLDEST once they
                // start flooding -- so the sample has to be selected by age and
                // must never include a source that is currently locked out.
                // Otherwise rotating ~max_entries prefixes flushes your own
                // lockout, which is the throttle bypass this whole item exists
                // to close.
                $candidate = $this->_evictionCandidate($this->_statedir . '/' . $entry, $now);
                if ($candidate !== null) {
                    if (count($sample) < self::EVICT_SAMPLE_LIMIT) {
                        $sample[$entry] = $candidate;
                        if ($sampleMax === null || $candidate > $sampleMax) {
                            $sampleMax = $candidate;
                        }
                    } elseif ($sampleMax === null || $candidate < $sampleMax) {
                        asort($sample, SORT_NUMERIC);
                        array_pop($sample);
                        $sample[$entry] = $candidate;
                        $sampleMax = max($sample);
                    }
                }
                if ($cursor !== '' && strcmp($name, $cursor) <= 0) {
                    continue;
                }
                // Keep the REAP_SCAN_LIMIT smallest names above the cursor.
                // Once the window is full, most entries cannot make the cut, so
                // compare against the current maximum before paying for a sort
                // -- this runs once per directory entry on the failed-login path.
                if (count($window) < self::REAP_SCAN_LIMIT) {
                    $window[] = $name;
                    continue;
                }
                if (!$windowSorted) {
                    sort($window, SORT_STRING);
                    $windowSorted = true;
                }
                if (strcmp($name, (string) end($window)) >= 0) {
                    continue;
                }
                array_pop($window);
                $window[] = $name;
                sort($window, SORT_STRING);
            }
        } finally {
            closedir($handle);
        }
        sort($window, SORT_STRING);
        $window = array_slice($window, 0, self::REAP_SCAN_LIMIT);

        $cutoff = $now - max($this->_window, $this->_lockout);
        $removed = 0;
        foreach ($window as $name) {
            if ($this->_reapFile($this->_statedir . '/' . $name . '.json', $cutoff)) {
                $removed++;
            }
        }

        // A pass that reached the end of the key space starts over next time.
        $next = count($window) < self::REAP_SCAN_LIMIT ? '' : (string) end($window);
        $this->_saveReapCursor($cursorPath, $next);

        // Hard cap: stale-only reaping cannot bound a directory an attacker
        // refreshes faster than max(window, lockout). Once the cap is exceeded,
        // evict the least-recently-touched sampled entries as well, so the
        // directory (and therefore every scan over it) stays bounded.
        return $this->_evictOverflow($total - $removed, $sample, $now);
    }

    /**
     * Age of a state file for eviction ordering, or null when it must not be
     * evicted at all.
     *
     * A record whose lockout is still running is NEVER evictable: deleting it
     * hands the locked-out source a fresh attempt budget, which turns the cap
     * into a throttle-bypass primitive (flood ~max_entries prefixes, flush your
     * own lockout, repeat).
     *
     * Records that are merely COUNTING are evictable, and deliberately so. The
     * cap has to bound the directory against an attacker whose whole flood is
     * fresh -- every file inside the window, none locked out. Holding those too
     * makes nothing evictable at exactly the moment the bound is needed and
     * hands back the unbounded-growth DoS this item exists to close. Losing a
     * partial count is cheap: the source has not yet earned a lockout, and
     * oldest-mtime-first ordering evicts the quietest prefixes before any
     * source that is actively attempting.
     *
     * @return int|null mtime to order by, or null when the record is live
     */
    private function _evictionCandidate(string $path, int $now): ?int
    {
        $stat = @lstat($path);
        if (!is_array($stat) || !isset($stat['mode'], $stat['mtime'])
            || !is_int($stat['mode']) || !is_int($stat['mtime'])
            || ($stat['mode'] & 0170000) !== 0100000) {
            return null;
        }

        $json = @file_get_contents($path);
        $record = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($record)) {
            // Malformed state is not a live lockout, and _load() would refuse
            // it anyway; let the cap reclaim it.
            return $stat['mtime'];
        }

        foreach ([ 'attempts', 'first', 'last', 'locked_until' ] as $key) {
            if (!isset($record[$key]) || !is_int($record[$key]) || $record[$key] < 0) {
                return $stat['mtime'];
            }
        }

        if ($record['locked_until'] > $now) {
            return null;
        }                       // active lockout: never evict

        return $stat['mtime'];
    }

    /**
     * Evict the oldest EVICTABLE entries until the directory is back at the
     * cap. Live lockouts are excluded by _evictionCandidate(), so a flood can
     * never delete the state that is throttling it.
     *
     * The return value is "re-arming the next sweep would help", NOT "still
     * over cap". When nothing in the directory is evictable -- every record
     * carries a live lockout -- being over cap is a standing condition no
     * amount of sweeping can change, and re-arming on it would make every
     * failed login pay an unbounded O(N) scan forever. Progress is the
     * condition: report true only when this pass actually evicted something
     * and is still over cap, so the routine interval throttles the hopeless
     * case back to once per REAP_INTERVAL_SECONDS.
     *
     * @param array<string,int> $sample basename => mtime, evictable only
     * @return bool true when another sweep would make further progress
     */
    private function _evictOverflow(int $remaining, array $sample, int $now): bool
    {
        $overflow = $remaining - $this->_maxEntries;
        if ($overflow <= 0) {
            return false;
        }
        if ($sample === []) {
            // Over cap with nothing evictable: no sweep can improve this, so
            // do not re-arm.
            return false;
        }

        asort($sample, SORT_NUMERIC);

        $evicted = 0;
        foreach (array_keys($sample) as $entry) {
            if ($overflow <= 0) {
                return false;
            }
            $path = $this->_statedir . '/' . $entry;
            $before = @lstat($path);
            clearstatcache(true, $path);
            $after = @lstat($path);
            // Re-check liveness under the same stat pair: a source can have
            // become locked between the readdir sample and this deletion.
            if ($this->_isStableRegularFile($before, $after)
                && $this->_evictionCandidate($path, $now) !== null
                && @unlink($path)) {
                $overflow--;
                $evicted++;
            }
        }

        return $overflow > 0 && $evicted > 0;
    }

    private function _saveReapCursor(string $path, string $cursor): void
    {
        $tmp = $path . '.' . getmypid() . '.tmp';
        $value = $cursor . "\n";
        // $tmp is a fresh, private per-pid path: LOCK_EX is redundant, the
        // atomic rename() below is what makes the update safe to observe.
        if (@file_put_contents($tmp, $value) !== strlen($value) || !@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('bruteforce state persistence unavailable');
        }
    }

    private function _reapFile(string $path, int $cutoff): bool
    {
        $before = @lstat($path);
        if (!$this->_isStableRegularFile($before, $before)) {
            return false;
        }
        $json = @file_get_contents($path);
        $record = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($record) || !$this->_isStaleRecord($record, $cutoff)) {
            return false;
        }
        clearstatcache(true, $path);
        $after = @lstat($path);
        return $this->_isStableRegularFile($before, $after) && @unlink($path);
    }

    private function _isStableRegularFile(mixed $before, mixed $after): bool
    {
        if (!is_array($before) || !is_array($after)) {
            return false;
        }
        foreach ([ 'mode', 'dev', 'ino' ] as $key) {
            if (!isset($before[$key], $after[$key]) || !is_int($before[$key])
                || !is_int($after[$key]) || $before[$key] !== $after[$key]) {
                return false;
            }
        }
        return ($before['mode'] & 0170000) === 0100000;
    }

    /** @param array<mixed> $record */
    private function _isStaleRecord(array $record, int $cutoff): bool
    {
        foreach ([ 'attempts', 'first', 'last', 'locked_until' ] as $key) {
            if (!isset($record[$key]) || !is_int($record[$key]) || $record[$key] < 0) {
                return false;
            }
        }
        return max($record['first'], $record['last'], $record['locked_until']) < $cutoff;
    }

    // ---- helpers -------------------------------------------------------

    /**
     * @param mixed $request
     * @return string
     */
    private function _ip($request)
    {
        // Resolve the real client IP per the trusted-proxy policy (default
        // 'auto': peel X-Forwarded-For only when the direct peer is a private
        // proxy). See ViMbAdmin_Net::clientIp.
        return ViMbAdmin_Net::clientIp(self::stringMap($_SERVER, 'server parameters'), $this->_proxyMode, $this->_proxies);
    }

    /**
     * @param string $ip
     * @return bool
     */
    private function _isWhitelisted($ip)
    {
        // Shared IP/CIDR matching (see ViMbAdmin_Net) so the brute-force
        // whitelist, the MCP allowlist and the queue trigger all agree.
        return ViMbAdmin_Net::ipInList($ip, implode(' ', $this->_whitelist));
    }
}
