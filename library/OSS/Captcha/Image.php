<?php

class OSS_Captcha_Image
{
    private const MAX_FILES = 500;

    private int $dotNoise;
    private int $lineNoise;
    private int $wordLen;
    private int $timeout;

    public function __construct($dotNoise = 100, $lineNoise = 5, $wordLen = 6, $timeout = 1800)
    {
        $this->dotNoise = (int) $dotNoise;
        $this->lineNoise = (int) $lineNoise;
        $this->wordLen = (int) $wordLen;
        $this->timeout = (int) $timeout;
    }

    public function generate(): string
    {
        $id = bin2hex(random_bytes(16));
        $word = substr(strtoupper(bin2hex(random_bytes($this->wordLen))), 0, $this->wordLen);

        $_SESSION['OSS_Captcha_' . $id] = [
            'word' => $word,
            'expires' => time() + $this->timeout,
        ];

        $dir = OSS_Utils::getTempDir() . '/captchas';
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0770, true) && !is_dir($dir)) {
                throw new RuntimeException('Unable to create captcha directory');
            }
        }
        $this->cleanup($dir);
        $this->render($dir . '/' . $id . '.png', $word);

        return $id;
    }

    public static function _isValid($id, $value): bool
    {
        $key = 'OSS_Captcha_' . (string) $id;
        $captcha = $_SESSION[$key] ?? null;
        unset($_SESSION[$key]);
        $path = self::path((string) $id);
        if ($path !== null) {
            @unlink($path);
        }

        return is_array($captcha)
            && (int) ($captcha['expires'] ?? 0) >= time()
            && hash_equals((string) ($captcha['word'] ?? ''), strtoupper(trim((string) $value)));
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

        imagepng($image, $path);
        imagedestroy($image);
    }

    private function cleanup(string $dir): void
    {
        $files = glob($dir . '/*.png') ?: [];
        $cutoff = time() - max(1, $this->timeout);

        foreach ($files as $key => $file) {
            if ((int) @filemtime($file) < $cutoff) {
                @unlink($file);
                unset($files[$key]);
            }
        }

        if (count($files) < self::MAX_FILES) {
            return;
        }

        usort($files, static fn(string $a, string $b): int =>
            ((int) @filemtime($a)) <=> ((int) @filemtime($b))
        );

        foreach (array_slice($files, 0, count($files) - self::MAX_FILES + 1) as $file) {
            @unlink($file);
        }
    }
}
