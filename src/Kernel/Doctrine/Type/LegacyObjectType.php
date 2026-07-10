<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\Doctrine\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;

use function is_resource;
use function restore_error_handler;
use function serialize;
use function set_error_handler;
use function stream_get_contents;
use function unserialize;

/**
 * Backwards-compatible port of DBAL 3's `object` type, which DBAL 4 removed
 * (the maintainers consider PHP `serialize()` in the database unsafe).
 *
 * ViMbAdmin's `Entities\DirectoryEntry::$jpegPhoto` is mapped `type="object"`
 * and existing rows hold `serialize()`d values in a `longtext` column. Dropping
 * the type would make those rows unreadable, so this replicates the exact DBAL 3
 * behaviour (serialize on write, unserialize on read over a CLOB) and is
 * registered under the name `object` by {@see \ViMbAdmin\Kernel\Doctrine\EntityManagerFactory}.
 *
 * Not a feature — a compatibility shim kept until the column is migrated to a
 * first-class type (e.g. blob/text) with a data conversion.
 *
 * @package ViMbAdmin
 * @subpackage Kernel
 */
final class LegacyObjectType extends Type
{
    public const NAME = 'object';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getClobTypeDeclarationSQL($column);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): string
    {
        return serialize($value);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_resource($value)) {
            $value = stream_get_contents($value);
        }

        // Mirror DBAL 3 ConversionException-on-corruption: turn the unserialize
        // warning into a throwable so a bad row fails loudly, not silently false.
        set_error_handler(static function (int $code, string $message): bool {
            throw new \RuntimeException('Could not unserialize object column: ' . $message);
        });

        try {
            // The column only ever holds a serialized SCALAR (DirectoryEntry
            // jpegPhoto bytes), so forbid object instantiation — neutralises the
            // PHP object-injection (POP-gadget) sink if a row ever carries crafted
            // `O:` bytes, with no behaviour change for the legitimate scalar case.
            return unserialize((string) $value, ['allowed_classes' => false]);
        } finally {
            restore_error_handler();
        }
    }
}
