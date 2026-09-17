<?php

declare(strict_types=1);

require_once __DIR__ . '/../application/Entities/DirectoryEntry.php';

final class DirectoryEntryJpegTestState
{
    public static int $checks = 0;
    public static int $failures = 0;
}

function directoryEntryJpegCheck(string $label, bool $ok): void
{
    DirectoryEntryJpegTestState::$checks++;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$ok) {
        DirectoryEntryJpegTestState::$failures++;
    }
}

echo "== directory entry JPEG contract ==\n";

$entry = new \Entities\DirectoryEntry();
directoryEntryJpegCheck(
    'pre-hydration JPEG value preserves null',
    $entry->getJpegPhoto() === null
);

$binary = "\xff\x00jpeg-bytes";
directoryEntryJpegCheck(
    'setter is fluent and preserves binary string bytes',
    $entry->setJpegPhoto($binary) === $entry && $entry->getJpegPhoto() === $binary
);

$entry->setJpegPhoto(false);
directoryEntryJpegCheck(
    'false remains distinct from a missing JPEG value',
    $entry->getJpegPhoto() === false
);

$array = ['legacy' => 'serialized-value'];
$entry->setJpegPhoto($array);
directoryEntryJpegCheck(
    'legacy array value is not coerced or replaced',
    $entry->getJpegPhoto() === $array
);

$object = new stdClass();
$object->bytes = 'legacy-object';
$entry->setJpegPhoto($object);
directoryEntryJpegCheck(
    'legacy object identity is preserved in memory',
    $entry->getJpegPhoto() === $object
);

directoryEntryJpegCheck('fixed assertion count', DirectoryEntryJpegTestState::$checks === 5);

echo DirectoryEntryJpegTestState::$failures === 0
    ? "ALL PASSED\n"
    : DirectoryEntryJpegTestState::$failures . " FAILED\n";
exit(DirectoryEntryJpegTestState::$failures === 0 ? 0 : 1);
