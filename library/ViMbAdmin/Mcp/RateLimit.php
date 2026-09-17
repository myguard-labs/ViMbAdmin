<?php

/**
 * Per-token sliding-window rate limiter for the MCP adapter.
 *
 * Used to cap destructive operations (archive / restore / delete) so a
 * compromised or buggy client can't mass-destroy mailboxes. File-based state
 * (one small JSON file per token+bucket) under var/ -- no extra service.
 */
class ViMbAdmin_Mcp_RateLimit
{
    /** @var string */
    private $_dir;
    /** @var int */
    private $_max;
    /** @var int */
    private $_window;

    /**
     * @param array{
     *     statedir?: string|null,
     *     max?: mixed,
     *     window?: mixed
     * } $opts
     */
    public function __construct(array $opts = [])
    {
        $this->_dir    = isset($opts['statedir']) && $opts['statedir']
                       ? rtrim($opts['statedir'], '/')
                       : dirname(__DIR__, 3) . '/var/mcp-ratelimit';
        $this->_max    = $this->_integerOption($opts, 'max', 10);
        $this->_window = $this->_integerOption($opts, 'window', 3600);
        if ($this->_max < 0) {
            throw new ViMbAdmin_Mcp_Exception('destructive rate-limit max must be zero or greater', 503);
        }
        if ($this->_window < 1) {
            throw new ViMbAdmin_Mcp_Exception('destructive rate-limit window must be at least one second', 503);
        }
    }

