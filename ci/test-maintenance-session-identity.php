<?php

declare(strict_types=1);

require_once __DIR__ . '/support/maintenance-session-identity.php';

(static function (): void {
    $checks = 0;
    $accept = static function (string $label, array $baseline, array $samples) use (&$checks): void {
        maintenanceSessionIdentityCheck($baseline, $samples);
        ++$checks;
        echo 'PASS ' . $label . "\n";
    };
    $reject = static function (string $label, array $baseline, array $samples, string $type, string $message) use (&$checks): void {
        try {
            maintenanceSessionIdentityCheck($baseline, $samples);
        } catch (Throwable $error) {
            if ($error instanceof $type && str_contains($error->getMessage(), $message)) {
                ++$checks;
                echo 'PASS ' . $label . "\n";
                return;
            }
            throw new RuntimeException('FAIL ' . $label . ': unexpected ' . $error::class . ': ' . $error->getMessage(), 0, $error);
        }
        throw new RuntimeException('FAIL ' . $label . ': invalid observations were accepted');
    };

    $accept('stable observer and purge connection', [11, 23], [[11, 23], [11, 23]]);
    $accept('PROCESSLIST order is immaterial', [23, 11], [[11, 23], [23, 11]]);
    $accept('one observer connection is a valid baseline', [11], [[11]]);
    $accept('one additional transient is allowed', [11, 23], [[11, 23, 41]]);
    $accept('transient IDs may change or disappear', [11, 23], [[11, 23, 41], [11, 23, 59], [11, 23]]);
    $accept('large integer connection IDs retain identity', [PHP_INT_MAX], [[PHP_INT_MAX]]);

    $reject('same-count baseline replacement', [11, 23], [[11, 41]], RuntimeException::class, 'missing baseline IDs [23]');
    $reject('missing observer despite allowed extra connection', [11, 23], [[23, 41, 59]], RuntimeException::class, 'missing baseline IDs [11]');
    $reject('later sample cannot replace a baseline connection', [11, 23], [[11, 23], [11, 41]], RuntimeException::class, 'sample 1');
    $reject('two additional connections exceed the allowance', [11, 23], [[11, 23, 41, 59]], RuntimeException::class, 'additional IDs [41,59]');
    $reject('empty sample loses baseline connections', [11, 23], [[]], RuntimeException::class, 'missing baseline IDs [11,23]');
    $reject('empty baseline cannot provide a vacuous pass', [], [[11]], InvalidArgumentException::class, 'nonempty baseline');
    $reject('missing samples cannot provide a vacuous pass', [11], [], InvalidArgumentException::class, 'nonempty baseline');
    $reject('duplicate baseline IDs', [11, 11], [[11]], InvalidArgumentException::class, 'Baseline');
    $reject('duplicate sample IDs', [11], [[11, 11]], InvalidArgumentException::class, 'Sample 0');
    $reject('string baseline ID', ['11'], [[11]], InvalidArgumentException::class, 'Baseline');
    $reject('string sample ID', [11], [['11']], InvalidArgumentException::class, 'Sample 0');
    $reject('zero baseline ID', [0], [[11]], InvalidArgumentException::class, 'positive integer');
    $reject('negative sample ID', [11], [[11, -1]], InvalidArgumentException::class, 'positive integer');
    $reject('float ID', [11.0], [[11]], InvalidArgumentException::class, 'positive integer');
    $reject('boolean ID', [true], [[11]], InvalidArgumentException::class, 'positive integer');
    $reject('null ID', [11], [[null]], InvalidArgumentException::class, 'positive integer');
    $reject('nested ID', [11], [[[11]]], InvalidArgumentException::class, 'positive integer');
    $reject('non-list baseline', [1 => 11], [[11]], InvalidArgumentException::class, 'Baseline');
    $reject('non-list sample', [11], [[1 => 11]], InvalidArgumentException::class, 'Sample 0');
    $reject('non-list sample collection', [11], [1 => [11]], InvalidArgumentException::class, 'list of ID lists');
    $reject('non-array sample', [11], [11], InvalidArgumentException::class, 'list of ID lists');

    echo 'Session identity controls passed (' . $checks . ").\n";
})();
