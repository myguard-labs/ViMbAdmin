<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\Cli\Command;

use Doctrine\Persistence\ObjectRepository;
use Entities\McpToken;
use LogicException;
use ViMbAdmin\Kernel\Cli\CliCommand;
use ViMbAdmin\Kernel\Container;

/**
 * `mcp.cli-token-revoke` — revoke an MCP API token by `--id=` or `--name=`
 * (WALL #2, docs/ZF1-REMOVAL.md). Native port of
 * `McpController::cliTokenRevokeAction` (sets the revoked flag; the row is kept
 * for audit).
 *
 * @package ViMbAdmin
 * @subpackage Kernel
 */
final class McpTokenRevokeCommand implements CliCommand
{
    #[\Override]
    public function name(): string
    {
        return 'mcp.cli-token-revoke';
    }

    #[\Override]
    public function run(Container $container, array $args): int
    {
        $entityManager = $container->entityManager();
        if (!method_exists($entityManager, 'getRepository') || !method_exists($entityManager, 'flush')) {
            throw new LogicException('MCP token revocation requires a Doctrine object manager.');
        }

        /** @var ObjectRepository<McpToken> $repository */
        $repository = $entityManager->getRepository('\\Entities\\McpToken');

        $id = self::option($args, 'id');
        $name = self::option($args, 'name');

        $tok = $id !== null ? $repository->find((int) $id)
            : ($name !== null ? $repository->findOneBy(['name' => $name]) : null);

        if (!$tok) {
            echo "ERROR: token not found (use --name or --id; see mcp.cli-token-list)\n";
            return 1;
        }

        $tok->setRevoked(true);
        $entityManager->flush();
        echo "Revoked MCP token '{$tok->getName()}' (id {$tok->getId()}).\n";

        return 0;
    }

    /** @param array<string,mixed> $args */
    private static function option(array $args, string $name): ?string
    {
        $value = $args[$name] ?? null;
        return is_string($value) && $value !== '' ? $value : null;
    }
}