    /**
     * Record one destructive hit for $tokenId and throw if the limit is now
     * exceeded inside the window. Call this BEFORE doing the destructive work.
     *
     * @throws ViMbAdmin_Mcp_Exception (429) when over the limit
     */
    public function hit(int|string|null $tokenId, string $bucket = 'destructive'): void
    {
        if ($this->_max === 0) {          // Explicitly configured zero disables the limiter.
            return;
        }

        $now  = time();
        $file = $this->_file($tokenId, $bucket);

        // The whole read-modify-write must be atomic, otherwise two concurrent
        // destructive calls can each read a sub-limit count and both proceed,
        // letting a compromised client slip past the cap. Hold an exclusive
        // flock on the state file for the entire check+record.
        // Lock a stable sidecar inode. The state file itself is atomically
        // replaced, so locking it would let waiters retain and later overwrite
        // a stale pre-rename inode.
        $lockFile = $file . '.lock';
        $fh = @fopen($lockFile, 'c+');
        if ($fh === false) {
            error_log("ViMbAdmin_Mcp_RateLimit: cannot open lock file {$lockFile} — destructive operation denied for token {$tokenId}");
            throw new ViMbAdmin_Mcp_Exception('destructive rate limit unavailable', 503);
        }

        $temporary = null;
        try {
            if (!flock($fh, LOCK_EX)) {
                error_log("ViMbAdmin_Mcp_RateLimit: cannot lock {$lockFile} — destructive operation denied for token {$tokenId}");
                throw new ViMbAdmin_Mcp_Exception('destructive rate limit unavailable', 503);
            }

            $exists = is_file($file);
            $raw = $exists ? file_get_contents($file) : '';
            if (!is_string($raw)) {
                throw new ViMbAdmin_Mcp_Exception('destructive rate limit unavailable', 503);
            }
            if (!$exists) {
                $hits = [];
            } else {
                $hits = json_decode($raw, true);
                if (!is_array($hits) || !array_is_list($hits)) {
                    throw new ViMbAdmin_Mcp_Exception('destructive rate limit state is invalid', 503);
                }
            }

            // drop entries outside the window
            $activeHits = [];
            foreach ($hits as $t) {
                if (is_int($t)) {
                    $timestamp = $t;
                } elseif (is_string($t) && preg_match('/^[0-9]+$/D', $t) === 1) {
                    $parsed = filter_var($t, FILTER_VALIDATE_INT);
                    if (!is_int($parsed)) {
                        throw new ViMbAdmin_Mcp_Exception('destructive rate limit state is invalid', 503);
                    }
                    $timestamp = $parsed;
                } else {
                    throw new ViMbAdmin_Mcp_Exception('destructive rate limit state is invalid', 503);
                }

                if (($now - $timestamp) < $this->_window) {
                    $activeHits[] = $timestamp;
                }
            }
            $hits = $activeHits;

            if (count($hits) >= $this->_max) {
                throw new ViMbAdmin_Mcp_Exception(
                    "rate limit: max {$this->_max} destructive operations per {$this->_window}s",
                    429
                );
            }

            $hits[] = $now;

            $encoded = json_encode($hits);
            if ($encoded === false) {
                error_log("ViMbAdmin_Mcp_RateLimit: cannot encode state for {$file} — destructive operation denied for token {$tokenId}");
                throw new ViMbAdmin_Mcp_Exception('destructive rate limit unavailable', 503);
            }

            $temporary = tempnam(dirname($file), basename($file) . '.tmp-');
            if ($temporary === false
                || file_put_contents($temporary, $encoded, LOCK_EX) !== strlen($encoded)
                || !rename($temporary, $file)) {
                throw new ViMbAdmin_Mcp_Exception('destructive rate limit unavailable', 503);
            }
            $temporary = null;
        } finally {
            if (is_string($temporary) && is_file($temporary)) {
                @unlink($temporary);
            }
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    // ---- internals -----------------------------------------------------

    private function _file(int|string|null $tokenId, string $bucket): string
    {
        if (!is_dir($this->_dir)) {
            @mkdir($this->_dir, 0750, true);
        }
        clearstatcache(true, $this->_dir);
        if (!$this->_safeDirectoryChain()) {
            error_log("ViMbAdmin_Mcp_RateLimit: unsafe state directory {$this->_dir} — destructive operation denied for token {$tokenId}");
            throw new ViMbAdmin_Mcp_Exception('destructive rate-limit state directory is unsafe', 503);
        }
        return $this->_dir . '/' . (int) $tokenId . '-' . preg_replace('/[^a-z0-9]/', '', $bucket) . '.json';
    }

    /**
     * The final state directory must not be writable by its group or by other
     * users. Ancestors may be group-writable (deployment groups are trusted),
     * but world-writable ancestors must carry the sticky bit. Every component
     * must be a real directory rather than a symlink. This narrows pathname
     * substitution exposure, but PHP's pathname APIs cannot eliminate TOCTOU;
     * operators must place state on a trusted filesystem.
     */
    private function _safeDirectoryChain(): bool
    {
        $path = $this->_dir;
        $final = true;
        while (true) {
            $stat = @lstat($path);
            if (!is_array($stat)) {
                return false;
            }

            if (!$this->_safeDirectoryStat($stat, $final)) {
                return false;
            }

            $parent = dirname($path);
            if ($parent === $path) {
                return true;
            }
            $path = $parent;
            $final = false;
        }
    }

    /** @param array<string|int,mixed> $stat */
    private function _safeDirectoryStat(array $stat, bool $final): bool
    {
        $mode = $stat['mode'];
        if (!is_int($mode) || ($mode & 0170000) !== 0040000) {
            return false;
        }
        if ($final) {
            return ($mode & 0022) === 0;
        }

        $worldWritable = ($mode & 0002) !== 0;
        $sticky = ($mode & 01000) !== 0;
        return !$worldWritable || $sticky;
    }

    /** @param array<string,mixed> $opts */
    private function _integerOption(array $opts, string $name, int $default): int
    {
        if (!array_key_exists($name, $opts)) {
            return $default;
        }

        $value = $opts[$name];
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?(?:0|[1-9][0-9]*)$/D', $value) === 1) {
            $parsed = filter_var($value, FILTER_VALIDATE_INT);
            if (is_int($parsed)) {
                return $parsed;
            }
        }

        throw new ViMbAdmin_Mcp_Exception("destructive rate-limit {$name} must be an integer", 503);
    }
}
