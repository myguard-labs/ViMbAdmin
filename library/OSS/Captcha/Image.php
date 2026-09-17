<?php

class OSS_Captcha_Image
{
    private const MAX_FILES = 500;

    /**
     * Live captcha entries kept in one session. A captcha that is minted but
     * never submitted is only removed by _isValid(), so without a cap an
     * unauthenticated flood of renders grows the session record forever. The
     * limit is per session and generous enough that no legitimate flow (one
     * mint per render, plus "click for a new image") ever reaches it.
     */
    private const MAX_SESSION_ENTRIES = 8;

    private const SESSION_PREFIX = 'OSS_Captcha_';

    private int $dotNoise;
    private int $lineNoise;
    private int $wordLen;
    private int $timeout;

    private static function boundedInt(mixed $value, string $name, int $minimum, int $maximum): int
    {
        if (is_string($value) && preg_match('/^[0-9]+$/D', $value)) {
            $normalized = ltrim($value, '0');
            $value = filter_var($normalized === '' ? '0' : $normalized, FILTER_VALIDATE_INT);
        }
        if (!is_int($value) || $value < $minimum || $value > $maximum) {
            throw new ValueError($name . ' is outside its permitted range');
        }
        return $value;
    }

    public function __construct(
        mixed $dotNoise = 100,
        mixed $lineNoise = 5,
        mixed $wordLen = 6,
        mixed $timeout = 1800
    ) {
        $this->dotNoise = self::boundedInt($dotNoise, 'Captcha dot noise', 0, 10000);
        $this->lineNoise = self::boundedInt($lineNoise, 'Captcha line noise', 0, 1000);
        $this->wordLen = self::boundedInt($wordLen, 'Captcha word length', 1, 64);
        $this->timeout = self::boundedInt($timeout, 'Captcha timeout', 1, 86400);
    }

    public function generate(): string
    {
        $id = bin2hex(random_bytes(16));
        if ($this->wordLen < 1) {
            throw new ValueError('Captcha word length must be greater than 0');
        }
        $word = OSS_String::randomFromSet('23456789ABCDEFGHJKLMNPQRSTUVWXYZ', $this->wordLen);

        // Drop expired entries and enforce the per-session cap BEFORE storing
        // this one, so the fresh captcha is never the entry that gets evicted.
        self::pruneSession(self::MAX_SESSION_ENTRIES - 1);

        $sessionKey = self::SESSION_PREFIX . $id;
        $_SESSION[$sessionKey] = [
            'word' => $word,
            'expires' => time() + $this->timeout,
        ];

        $dir = OSS_Utils::getTempDir() . '/captchas';
        $path = $dir . '/' . $id . '.png';
        try {
            if (!is_dir($dir)) {
                if (!mkdir($dir, 0770, true) && !is_dir($dir)) {
                    throw new RuntimeException('Unable to create captcha directory');
                }
            }
            $this->cleanup($dir);
            $this->render($path, $word);
        } catch (Throwable $error) {
            unset($_SESSION[$sessionKey]);
            @unlink($path);
            throw $error;
        }

        return $id;
    }

    /**
     * Remove expired captcha entries from the session and evict the
     * soonest-expiring survivors until at most $keep remain.
     *
     * The scan is over session keys carrying the captcha prefix only, and the
     * eviction it performs is what keeps that set at MAX_SESSION_ENTRIES, so
     * the per-call cost is bounded by the cap rather than by attacker input.
     * Malformed entries (wrong shape, missing/!int expiry) count as expired.
     */
    private static function pruneSession(int $keep): void
    {
        // No session started (CLI, or a request before session_start()): nothing
        // to prune. $_SESSION is array-typed once it exists.
        if (!isset($_SESSION)) {
            return;
        }

        $now = time();
        $live = [];
        foreach ($_SESSION as $key => $entry) {
            if (!is_string($key) || !str_starts_with($key, self::SESSION_PREFIX)) {
                continue;
            }
            $expires = is_array($entry) ? ($entry['expires'] ?? null) : null;
            if (!is_int($expires) || $expires < $now) {
                unset($_SESSION[$key]);
                continue;
            }
            $live[$key] = $expires;
        }

        $excess = count($live) - max(0, $keep);
        if ($excess <= 0) {
            return;
        }

        // Evict the entries closest to expiry first: they are the oldest mints
        // and the ones a legitimate user is least likely to still be looking at.
        asort($live);
        foreach (array_slice(array_keys($live), 0, $excess) as $key) {
            unset($_SESSION[$key]);
        }
    }

    public static function _isValid(mixed $id, mixed $value): bool
    {
        if (!is_string($id)) {
            return false;
        }
        $key = self::SESSION_PREFIX . $id;
        $captcha = $_SESSION[$key] ?? null;
        unset($_SESSION[$key]);
        $path = self::path((string) $id);
        if ($path !== null) {
            @unlink($path);
        }

        if (!is_string($value)) {
            return false;
        }

        return is_array($captcha)
            && is_int($captcha['expires'] ?? null)
            && $captcha['expires'] >= time()
            && is_string($captcha['word'] ?? null)
            && hash_equals($captcha['word'], strtoupper(trim($value)));
    }

