<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\Cli\Command;

use Doctrine\Persistence\ObjectRepository;
use Entities\McpToken;
use LogicException;
use Override;
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
    #[Override]
    public function name(): string
    {
        return 'mcp.cli-token-list';
    }

    #[Override]
    public function run(Container $container, array $args): int
    {
        $entityManager = $container->entityManager();
        if (!method_exists($entityManager, 'getRepository')) {
            throw new LogicException('MCP token listing requires a Doctrine object manager.');
        }

        /** @var ObjectRepository<McpToken> $repository */
        $repository = $entityManager->getRepository('\\Entities\\McpToken');
        $tokens = $repository->findBy([], ['id' => 'ASC']);

        if (!$tokens) {
            echo "No MCP tokens.\n";
            return 0;
        }

        printf("%-4s %-20s %-12s %-20s %-20s %-10s %-19s\n", 'ID', 'NAME', 'SCOPE', 'IPS', 'DOMAINS', 'STATE', 'LAST USED');
        foreach ($tokens as $t) {
            $allowedIps = $t->getAllowedIps();
            $allowedDomains = $t->getAllowedDomains();
            printf(
                "%-4d %-20s %-12s %-20s %-20s %-10s %-19s\n",
                $t->getId(),
                $t->getName(),
                $t->getScope(),
                ($allowedIps ?? '') !== '' ? $allowedIps : 'any',
                ($allowedDomains ?? '') !== '' ? $allowedDomains : 'all',
                $t->getRevoked() ? 'revoked' : ($t->isActive() ? 'active' : 'expired'),
                $t->getLastUsedAt() ? $t->getLastUsedAt()->format('Y-m-d H:i:s') : '-'
            );
        }

        return 0;
    }
}
