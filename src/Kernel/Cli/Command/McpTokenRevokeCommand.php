<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\Cli\Command;

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
    public function name(): string
    {
        return 'mcp.cli-token-revoke';
    }

    public function run(Container $container, array $args): int
    {
        $em   = $container->entityManager();
        $repo = $em->getRepository('\\Entities\\McpToken');

        $id   = (isset($args['id']) && is_string($args['id']) && $args['id'] !== '') ? $args['id'] : null;
        $name = (isset($args['name']) && is_string($args['name']) && $args['name'] !== '') ? $args['name'] : null;

        $tok = $id !== null ? $repo->find((int) $id)
            : ($name !== null ? $repo->findByName($name) : null);

        if (!$tok) {
            echo "ERROR: token not found (use --name or --id; see mcp.cli-token-list)\n";
            return 1;
        }

        $tok->setRevoked(true);
        $em->flush();
        echo "Revoked MCP token '{$tok->getName()}' (id {$tok->getId()}).\n";

        return 0;
    }
}