    public static function path(string $id): ?string
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
            return null;
        }

        $path = OSS_Utils::getTempDir() . '/captchas/' . $id . '.png';

        return is_readable($path) ? $path : null;
    }

    private function render(string $path, string $word): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            throw new RuntimeException('The GD extension is required for captcha images');
        }

        $image = imagecreatetruecolor(260, 80);
        $background = imagecolorallocate($image, 248, 248, 248);
        $foreground = imagecolorallocate($image, 35, 35, 35);
        $noise = imagecolorallocate($image, 150, 150, 150);
        if ($background === false || $foreground === false || $noise === false) {
            throw new RuntimeException('Unable to allocate captcha image colors');
        }
        imagefilledrectangle($image, 0, 0, 259, 79, $background);

        for ($i = 0; $i < $this->dotNoise; $i++) {
            imagesetpixel($image, random_int(0, 259), random_int(0, 79), $noise);
        }
        for ($i = 0; $i < $this->lineNoise; $i++) {
            imageline(
                $image,
                random_int(0, 259),
                random_int(0, 79),
                random_int(0, 259),
                random_int(0, 79),
                $noise
            );
        }

        $font = dirname(__FILE__) . '/../../../data/freeserif.ttf';
        if (function_exists('imagettftext') && is_readable($font)) {
            imagettftext($image, 36, random_int(-4, 4), 24, 57, $foreground, $font, $word);
        } else {
            imagestring($image, 5, 70, 31, $word, $foreground);
        }

        try {
            if (!$this->writeImage($image, $path)) {
                throw new RuntimeException('Unable to write captcha image');
            }
        } finally {
            imagedestroy($image);
        }
    }

    protected function writeImage(GdImage $image, string $path): bool
    {
        return imagepng($image, $path);
    }

    /**
     * Filesystem sweep interval: cleanup() itself is pre-auth, unauthenticated
     * -reachable work, so the expensive glob()/filemtime() pass is amortised
     * to run at most once per this many seconds rather than on every request.
     * Expiry enforcement (the timeout check in _isValid()) is unaffected — only
     * the filesystem sweep is throttled.
     */
    private const SWEEP_INTERVAL_SECONDS = 60;

    /**
     * True at most once per SWEEP_INTERVAL_SECONDS: reads a same-directory
     * marker's mtime and, if stale (or absent), touches it and returns true.
     * The marker never suppresses cleanup indefinitely: a corrupt or
     * unwritable marker degrades to "always sweep", never to "never sweep",
     * and the path is fixed and confined to the captcha directory (no
     * attacker-controlled path component).
     */
    private static function dueForSweep(string $dir): bool
    {
        $marker = $dir . '/.sweep';
        // is_file() first: filemtime() also succeeds on a directory, and a
        // directory sitting at that path (however it got there) must not be
        // read as a fresh marker and used to suppress the sweep.
        $mtime = is_file($marker) ? @filemtime($marker) : false;
        $now = time();
        // $mtime <= $now guards against a backward clock jump: a marker
        // stamped in what is now "the future" must not be read as fresh
        // indefinitely.
        if (is_int($mtime) && $mtime <= $now && $mtime > $now - self::SWEEP_INTERVAL_SECONDS) {
            return false;
        }
        // touch() races harmlessly: worst case, two requests both sweep once.
        @touch($marker);
        return true;
    }

    private function cleanup(string $dir): void
    {
        // MAX_FILES enforcement is a disk-exhaustion cap and must run on
        // every request regardless of the sweep throttle below, or an
        // unauthenticated burst could create unbounded files during the
        // throttled window. Only the per-file expiry scan (the more
        // expensive filemtime() pass over every file) is amortised.
        $files = glob($dir . '/*.png') ?: [];
        $due = self::dueForSweep($dir);

        $mtimes = [];
        if ($due) {
            $cutoff = time() - max(1, $this->timeout);
            foreach ($files as $key => $file) {
                $mtime = (int) @filemtime($file);
                if ($mtime < $cutoff) {
                    @unlink($file);
                    unset($files[$key]);
                    continue;
                }
                $mtimes[$file] = $mtime;
            }
        }

        if (count($files) < self::MAX_FILES) {
            return;
        }

        // Without a fresh expiry pass, mtimes for the oldest-first eviction
        // below are read directly; this still runs every request that is
        // actually over the cap, sweep throttle notwithstanding.
        foreach ($files as $file) {
            if (!isset($mtimes[$file])) {
                $mtimes[$file] = (int) @filemtime($file);
            }
        }

        usort($files, static fn (string $a, string $b): int => $mtimes[$a] <=> $mtimes[$b]);

        foreach (array_slice($files, 0, count($files) - self::MAX_FILES + 1) as $file) {
            @unlink($file);
        }
    }
}
