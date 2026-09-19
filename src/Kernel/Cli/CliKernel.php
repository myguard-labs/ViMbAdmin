<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\Cli;

use ViMbAdmin\Kernel\Bootstrap;
use ViMbAdmin\Kernel\Container;
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
 * every supported `controller.action` name as a native {@see CliCommand}.
 * `vimbtool.php` rejects unregistered names before booting the native container.
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
        // Register every supported command. vimbtool.php rejects other names.
        $registered = [
            new QueueRunCommand(),
            new ResetTotpCommand(),
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
     * Bootstrap owns the OSS_Runtime compatibility setup. The vimbtool entry
     * point only boots this kernel and runs the selected command. The identity
     * namespace argument is irrelevant under CLI (no command authenticates and
     * boot() starts no session for the CLI SAPI), so a neutral placeholder is
     * passed.
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
