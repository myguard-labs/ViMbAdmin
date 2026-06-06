<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\Cli\Command;

use ViMbAdmin\Kernel\Cli\CliCommand;
use ViMbAdmin\Kernel\Container;

/**
 * `admin.cli-reset-totp` — disable two-factor auth for one admin or all
 * (WALL #2, docs/ZF1-REMOVAL.md). Native port of
 * `AdminController::cliResetTotpAction`, over the framework-free
 * {@see \ViMbAdmin_TwoFactor} (the recovery path for a locked-out admin).
 *
 * `--username=<email>` resets one admin; `--all` resets every admin.
 *
 * @package ViMbAdmin
 * @subpackage Kernel
 */
final class ResetTotpCommand implements CliCommand
{
    public function name(): string
    {
        return 'admin.cli-reset-totp';
    }

    public function run(Container $container, array $args): int
    {
        $username = (isset($args['username']) && is_string($args['username'])) ? $args['username'] : null;
        $all      = array_key_exists('all', $args);

        if ($username === null && !$all) {
            echo "Usage: vimbtool.php -a admin.cli-reset-totp --username=<email> | --all\n";
            return 1;
        }

        $options = $container->options();
        $tfa     = new \ViMbAdmin_TwoFactor('ViMbAdmin', (string) ($options['securitysalt'] ?? ''));
        $em      = $container->entityManager();
        $repo    = $em->getRepository('\\Entities\\Admin');

        $admins = $all ? $repo->findAll() : $repo->findBy(['username' => $username]);
        if (!$admins) {
            echo "No matching admin(s) found.\n";
            return 1;
        }

        $n = 0;
        foreach ($admins as $admin) {
            if ($tfa->isEnabled($admin)) {
                $tfa->disable($admin);
                echo '2FA reset for: ' . $admin->getUsername() . "\n";
                $n++;
            }
        }
        $em->flush();
        echo "Done. {$n} admin(s) had 2FA disabled.\n";

        return 0;
    }
}
