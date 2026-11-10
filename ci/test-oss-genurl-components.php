<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/library/OSS/Runtime.php';
require_once dirname(__DIR__) . '/library/OSS/Utils.php';

(static function (): void {
    // Explicit scheme/host avoids ambient proxy and server state. The real
    // runtime supplies the sub-path; no service or Composer dependency is used.
    OSS_Runtime::configure([], '/sentinel-base/', new stdClass());
    $host = 'https://route-components.invalid';
    /** @var array<string, array{string|bool, string}> $values */
    $values = [
        'false' => [false, ''],
        'empty' => ['', ''],
        'zero-string' => ['0', ''],
        'true' => [true, '/1'],
        'named' => ['sentinel-name', '/sentinel-name'],
    ];
    $failures = [];
    foreach ($values as $label => [$value, $segment]) {
        // Each row varies exactly one component. Exact URLs detect an extra
        // slash as well as a missing, unexpected or reordered route segment.
        $cases = [
            'action' => [
                OSS_Utils::genUrl('fixed-controller', $value, 'fixed-module', [], $host),
                $host . '/sentinel-base/fixed-module/fixed-controller' . $segment,
            ],
            'controller' => [
                OSS_Utils::genUrl($value, false, 'fixed-module', [], $host),
                $host . '/sentinel-base/fixed-module' . $segment,
            ],
            'module' => [
                OSS_Utils::genUrl('fixed-controller', 'fixed-action', $value, [], $host),
                $host . '/sentinel-base' . $segment . '/fixed-controller/fixed-action',
            ],
        ];
        foreach ($cases as $component => [$actual, $expected]) {
            if ($actual !== $expected) {
                $failures[] = $component . '/' . $label . ': expected ' . $expected . ', got ' . $actual;
            }
        }
    }
    if ($failures !== []) {
        throw new RuntimeException(implode("\n", $failures));
    }
})();

echo "ALL PASSED (15 checks)\n";
