<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\Cli;

use ViMbAdmin\Kernel\Bootstrap;
use ViMbAdmin\Kernel\Cli\Command\QueueRunCommand;

/**
 * Framework-free CLI dispatcher (WALL #2, docs/ZF1-REMOVAL.md).
 *
 * The CLI counterpart of {@see \ViMbAdmin\Kernel\Http\Kernel}: it owns the map of
 * the `controller.action` names that have been ported off the ZF1 CLI
 * (`bin/vimbtool.php` → the ZF1 application + `OSS_Controller_Router_Cli`) onto
 * native {@see CliCommand}s. `vimbtool.php` asks {@see canHandle()} first and only
 * boots the ZF1 application for a command not yet migrated — the same opt-in,
 * fall-back-to-ZF1 strangler the web kernel used, so the CLI migrates one command
 * at a time with nothing else disturbed.
 *
 * {@see Bootstrap::boot()} already skips the session under `PHP_SAPI === 'cli'`,
 * so the native resources (config + Doctrine EM) build cleanly with no web
 * scaffolding; CLI commands use the EM + options and never touch session/auth.
 *
 * @package ViMbAdmin
 * @subpackage Kernel
 */
final class CliKernel
{
    /** @var array<string,CliCommand> name => command */
    private array $commands = [];

    public function __construct(
        private readonly string $appPath,
        private readonly string $env,
    ) {
        // Register each migrated command. Grow this list as the cli-* tail moves
        // off ZF1 (cli-reset-totp, cli-delete-pending, cli-schema-update,
        // mcp.cli-token-*); an unregistered name falls back to ZF1 in vimbtool.php.
        foreach ([new QueueRunCommand()] as $command) {
            $this->commands[$command->name()] = $command;
        }
    }

    /**
     * Whether $action (a `controller.action` name) is served natively. Pure — no
     * resource is built, so `vimbtool.php` can cheaply decide before booting.
     */
    public function canHandle(string $action): bool
    {
        return isset($this->commands[$action]);
    }

    /**
     * The names of every natively-served command (for tests / introspection).
     *
     * @return list<string>
     */
    public function commands(): array
    {
        return array_keys($this->commands);
    }

    /**
     * Boot the native resources and run $action, returning its process exit code.
     * Caller must have checked {@see canHandle()} first.
     *
     * @param array<string,mixed> $args the parsed argv (see {@see CliCommand::run()})
     */
    public function run(string $action, array $args): int
    {
        $command = $this->commands[$action] ?? null;
        if ($command === null) {
            return 1;
        }

        // The identity-namespace argument is irrelevant under CLI: no command
        // reads auth and boot() starts no session for the CLI SAPI, so the auth
        // bridge is built but never used. Pass a neutral placeholder.
        $container = Bootstrap::boot($this->appPath, $this->env, 'cli');

        return $command->run($container, $args);
    }
}
