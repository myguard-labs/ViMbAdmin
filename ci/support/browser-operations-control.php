<?php

declare(strict_types=1);

// Reached only after the private verifier's token and operations-mode gates.
// Faults affect this fixture database, never application dispatch or responses.
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    failVerifier(405, 'POST required');
}
$action = $_POST['action'] ?? null;
if (!in_array($action, ['fail-alias-update', 'restore-alias-update'], true)) {
    failVerifier(400, 'unknown operations control');
}
$pdo = new PDO(
    sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        requiredEnvironment('DB_HOST'),
        requiredEnvironment('DB_PORT'),
        requiredEnvironment('DB_NAME')
    ),
    requiredEnvironment('DB_USER'),
    requiredSecretFile('DB_PASS_FILE'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$pdo->exec('DROP TRIGGER IF EXISTS browser_operations_alias_failure');
if ($action === 'fail-alias-update') {
    $pdo->exec("CREATE TRIGGER browser_operations_alias_failure BEFORE UPDATE ON alias FOR EACH ROW"
        . " SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'browser operations injected alias update failure'");
}
echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);
