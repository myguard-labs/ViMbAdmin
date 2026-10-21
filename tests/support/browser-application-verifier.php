<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function failVerifier(int $status, string $message): never
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $message], JSON_THROW_ON_ERROR);
    exit;
}

function requiredEnvironment(string $name): string
{
    $value = getenv($name);
    if (!is_string($value) || $value === '' || str_contains($value, "\0")) {
        failVerifier(500, 'verifier environment is incomplete');
    }
    return $value;
}

function requiredSecretFile(string $environmentName): string
{
    $path = requiredEnvironment($environmentName);
    if (!is_file($path) || is_link($path)) {
        failVerifier(500, 'verifier secret file is unavailable');
    }
    $value = file_get_contents($path);
    if (!is_string($value) || preg_match('/^[a-f0-9]{64}\n?$/D', $value) !== 1) {
        failVerifier(500, 'verifier secret file is invalid');
    }
    return rtrim($value, "\n");
}

/** @return array<string,mixed> */
function databaseSnapshot(): array
{
    $host = requiredEnvironment('DB_HOST');
    $port = requiredEnvironment('DB_PORT');
    $name = requiredEnvironment('DB_NAME');
    $user = requiredEnvironment('DB_USER');
    $password = requiredSecretFile('DB_PASS_FILE');
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $name),
        $user,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_STRINGIFY_FETCHES => true],
    );
    $tableStatement = $pdo->query('SHOW TABLES');
    if ($tableStatement === false) {
        throw new RuntimeException('database table inventory failed');
    }
    $tableValues = $tableStatement->fetchAll(PDO::FETCH_COLUMN);
    $tables = [];
    foreach ($tableValues as $table) {
        if (!is_string($table) || preg_match('/^[A-Za-z0-9_]+$/D', $table) !== 1) {
            throw new RuntimeException('database table name is invalid');
        }
        $tables[] = $table;
    }
    sort($tables, SORT_STRING);
    $database = hash_init('sha256');
    $rowCount = 0;
    $entityDigests = [];
    foreach ($tables as $table) {
        $rowStatement = $pdo->query('SELECT * FROM `' . $table . '`');
        if ($rowStatement === false) {
            throw new RuntimeException('database table snapshot failed');
        }
        $rows = $rowStatement->fetchAll(PDO::FETCH_ASSOC);
        $rowHashes = [];
        foreach ($rows as $row) {
            $rowHash = hash('sha256', json_encode($row, JSON_THROW_ON_ERROR));
            $rowHashes[] = $rowHash;
            if (getenv('VIMBADMIN_FIXTURE_TENANT') === '1'
                && in_array($table, ['admin', 'domain', 'mailbox'], true)) {
                $id = is_array($row) ? ($row['id'] ?? null) : null;
                if (!is_string($id) || !ctype_digit($id)) {
                    throw new RuntimeException('tenant entity id is invalid');
                }
                $entityDigests[$table][$id] = $rowHash;
            }
        }
        sort($rowHashes, SORT_STRING);
        $rowCount += count($rowHashes);
        hash_update($database, $table . "\0" . implode("\0", $rowHashes) . "\n");
    }
    $snapshot = ['tables' => count($tables), 'rows' => $rowCount, 'sha256' => hash_final($database)];
    if (getenv('VIMBADMIN_FIXTURE_TENANT') === '1') {
        // Explicit projections keep passwords, tokens and other private fields
        // out of browser diagnostics while proving per-tenant state changes.
        $snapshot['entities'] = [];
        $snapshot['entityDigests'] = $entityDigests;
        foreach (['admin' => 'id,username,active,super', 'domain' => 'id,domain,active',
            'mailbox' => 'id,username,active,name'] as $table => $columns) {
            $statement = $pdo->query('SELECT ' . $columns . ' FROM `' . $table . '` ORDER BY id');
            if ($statement === false) {
                throw new RuntimeException('tenant projection failed');
            }
            $snapshot['entities'][$table] = $statement->fetchAll(PDO::FETCH_ASSOC);
        }
    }
    return $snapshot;
}

/** @return array{exists:int,sha256:string}|array{exists:bool,bytes:int,sha256:?string} */
function sessionSnapshot(string $alias): array
{
    $directory = requiredEnvironment('VIMBADMIN_FIXTURE_SESSION_DIR');
    $mapPath = requiredEnvironment('VIMBADMIN_FIXTURE_SESSION_MAP');
    $map = json_decode((string) file_get_contents($mapPath), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($map)) {
        throw new RuntimeException('session alias map is invalid');
    }
    if ($alias === 'all') {
        $hashes = [];
        foreach (glob($directory . '/sess_*') ?: [] as $filename) {
            if (is_file($filename) && !is_link($filename)) {
                $hashes[] = hash_file('sha256', $filename);
            }
        }
        sort($hashes, SORT_STRING);
        return ['exists' => count($hashes), 'sha256' => hash('sha256', implode("\n", $hashes))];
    }
    $id = $map[$alias] ?? null;
    if (!is_string($id) || preg_match('/^[a-f0-9]{48}$/D', $id) !== 1) {
        failVerifier(400, 'unknown session alias');
    }
    $filename = $directory . '/sess_' . $id;
    if (!is_file($filename)) {
        return ['exists' => false, 'bytes' => 0, 'sha256' => null];
    }
    $bytes = filesize($filename);
    $sha256 = hash_file('sha256', $filename);
    if (!is_int($bytes) || !is_string($sha256)) {
        throw new RuntimeException('session snapshot failed');
    }
    return ['exists' => true, 'bytes' => $bytes, 'sha256' => $sha256];
}

try {
    $providedToken = $_SERVER['HTTP_X_VIMBADMIN_FIXTURE_TOKEN'] ?? null;
    if (!is_string($providedToken) || !hash_equals(requiredSecretFile('VIMBADMIN_FIXTURE_TOKEN_FILE'), $providedToken)) {
        failVerifier(403, 'forbidden');
    }
    $requestUri = $_SERVER['REQUEST_URI'] ?? '';
    if (!is_string($requestUri)) {
        failVerifier(400, 'request URI is invalid');
    }
    $requestPath = parse_url($requestUri, PHP_URL_PATH);
    if ($requestPath !== '/snapshot') {
        failVerifier(404, 'not found');
    }
    $alias = $_GET['session'] ?? null;
    if (!is_string($alias) || preg_match('/^[a-z_]+$/D', $alias) !== 1) {
        failVerifier(400, 'session alias is required');
    }
    echo json_encode([
        'ok' => true,
        'database' => databaseSnapshot(),
        'session' => sessionSnapshot($alias),
    ], JSON_THROW_ON_ERROR);
} catch (Throwable) {
    failVerifier(500, 'snapshot failed');
}
