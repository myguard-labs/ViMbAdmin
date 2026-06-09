<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\Cli;

use ViMbAdmin\Kernel\Bootstrap;
use ViMbAdmin\Kernel\Container;
use ViMbAdmin\Kernel\Cli\Command\DeletePendingCommand;
use ViMbAdmin\Kernel\Cli\Command\McpTokenGenerateCommand;
use ViMbAdmin\Kernel\Cli\Command\McpTokenListCommand;
use ViMbAdmin\Kernel\Cli\Command\McpTokenRevokeCommand;
use ViMbAdmin\Kernel\Cli\Command\PrecompileTemplatesCommand;
use ViMbAdmin\Kernel\Cli\Command\QueueRunCommand;
use ViMbAdmin\Kernel\Cli\Command\ResetTotpCommand;
use ViMbAdmin\Kernel\Cli\Command\SchemaUpdateCommand;

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
        // Register each migrated command. An unregistered name falls back to the
        // ZF1 CLI in vimbtool.php. The whole cli-* tail is now native.
        $registered = [
            new QueueRunCommand(),
            new ResetTotpCommand(),
            new DeletePendingCommand(),
            new SchemaUpdateCommand(),
            new PrecompileTemplatesCommand(),
            new McpTokenGenerateCommand(),
            new McpTokenListCommand(),
            new McpTokenRevokeCommand(),
        ];
        foreach ($registered as $command) {
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
     * Build the native resources (config + Doctrine EM; no session under CLI).
     *
     * Exposed separately from {@see run()} so the entry point can wire the
     * residual legacy glue some library classes still read (e.g. the entity
     * preference layer `OSS_Doctrine2_WithPreferences` fetches the EM from the
     * `d2em` registry) around the booted container — the same split the web entry
     * point uses. The identity-namespace argument is irrelevant under CLI (no
     * command authenticates and boot() starts no session for the CLI SAPI), so a
     * neutral placeholder is passed.
     */
    public function boot(): Container
    {
        return Bootstrap::boot($this->appPath, $this->env, 'cli');
    }

    /**
     * Run $action against the (already booted) container, returning its process
     * exit code. Caller must have checked {@see canHandle()} first.
     *
     * @param array<string,mixed> $args the parsed argv (see {@see CliCommand::run()})
     */
    public function run(string $action, array $args, Container $container): int
    {
        $command = $this->commands[$action] ?? null;
        if ($command === null) {
            return 1;
        }

        return $command->run($container, $args);
    }
}
