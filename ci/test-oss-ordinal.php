<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/library/OSS/Utils.php';

(static function (): void {
    /** @var array<string, array{int, string}> $cases */
    $cases = [
        'zero' => [0, '0th'],
        'one' => [1, '1st'],
        'two' => [2, '2nd'],
        'three' => [3, '3rd'],
        'four' => [4, '4th'],
        'nine' => [9, '9th'],
        'ten' => [10, '10th'],
        'eleven' => [11, '11th'],
        'twelve' => [12, '12th'],
        'thirteen' => [13, '13th'],
        'fourteen' => [14, '14th'],
        'nineteen' => [19, '19th'],
        'twenty' => [20, '20th'],
        'twenty-one' => [21, '21st'],
        'twenty-two' => [22, '22nd'],
        'twenty-three' => [23, '23rd'],
        'twenty-four' => [24, '24th'],
        'hundred' => [100, '100th'],
        'hundred-one' => [101, '101st'],
        'hundred-eleven' => [111, '111th'],
        'hundred-twelve' => [112, '112th'],
        'hundred-thirteen' => [113, '113th'],
        'hundred-twenty-one' => [121, '121st'],
        'negative-one' => [-1, '-1st'],
        'negative-two' => [-2, '-2nd'],
        'negative-three' => [-3, '-3rd'],
        'negative-four' => [-4, '-4th'],
        'negative-nine' => [-9, '-9th'],
        'negative-ten' => [-10, '-10th'],
        'negative-eleven' => [-11, '-11th'],
        'negative-twelve' => [-12, '-12th'],
        'negative-thirteen' => [-13, '-13th'],
        'negative-fourteen' => [-14, '-14th'],
        'negative-nineteen' => [-19, '-19th'],
        'negative-twenty' => [-20, '-20th'],
        'negative-twenty-one' => [-21, '-21st'],
        'negative-twenty-two' => [-22, '-22nd'],
        'negative-twenty-three' => [-23, '-23rd'],
        'negative-twenty-four' => [-24, '-24th'],
        'negative-hundred' => [-100, '-100th'],
        'negative-hundred-one' => [-101, '-101st'],
        'negative-hundred-eleven' => [-111, '-111th'],
        'negative-hundred-twelve' => [-112, '-112th'],
        'negative-hundred-thirteen' => [-113, '-113th'],
        'negative-hundred-twenty-one' => [-121, '-121st'],
        'minimum-integer' => [PHP_INT_MIN, PHP_INT_MIN . 'th'],
        'maximum-integer' => [PHP_INT_MAX, PHP_INT_MAX . 'th'],
    ];
    $failures = [];

    set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
        throw new ErrorException($message, 0, $severity, $file, $line);
    }, E_WARNING);

    try {
        foreach ($cases as $label => [$number, $expected]) {
            try {
                $actual = OSS_Utils::ordinal($number);
            } catch (Throwable $exception) {
                $failures[] = $label . ': ' . $exception->getMessage();
                continue;
            }

            if ($actual !== $expected) {
                $failures[] = $label . ': expected ' . $expected . ', got ' . $actual;
            }
        }
    } finally {
        restore_error_handler();
    }

    if ($failures !== []) {
        foreach ($failures as $failure) {
            fwrite(STDERR, 'FAIL ordinal ' . $failure . "\n");
        }
        fwrite(STDERR, count($failures) . '/' . count($cases) . " ordinal checks failed\n");
        exit(1);
    }

    echo 'ALL PASSED (' . count($cases) . " checks)\n";
})();
