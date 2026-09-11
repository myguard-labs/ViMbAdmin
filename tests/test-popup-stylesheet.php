<?php

declare(strict_types=1);

// Popup stylesheet links must name shipped files.
$root = dirname(__DIR__);
$header = (string) file_get_contents($root . '/application/views/header.phtml');
$count = preg_match_all('~href="\{genUrl}/css/([^"/]*popup\.css)"~', $header, $matches);
if ($count === false || $count < 1) {
    fwrite(STDERR, "FAIL: expected at least one popup stylesheet reference, got {$count}\n");
    exit(1);
}
foreach ($matches[1] as $stylesheet) {
    if (!is_file($root . '/public/css/' . $stylesheet)) {
        fwrite(STDERR, "FAIL: popup stylesheet is not shipped: {$stylesheet}\n");
        exit(1);
    }
}
echo "ALL PASSED\n";
