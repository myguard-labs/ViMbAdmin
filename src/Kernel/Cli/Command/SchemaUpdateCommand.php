<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\Cli\Command;

use Doctrine\ORM\EntityManagerInterface;
use ViMbAdmin_Schema;
use ViMbAdmin\Kernel\Cli\CliCommand;
use ViMbAdmin\Kernel\Container;

/**
 * `maintenance.cli-schema-update` — apply pending Doctrine schema migrations
 * (WALL #2, docs/ZF1-REMOVAL.md). Native port of
 * `MaintenanceController::cliSchemaUpdateAction`, over the same framework-free
 * {@see \ViMbAdmin_Schema} the web maintenance schema-update (#72) uses. DDL —
 * run on deploy/upgrade.
 *
 * @package ViMbAdmin
 * @subpackage Kernel
 */
final class SchemaUpdateCommand implements CliCommand
{
    #[\Override]
    public function name(): string
    {
        return 'maintenance.cli-schema-update';
    }

    #[\Override]
    public function run(Container $container, array $args): int
    {
        $verbose = array_key_exists('v', $args) || array_key_exists('verbose', $args);

        try {
            /** @var EntityManagerInterface $entityManager */
            $entityManager = $container->entityManager();
            $res = (new ViMbAdmin_Schema($entityManager))->migrate();
        } catch (\Throwable $e) {
            echo 'ERROR: schema update failed: ' . $e->getMessage() . "\n";
            return 1;
        }

        if ($verbose) {
            if (!empty($res['applied'])) {
                echo "Applied {$res['applied']} schema statement(s):\n";
                foreach (($res['statements'] ?? []) as $s) {
                    echo '  ' . $s . "\n";
                }
            } else {
                echo "Schema already up to date.\n";
            }
            echo 'DB version: ' . ($res['version'] ?? '?') . "\n";
        }

        return 0;
    }
}
