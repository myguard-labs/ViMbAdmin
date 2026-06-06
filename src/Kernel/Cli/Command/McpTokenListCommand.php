<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\Cli\Command;

use ViMbAdmin\Kernel\Cli\CliCommand;
use ViMbAdmin\Kernel\Container;

/**
 * `mcp.cli-token-list` — list the MCP API tokens (WALL #2,
 * docs/ZF1-REMOVAL.md). Native port of `McpController::cliTokenListAction`.
 *
 * @package ViMbAdmin
 * @subpackage Kernel
 */
final class McpTokenListCommand implements CliCommand
{
    public function name(): string
    {
        return 'mcp.cli-token-list';
    }

    public function run(Container $container, array $args): int
    {
        $tokens = $container->entityManager()
            ->getRepository('\\Entities\\McpToken')
            ->findBy([], ['id' => 'ASC']);

        if (!$tokens) {
            echo "No MCP tokens.\n";
            return 0;
        }

        printf("%-4s %-20s %-12s %-20s %-20s %-10s %-19s\n", 'ID', 'NAME', 'SCOPE', 'IPS', 'DOMAINS', 'STATE', 'LAST USED');
        foreach ($tokens as $t) {
            printf(
                "%-4d %-20s %-12s %-20s %-20s %-10s %-19s\n",
                $t->getId(),
                $t->getName(),
                $t->getScope(),
                $t->getAllowedIps() ?: 'any',
                $t->getAllowedDomains() ?: 'all',
                $t->getRevoked() ? 'revoked' : ($t->isActive() ? 'active' : 'expired'),
                $t->getLastUsedAt() ? $t->getLastUsedAt()->format('Y-m-d H:i:s') : '-'
            );
        }

        return 0;
    }
}
