<?php

require __DIR__ . '/../vendor/autoload.php';

$failures = 0;
$check = static function (string $label, bool $ok) use (&$failures): void {
    echo ($ok ? "  ok   " : "  FAIL ") . $label . "\n";
    if (!$ok) {
        $failures++;
    }
};

echo "== Password-reset URL origin ==\n";

$config = file_get_contents(__DIR__ . '/../contrib/angie/vimbadmin.conf');
$check('shipped Angie configuration is readable', is_string($config));

$includePosition = is_string($config) ? strpos($config, 'include              fastcgi_params;') : false;
$overridePosition = is_string($config)
    ? strpos($config, 'fastcgi_param        HTTP_HOST        $server_name;')
    : false;
$check(
    'canonical HTTP_HOST override follows the inherited FastCGI parameters',
    $includePosition !== false && $overridePosition !== false && $overridePosition > $includePosition
);
$check(
    'shipped FastCGI configuration never forwards the request Host header',
    !is_string($config) || !str_contains($config, 'fastcgi_param        HTTP_HOST        $http_host;')
);

OSS_Runtime::configure([], '/vimbadmin', new stdClass());
$_SERVER['HTTPS'] = 'on';
$_SERVER['HTTP_HOST'] = 'vimbadmin.example.com';

$check(
    'FastCGI canonical host produces the password-reset email origin',
    OSS_Utils::genUrl('auth', 'reset-password', false, ['username' => 'admin'])
        === 'https://vimbadmin.example.com/vimbadmin/auth/reset-password/username/admin'
);

$_SERVER['HTTP_HOST'] = ['vimbadmin.example.com'];
$malformedHostRejected = false;
try {
    OSS_Utils::genUrl('auth', 'reset-password');
} catch (TypeError) {
    $malformedHostRejected = true;
}
$check('malformed FastCGI host fails closed', $malformedHostRejected);

$_SERVER['HTTP_HOST'] = 'vimbadmin.example.com';
OSS_Runtime::configure(
    ['utils' => ['genurl' => ['host_mode' => 'REPLACE', 'host_replace' => 'reset.example.test']]],
    '/vimbadmin',
    new stdClass()
);
$check(
    'explicit configured origin remains authoritative',
    OSS_Utils::genUrl('auth', 'reset-password', false, ['username' => 'admin'])
        === 'https://reset.example.test/vimbadmin/auth/reset-password/username/admin'
);

echo $failures === 0 ? "\nALL PASSED\n" : "\n{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
