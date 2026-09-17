<?php

require __DIR__ . '/../library/ViMbAdmin/Demo.php';

$failures = 0;
$check = static function (string $label, bool $ok) use (&$failures): void {
    echo ($ok ? "  ok   " : "  FAIL ") . $label . "\n";
    if (!$ok) {
        $failures++;
    }
};

echo "== ViMbAdmin demo account ==\n";

$check(
    'missing demo section is inert',
    ViMbAdmin_Demo::account([]) === null
    && ViMbAdmin_Demo::password([]) === null
    && !ViMbAdmin_Demo::enabled([])
    && !ViMbAdmin_Demo::isLocked([], 'demo@example.com')
);

foreach ([null, false, 'invalid', ['account' => []], ['account' => new stdClass()]] as $demo) {
    $check(
        'malformed demo configuration is inert',
        ViMbAdmin_Demo::account(['demo' => $demo]) === null
        && ViMbAdmin_Demo::password(['demo' => $demo]) === null
        && !ViMbAdmin_Demo::enabled(['demo' => $demo])
    );
}

$options = [
    'demo' => [
        'account' => '  Demo@Example.COM  ',
        'password' => '  public password  ',
    ],
];
$check('account is trimmed', ViMbAdmin_Demo::account($options) === 'Demo@Example.COM');
$check('non-empty account enables demo mode', ViMbAdmin_Demo::enabled($options));
$check(
    'public demo password is returned verbatim',
    ViMbAdmin_Demo::password($options) === '  public password  '
);
$check(
    'lock matching is case-insensitive',
    ViMbAdmin_Demo::isLocked($options, 'demo@example.com')
);
$check(
    'lock matching does not trim the supplied username',
    !ViMbAdmin_Demo::isLocked($options, ' demo@example.com ')
);
$check(
    'null and empty usernames never lock',
    !ViMbAdmin_Demo::isLocked($options, null)
    && !ViMbAdmin_Demo::isLocked($options, '')
);

foreach ([null, '', '   '] as $account) {
    $empty = ['demo' => ['account' => $account, 'password' => 'visible']];
    $check(
        'null or empty account disables credentials',
        ViMbAdmin_Demo::account($empty) === null
        && ViMbAdmin_Demo::password($empty) === null
        && !ViMbAdmin_Demo::enabled($empty)
    );
}

$check(
    'missing password stays null for an enabled account',
    ViMbAdmin_Demo::password(['demo' => ['account' => 'demo@example.com']]) === null
);
$check(
    'empty password stays null for an enabled account',
    ViMbAdmin_Demo::password(['demo' => ['account' => 'demo@example.com', 'password' => '']]) === null
);

echo "\n";
$exitCode = $failures === 0 ? 0 : 1;
echo $exitCode === 0
    ? "OK: all ViMbAdmin_Demo assertions passed (PHP " . PHP_VERSION . ")\n"
    : "FAIL: {$failures} assertion(s) failed\n";
exit($exitCode);
