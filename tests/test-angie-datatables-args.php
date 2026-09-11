<?php

declare(strict_types=1);

$config = file_get_contents(__DIR__ . '/../contrib/angie/vimbadmin.conf');
if (!is_string($config)) {
    fwrite(STDERR, "Could not read Angie configuration\n");
    exit(1);
}

if (preg_match('/^\s+"~\*\^(.*)" 0;$/m', $config, $match) !== 1) {
    fwrite(STDERR, "Could not find the Angie argument allowlist regex\n");
    exit(1);
}

$pattern = '~^' . str_replace('~', '\\~', $match[1]) . '~iD';
$failures = 0;
$check = static function (string $label, bool $ok) use (&$failures): void {
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$ok) {
        $failures++;
    }
};
$allowed = static fn(string $query): bool => preg_match($pattern, $query) === 1;

$modern = 'draw=7&start=20&length=25&search%5Bvalue%5D=%2Aabc&search%5Bregex%5D=false'
    . '&order%5B0%5D%5Bcolumn%5D=2&order%5B0%5D%5Bdir%5D=desc&order%5B0%5D%5Bname%5D=domain'
    . '&columns%5B0%5D%5Bdata%5D=id&columns%5B0%5D%5Bname%5D=domain'
    . '&columns%5B0%5D%5Bsearchable%5D=true&columns%5B0%5D%5Borderable%5D=true'
    . '&columns%5B0%5D%5Bsearch%5D%5Bvalue%5D=&columns%5B0%5D%5Bsearch%5D%5Bregex%5D=false&_=';
$check('encoded DataTables 2 request names pass the edge allowlist', $allowed($modern));
$check('literal nested DataTables 2 names pass the edge allowlist',
    $allowed('draw=1&start=0&length=10&search[value]=abc&order[0][column]=0&order[0][dir]=asc'));
$check('unknown nested search member is rejected', !$allowed('draw=1&search[script]=x'));
$check('out-of-shape ordering member is rejected', !$allowed('draw=1&order[column]=0'));
$check('legacy-only DataTables names are rejected',
    !$allowed('sEcho=1&iDisplayStart=0&iDisplayLength=10&sSearch=abc&iSortCol_0=0&sSortDir_0=asc'));

echo $failures === 0 ? "ALL PASSED\n" : "{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
