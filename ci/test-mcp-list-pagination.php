<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$failures = 0;
$check = static function (string $label, bool $ok) use (&$failures): void {
    echo ($ok ? "  ok   " : "  FAIL ") . $label . "\n";
    if (!$ok) {
        $failures++;
    }
};

echo "== MCP list pagination contract ==\n";

$controller = (new ReflectionClass(McpController::class))->newInstanceWithoutConstructor();
$pagination = new ReflectionMethod($controller, '_pagination');
$bounds = new ReflectionMethod($controller, '_listBounds');

$check(
    'a full page with one look-ahead row advertises the next page',
    $pagination->invoke($controller, 3, 2, 2, 0) === [
        'limit' => 2,
        'offset' => 0,
        'returned' => 2,
        'has_more' => true,
        'next_offset' => 2,
    ],
);
$check(
    'an exact final page is explicitly complete',
    $pagination->invoke($controller, 2, 2, 2, 8) === [
        'limit' => 2,
        'offset' => 8,
        'returned' => 2,
        'has_more' => false,
        'next_offset' => null,
    ],
);
$check(
    'an empty page beyond the former offset cap remains a bounded complete response',
    $pagination->invoke($controller, 0, 0, 200, 10200) === [
        'limit' => 200,
        'offset' => 10200,
        'returned' => 0,
        'has_more' => false,
        'next_offset' => null,
    ],
);
$check(
    'omitted paging parameters retain the documented defaults',
    $bounds->invoke($controller, []) === [100, 0],
);
$boundaryPage = $pagination->invoke($controller, 201, 200, 200, 10000);
$nextOffset = is_array($boundaryPage) ? ($boundaryPage['next_offset'] ?? null) : null;
$continuationAccepted = false;
try {
    $continuationAccepted = $nextOffset === 10200
        && $bounds->invoke($controller, ['limit' => 200, 'offset' => $nextOffset]) === [200, 10200];
} catch (ViMbAdmin_Mcp_Exception) {
    $continuationAccepted = false;
}
$check(
    'the page after offset 10000 is advertised and accepted',
    $continuationAccepted,
);
$check(
    'the input parser accepts its maximum native non-negative offset',
    $bounds->invoke($controller, ['offset' => PHP_INT_MAX]) === [100, PHP_INT_MAX],
);

foreach ([
    'zero limit' => ['limit' => 0],
    'oversized limit' => ['limit' => 201],
    'negative offset' => ['offset' => -1],
    'fractional limit' => ['limit' => '1.5'],
    'overflowing offset' => ['offset' => (string) PHP_INT_MAX . '0'],
] as $label => $params) {
    $rejected = false;
    try {
        $bounds->invoke($controller, $params);
    } catch (ViMbAdmin_Mcp_Exception $error) {
        $rejected = str_contains($error->getMessage(), 'param');
    }
    $check("{$label} is rejected", $rejected);
}

$source = file_get_contents(__DIR__ . '/../application/controllers/McpController.php');
if (!is_string($source)) {
    throw new RuntimeException('Cannot read MCP controller source.');
}
$check(
    'all three public list methods retain their legacy item keys and add pagination metadata',
    substr_count($source, "'pagination' => \$this->_pagination") === 3
        && str_contains($source, "'domains' => \$out")
        && str_contains($source, "'mailboxes' => \$out")
        && str_contains($source, "'aliases' => \$out"),
);
$check(
    'all three list queries fetch exactly one bounded look-ahead row',
    substr_count($source, '$limit + 1') === 3
        && substr_count($source, 'array_slice($') >= 3,
);
$check(
    'list bounds have no artificial offset ceiling',
    !str_contains($source, 'LIST_MAX_OFFSET'),
);

$documentation = file_get_contents(__DIR__ . '/../docs/mcp-auth.md');
if (!is_string($documentation)) {
    throw new RuntimeException('Cannot read MCP documentation.');
}
$check(
    'public documentation explains how to retrieve a complete list',
    str_contains($documentation, '`next_offset` is `null`')
        && str_contains($documentation, '`limit` defaults to 100')
        && str_contains($documentation, 'any non-negative integer supported by the')
        && substr_count($documentation, '`pagination`') >= 4,
);

echo $failures === 0 ? "ALL PASSED\n" : "{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
