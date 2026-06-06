<?php

declare(strict_types=1);

namespace ViMbAdmin\Kernel\Cli\Command;

use ViMbAdmin\Kernel\Cli\CliCommand;
use ViMbAdmin\Kernel\Container;

/**
 * `mailbox.cli-delete-pending` — purge mailboxes flagged delete-pending
 * (WALL #2, docs/ZF1-REMOVAL.md). Native port of
 * `MailboxController::cliDeletePendingAction`: for each
 * `Repositories\Mailbox::pendingDelete()` row it removes the on-disk maildir +
 * homedir (via the configured `binary.path.rm_rf`, each path
 * {@see \escapeshellarg()}-quoted) and then the DB row. Run from cron after a
 * deletion grace period.
 *
 * @package ViMbAdmin
 * @subpackage Kernel
 */
final class DeletePendingCommand implements CliCommand
{
    public function name(): string
    {
        return 'mailbox.cli-delete-pending';
    }

    public function run(Container $container, array $args): int
    {
        $verbose = array_key_exists('v', $args) || array_key_exists('verbose', $args);

        $em         = $container->entityManager();
        $options    = $container->options();
        $mailboxes  = $em->getRepository('\\Entities\\Mailbox')->pendingDelete();

        if (!count($mailboxes)) {
            if ($verbose) {
                echo "No mailboxes pending deletion\n";
            }
            return 0;
        }

        $rmRf = $options['binary']['path']['rm_rf'] ?? null;
        if (!is_string($rmRf) || $rmRf === '') {
            echo "ERROR: Deleting mailboxes - you must set 'binary.path.rm_rf' in application.ini\n";
            return 1;
        }

        if ($verbose) {
            echo 'Deleting ' . count($mailboxes) . " mailboxes:\n";
        }

        $exit = 0;
        foreach ($mailboxes as $mailbox) {
            if ($verbose) {
                echo ' - ' . $mailbox->getUsername() . '... ';
            }

            foreach ([$mailbox->getCleanedMaildir(), $mailbox->getHomedir()] as $dir) {
                if ($dir === null || $dir === '' || !file_exists($dir)) {
                    continue;
                }
                $command = sprintf('%s %s', $rmRf, escapeshellarg($dir));
                exec($command, $out, $result);
                if ($result !== 0) {
                    echo "ERROR: Could not delete {$dir} when deleting mailbox " . $mailbox->getUsername() . "\n";
                    $exit = 1;
                }
            }

            $em->remove($mailbox);
            $em->flush();
            if ($verbose) {
                echo "DONE\n";
            }
        }

        return $exit;
    }
}
