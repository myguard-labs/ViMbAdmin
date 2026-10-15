<?php

declare(strict_types=1);

$source = file_get_contents(dirname(__DIR__) . '/src/Kernel/Controller/AuthController.php');
if (!is_string($source)) {
    throw new RuntimeException('AuthController source is unreadable');
}
if (getenv('VIMBADMIN_RESET_ENUMERATION_MUTATION') === '1') {
    $needle = 'return $this->passwordResetRequestResponse($username);';
    $position = strpos($source, $needle, strpos($source, 'catch (\\OSS_Doctrine2_WithPreferences_IndexLimitException $e)') ?: 0);
    if ($position !== false) {
        $source = substr_replace($source, "\$this->flash('ceiling reached'); return \$this->redirect('auth/lost-password');", $position, strlen($needle));
    }
}

$failures = 0;
$check = static function (string $label, bool $ok) use (&$failures): void {
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
    $failures += $ok ? 0 : 1;
};

$start = strpos($source, 'public function lostPasswordAction(): Response');
$end = strpos($source, 'public function resetPasswordAction(): Response', $start ?: 0);
$action = $start !== false && $end !== false ? substr($source, $start, $end - $start) : '';

$check('positive: missing, ceiling, and mailed branches share one response helper', substr_count($action, 'return $this->passwordResetRequestResponse($username);') === 3);
$check('positive: a fresh existing account sends one reset email before the shared response', substr_count($action, '$this->sendAuthEmail(') === 1);
$ceilingReturn = strpos($action, 'catch (\\OSS_Doctrine2_WithPreferences_IndexLimitException $e)');
$mailCall = strpos($action, '$this->sendAuthEmail(');
$check(
    'boundary: the token ceiling returns before mail delivery',
    $ceilingReturn !== false && $mailCall !== false
        && strpos($action, 'return $this->passwordResetRequestResponse($username);', $ceilingReturn) < $mailCall,
);
$check('negative: account existence and ceiling do not select distinct user-visible messages', substr_count($action, 'If your username was correct') === 1);

$oracle = static function (bool $exists, int $liveTokens, int $ceiling): array {
    $mail = $exists && $liveTokens < $ceiling;
    return ['status' => 302, 'flash' => 'generic', 'mail' => $mail];
};
foreach ([0, 4, 5, 6] as $count) {
    $existing = $oracle(true, $count, 5);
    $missing = $oracle(false, $count, 5);
    $check("response: existing and missing match at token count {$count}", $existing['status'] === $missing['status'] && $existing['flash'] === $missing['flash']);
    $check("mail: only an existing account below the ceiling mails at token count {$count}", $existing['mail'] === ($count < 5) && $missing['mail'] === false);
}

echo $failures === 0 ? "ALL PASSED\n" : "{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
