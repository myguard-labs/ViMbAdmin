<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\Cli\Command;

use ViMbAdmin\Kernel\Cli\CliCommand;
use ViMbAdmin\Kernel\Container;

/**
 * `mcp.cli-token-generate` — mint a new MCP API token (WALL #2,
 * docs/ZF1-REMOVAL.md). Native port of `McpController::cliTokenGenerateAction`.
 * The raw token is printed ONCE; only its SHA-256 is stored. A free (or already
 * revoked) `--name` is required.
 *
 * Options: `--name=` (required), `--scope=` (default `read`), `--ip=`,
 * `--domains=`, `--days=` (validity; default no expiry).
 *
 * @package ViMbAdmin
 * @subpackage Kernel
 */
final class McpTokenGenerateCommand implements CliCommand
{
    public function name(): string
    {
        return 'mcp.cli-token-generate';
    }

    public function run(Container $container, array $args): int
    {
        $opt = static function (string $k) use ($args): ?string {
            return (isset($args[$k]) && is_string($args[$k]) && $args[$k] !== '') ? $args[$k] : null;
        };

        $name = $opt('name');
        if ($name === null) {
            echo "ERROR: --name is required\n";
            return 1;
        }

        $em  = $container->entityManager();
        $old = $em->getRepository('\\Entities\\McpToken')->findByName($name);
        if ($old !== null) {
            if (!$old->getRevoked()) {
                echo "ERROR: an active token named '{$name}' already exists (revoke it first)\n";
                return 1;
            }
            // name is free to reuse: drop the old revoked row
            $em->remove($old);
            $em->flush();
        }

        $raw = bin2hex(random_bytes(32));
        $tok = new \Entities\McpToken();
        $tok->setName($name);
        $tok->setTokenHash(hash('sha256', $raw));
        $tok->setScope($opt('scope') ?: 'read');
        $tok->setAllowedIps($opt('ip'));
        $tok->setAllowedDomains($opt('domains'));
        $tok->setCreated(new \DateTime());
        $tok->setRevoked(false);

        $days = $opt('days');
        if ($days !== null && (int) $days > 0) {
            $tok->setExpiresAt((new \DateTime())->modify('+' . (int) $days . ' days'));
        }

        $em->persist($tok);
        $em->flush();

        echo "MCP token '{$name}' created. Scope: {$tok->getScope()}.";
        echo $tok->getAllowedIps() ? " IPs: {$tok->getAllowedIps()}." : ' IPs: any.';
        echo $tok->getAllowedDomains() ? " Domains: {$tok->getAllowedDomains()}." : ' Domains: all.';
        echo $tok->getExpiresAt() ? ' Expires: ' . $tok->getExpiresAt()->format('Y-m-d') . ".\n" : " No expiry.\n";
        echo "\n  TOKEN (shown once, store it now):\n\n    {$raw}\n\n";
        echo "Use it as:  Authorization: Bearer {$raw}\n";

        return 0;
    }
}
