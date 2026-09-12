<?php

declare(strict_types=1);

/**
 * DataTables `order[0][column]` -> sort-field mapping for the server-side list pages.
 *
 * The client sends the index of the column the user clicked. The controller
 * translates that index into a whitelisted field name which the repository maps
 * again onto a DQL path. A stale index map is silent: the user clicks "Created"
 * and the rows come back ordered by domain, with no error anywhere.
 *
 * Three of the five list tables render a CONDITIONAL column, so the index of
 * every column after it depends on configuration or on request scope:
 *
 *   - domain/list.phtml  "Used / Max"  <- defaults.list_size.disabled
 *                                        (shipped default: DISABLED, i.e. hidden)
 *   - mailbox/list.phtml "Domain"      <- defaults.list_domain.disabled
 *   - log/list.phtml     "Domain"      <- a remembered domain filter
 *
 * These assertions pin each controller's map to the column order the matching
 * view template and view JS actually render, in BOTH states of the conditional.
 * Alias and Archive render a fixed column set whose static maps were verified to
 * match their templates; they carry no conditional column and are not asserted
 * here.
 */

require __DIR__ . '/../vendor/autoload.php';
foreach (glob(__DIR__ . '/../application/Entities/*.php') ?: [] as $entityFile) {
    require_once $entityFile;
}

use ViMbAdmin\Kernel\Controller\DomainController;
use ViMbAdmin\Kernel\Controller\LogController;
use ViMbAdmin\Kernel\Controller\MailboxController;

$checks = 0;
$failures = 0;
$check = static function (string $label, bool $condition) use (&$checks, &$failures): void {
    $checks++;
    echo ($condition ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$condition) {
        $failures++;
    }
};

/**
 * Call a controller's private static `listSortField(int, bool)`.
 */
$sortField = static function (string $class, int $index, bool $flag): string {
    $method = new ReflectionMethod($class, 'listSortField');
    $method->setAccessible(true);
    $value = $method->invoke(null, $index, $flag);

    return is_string($value) ? $value : '<non-string>';
};

/**
 * Collect the whole map for a table, index 0..$last inclusive.
 *
 * @return list<string>
 */
$mapOf = static function (string $class, int $last, bool $flag) use ($sortField): array {
    $out = [];
    for ($i = 0; $i <= $last; $i++) {
        $out[] = $sortField($class, $i, $flag);
    }

    return $out;
};

// ---------------------------------------------------------------------------
// Domain list
// ---------------------------------------------------------------------------
// Rendered with the size column ON (defaults.list_size.disabled = false):
//   0 Domain | 1 Mailboxes | 2 Aliases | 3 Used/Max* | 4 Default quota
//   5 Active | 6 Transport | 7 Backup MX* | 8 Created | 9 controls*   (* not sortable)
$check('domain map with the size column rendered follows the rendered order',
    $mapOf(DomainController::class, 9, true) === [
        'domain', 'mailboxes', 'aliases', 'domain', 'quota',
        'active', 'transport', 'domain', 'created', 'domain',
    ]);

// Rendered with the size column OFF -- the SHIPPED DEFAULT
// (application.ini.dist: defaults.list_size.disabled = true). Every column after
// index 2 moves down one. A static map pinned to the "on" layout would answer
// 'domain' for Default quota, 'quota' for Active, 'active' for Transport and
// 'domain' for Created.
$check('domain map with the size column hidden shifts every later column down one',
    $mapOf(DomainController::class, 8, false) === [
        'domain', 'mailboxes', 'aliases', 'quota', 'active',
        'transport', 'domain', 'created', 'domain',
    ]);
$check('domain Created sorts by created under the shipped default, not by domain',
    $sortField(DomainController::class, 7, false) === 'created');
$check('domain Transport sorts by transport under the shipped default, not by active',
    $sortField(DomainController::class, 5, false) === 'transport');

// ---------------------------------------------------------------------------
// Log list
// ---------------------------------------------------------------------------
// Unscoped: 0 Action | 1 Log* | 2 Admin | 3 Domain | 4 Occurred At
$check('log map without a domain filter follows the rendered order',
    $mapOf(LogController::class, 4, false) === ['action', 'timestamp', 'admin', 'domain', 'timestamp']);

// Domain-scoped: the Domain column is NOT rendered, so the table is
// 0 Action | 1 Log* | 2 Admin | 3 Occurred At -- and log/js/list.js sets the
// INITIAL order to index 3 in exactly this case, so a map answering 'domain'
// for index 3 mis-sorts the very first page load of every domain-scoped log.
$check('log map with a domain filter drops the Domain column',
    $mapOf(LogController::class, 3, true) === ['action', 'timestamp', 'admin', 'timestamp']);
$check('domain-scoped log index 3 is the Occurred At column, not domain',
    $sortField(LogController::class, 3, true) === 'timestamp');

// ---------------------------------------------------------------------------
// Mailbox list
// ---------------------------------------------------------------------------
// Domain column ON (shipped default: defaults.list_domain.disabled = false):
//   0 Email | 1 Name | 2 Used/Quota* | 3 Last login* | 4 Domain | 5 Active* | 6 controls*
$check('mailbox map with the domain column rendered follows the rendered order',
    $mapOf(MailboxController::class, 6, true) === [
        'username', 'name', 'username', 'username', 'domain', 'username', 'username',
    ]);
// Domain column OFF: index 4 is Active, which is not sortable.
$check('mailbox map without the domain column exposes no domain sort',
    $mapOf(MailboxController::class, 5, false) === [
        'username', 'name', 'username', 'username', 'username', 'username',
    ]);

echo "\n";
$exitCode = $failures === 0 ? 0 : 1;
echo $exitCode === 0
    ? "OK: all {$checks} DataTables sort-column map assertions passed (PHP " . PHP_VERSION . ")\n"
    : "FAIL: {$failures} of {$checks} assertion(s) failed\n";
exit($exitCode);
