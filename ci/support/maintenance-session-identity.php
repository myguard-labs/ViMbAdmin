<?php

declare(strict_types=1);

use InvalidArgumentException as InvalidSessionIdentity;
use RuntimeException as SessionIdentityViolation;

/**
 * @param array<array-key, mixed> $ids
 * @return array<int, true>
 */
function maintenanceSessionIdSet(array $ids, string $label): array
{
    $set = [];
    $offset = 0;
    foreach ($ids as $key => $id) {
        if ($key !== $offset++ || !is_int($id) || $id <= 0 || isset($set[$id])) {
            throw new InvalidSessionIdentity($label . ' must contain unique positive integer session IDs in a list');
        }
        $set[$id] = true;
    }
    return $set;
}

/**
 * Check the disposable fixture's PROCESSLIST snapshots, not production sessions.
 * The baseline is captured after opening the purge manager's connection and
 * includes the observer connection used to collect every sample. Those existing
 * connections must persist. Each sample may contain one additional transient
 * connection; its ID need not remain the same between acquisitions.
 *
 * These inputs are validated here, so malformed lists and IDs must remain
 * representable in the input types rather than being assumed correct.
 *
 * @param array<array-key, mixed> $baseline
 * @param array<array-key, mixed> $samples
 */
function maintenanceSessionIdentityCheck(array $baseline, array $samples): void
{
    $baselineSet = maintenanceSessionIdSet($baseline, 'Baseline');
    if ($baselineSet === [] || $samples === []) {
        throw new InvalidSessionIdentity('Session identity observations require a nonempty baseline and samples');
    }
    $offset = 0;
    foreach ($samples as $key => $ids) {
        if ($key !== $offset++ || !is_array($ids)) {
            throw new InvalidSessionIdentity('Session identity samples must be a list of ID lists');
        }
        $sampleSet = maintenanceSessionIdSet($ids, 'Sample ' . $key);
        $missing = array_diff_key($baselineSet, $sampleSet);
        $additional = array_diff_key($sampleSet, $baselineSet);
        if ($missing !== [] || count($additional) > 1) {
            throw new SessionIdentityViolation('Fixture session identity changed in sample ' . $key
                . ': missing baseline IDs ' . json_encode(array_keys($missing), JSON_THROW_ON_ERROR)
                . '; additional IDs ' . json_encode(array_keys($additional), JSON_THROW_ON_ERROR));
        }
    }
}
