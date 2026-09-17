<?php

declare(strict_types=1);

require __DIR__ . '/support/native-workflow-harness.php';
define('APPLICATION_PATH', __DIR__ . '/support/native-workflow-app');
require __DIR__ . '/support/native-workflow-app/plugins/WorkflowProbe.php';

$failures = 0;
$checks = 0;
$check = static function (string $label, bool $ok) use (&$failures, &$checks): void {
    $checks++;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$ok) {
        $failures++;
    }
};

// Differential oracle captured from tag 4.0.0, commit
// a0c7a20a376ff3aa25d071068ab3f33054009594. Each row lists a legacy
// form/action contract, not a copy of the current implementation. Sources:
// library/ViMbAdmin/Form/{Auth/Login,Admin/{AddEdit,Password},Mailbox/AddEdit}.php,
// library/OSS/Form/Auth.php::createRememberMeElement, application/controllers/
// {Auth,Admin,Mailbox}Controller.php and application/plugins/AdditionalInfo.php.
// The old framework is intentionally not loaded into the native runtime.
$contracts = [
    'login' => ['auth', 'login', [], ['rememberme'], ['username' => 'actor@example.test', 'password' => WorkflowHarness::PASSWORD], ['rememberme' => '1']],
    'setup' => ['auth', 'setup', [], [], ['salt' => str_repeat('s', 64), 'username' => 'new@example.test', 'password' => WorkflowHarness::PASSWORD], []],
    'admin-add' => ['admin', 'add', [], ['welcome_email'], ['username' => 'new@example.test', 'password' => WorkflowHarness::PASSWORD, 'super' => '0'], ['welcome_email' => '1']],
    'admin-password' => ['admin', 'password', ['aid' => '2'], ['email'], ['password' => 'replacement workflow password'], ['email' => '1']],
    'mailbox-add' => ['mailbox', 'add', [], ['welcome_email', 'cc_welcome_email'], ['local_part' => 'new', 'domain' => '1', 'name' => 'New', 'password' => WorkflowHarness::PASSWORD, 'quota' => '1', 'alt_email' => 'alternate@example.test', 'plugin_additionalInfo_department' => 'new-mailbox-value'], ['welcome_email' => '1', 'cc_welcome_email' => 'copy@example.test']],
    'mailbox-edit' => ['mailbox', 'edit', ['mid' => '1'], ['welcome_email', 'cc_welcome_email'], ['name' => 'Edited', 'quota' => '2', 'alt_email' => 'edited@example.test', 'plugin_additionalInfo_department' => 'new-mailbox-value'], ['welcome_email' => '1', 'cc_welcome_email' => 'copy@example.test']],
    'mailbox-password' => ['mailbox', 'password', ['mid' => '1'], ['email'], ['password' => 'replacement workflow password'], ['email' => '1']],
    'alias-add' => ['alias', 'add', [], ['plugin_additionalInfo_department'], ['local_part' => 'new', 'domain' => '1', 'goto' => 'new@example.test'], ['plugin_additionalInfo_department' => 'new-alias-value', 'pluginsf_AdditionalInfo' => ['plugin_additionalInfo_department' => 'nested-alias-value']]],
    'alias-edit' => ['alias', 'edit', ['alid' => '1'], ['plugin_additionalInfo_department'], ['goto' => 'new@example.test'], ['plugin_additionalInfo_department' => 'new-alias-value', 'pluginsf_AdditionalInfo' => ['plugin_additionalInfo_department' => 'nested-alias-value']]],
];

$check(
    'native workflow removals have an explicit contract version',
    defined('ViMbAdmin_Version::NATIVE_WORKFLOW_CONTRACT')
    && constant('ViMbAdmin_Version::NATIVE_WORKFLOW_CONTRACT') === 'native-workflows/1'
);
$docPath = __DIR__ . '/../docs/NATIVE-WORKFLOWS-1.md';
$doc = is_file($docPath) ? file_get_contents($docPath) : '';
if (!is_string($doc)) {
    throw new RuntimeException('Could not read workflow contract');
}
$check(
    'versioned migration contract identifies the immutable legacy baseline',
    str_contains($doc, 'native-workflows/1')
    && str_contains($doc, 'a0c7a20a376ff3aa25d071068ab3f33054009594')
);

foreach ($contracts as $name => [$controller, $action, $params, $removedFields, $valid, $legacyOptIn]) {
    $check($name . ': removal and replacement instructions are documented', str_contains($doc, '`' . $name . '`'));
    // Run all native actions, including successful state changes. Absence of
    // mail/plugin writes alone would be vacuous if the form never validated.
    // Setup's legacy welcome was automatic: there is no opt-in input to vary.
    $modes = $name === 'setup' ? ['success', 'csrf-error', 'field-error']
        : ['off', 'on', 'malformed', 'csrf-error', 'field-error'];
    if (str_starts_with($name, 'mailbox-') && $action !== 'password') {
        $modes = [...$modes, 'welcome-only', 'cc-only'];
    }
    if ($controller === 'alias') {
        $modes[] = 'plugin-off';
    }
    foreach ($modes as $mode) {
        ViMbAdminPlugin_WorkflowProbe::$events = [];
        // Welcome-mail removal is independent of mailbox preference creation;
        // the real adapter's write path is covered below on an existing record.
        $h = new WorkflowHarness($name === 'setup', $name === 'login', $mode !== 'plugin-off', $name !== 'mailbox-add');
        $get = $h->run($controller, $action, $params);
        $check($name . '/' . $mode . ': GET renders a usable form', $get->status === 200 && str_contains($get->body, 'name="csrf"'));
        foreach ($removedFields as $field) {
            $check(
                $name . '/' . $mode . ': legacy field removed: ' . $field,
                !str_contains($get->body, 'name="' . $field . '"')
                && !str_contains($get->body, 'name="pluginsf_AdditionalInfo[' . $field . ']"')
            );
        }
        $legacyFields = $mode === 'off' ? [] : $legacyOptIn;
        if ($mode === 'malformed') {
            $legacyFields = array_fill_keys(array_keys($legacyOptIn), ['unsupported']);
        }
        if ($mode === 'welcome-only') {
            unset($legacyFields['cc_welcome_email']);
        }
        if ($mode === 'cc-only') {
            $legacyFields['welcome_email'] = '0';
        }
        $post = $valid + $legacyFields + ['csrf' => 'workflow-csrf'];
        if ($mode === 'csrf-error') {
            $post['csrf'] = 'incorrect';
        }
        if ($mode === 'field-error') {
            $post[array_key_first($valid)] = '';
            if ($controller === 'alias') {
                $post['goto'] = 'not-an-address';
            }
            if ($name === 'mailbox-edit') {
                $post['quota'] = '-1';
            }
        }
        $response = $h->run($controller, $action, $params, $post);
        $success = !in_array($mode, ['csrf-error', 'field-error'], true);
        $check(
            $name . '/' . $mode . ': expected action branch executes',
            $success ? $response->status === 302 && $h->persistence->flushes === 1
                : $response->status === 200 && $h->persistence->flushes === 0
        );
        $check($name . '/' . $mode . ': removed workflow sends no mail', count($h->transport->messages) === 0);
        if ($name === 'setup' || $name === 'admin-add') {
            $created = array_values(array_filter($h->persistence->persisted, static fn (object $e): bool => $e instanceof \Entities\Admin));
            $admin = $created[0] ?? null;
            $check(
                $name . '/' . $mode . ': accepted request persists the expected administrator',
                $success ? count($created) === 1 && $admin instanceof \Entities\Admin
                    && $admin->getUsername() === 'new@example.test' && $admin->getActive() === true
                    && $admin->isSuper() === ($name === 'setup')
                    && \OSS_Auth_Password::verify(WorkflowHarness::PASSWORD, $admin->requiredPassword(), ['pwhash' => 'crypt:sha512'])
                    : $created === []
            );
        }
        if ($name === 'setup') {
            $versions = array_values(array_filter($h->persistence->persisted, static fn (object $e): bool => $e instanceof \Entities\DatabaseVersion));
            $version = $versions[0] ?? null;
            $check(
                $name . '/' . $mode . ': accepted setup persists the database version',
                $success ? count($versions) === 1 && $version instanceof \Entities\DatabaseVersion
                    && $version->getVersion() === \ViMbAdmin_Version::DBVERSION
                    && $version->getName() === \ViMbAdmin_Version::DBVERSION_NAME
                    : $versions === []
            );
        }
        if ($name === 'mailbox-add') {
            $created = array_values(array_filter($h->persistence->persisted, static fn (object $e): bool => $e instanceof \Entities\Mailbox));
            $mailbox = $created[0] ?? null;
            $check(
                $name . '/' . $mode . ': accepted request persists the expected mailbox',
                $success ? count($created) === 1 && $mailbox instanceof \Entities\Mailbox
                    && $mailbox->getUsername() === 'new@example.test' && $mailbox->getLocalPart() === 'new'
                    && $mailbox->getDomain() === $h->domain && $mailbox->getName() === 'New'
                    && $mailbox->getQuota() === 1024 && $mailbox->getAltEmail() === 'alternate@example.test'
                    && $mailbox->getActive() === true && $mailbox->getDeletePending() === false
                    && \OSS_Auth_Password::verify(WorkflowHarness::PASSWORD, $mailbox->requiredPassword(), ['pwhash' => 'crypt:sha512'])
                    : $created === []
            );
        }
        if ($name === 'mailbox-edit') {
            $check(
                $name . '/' . $mode . ': mailbox fields follow the accepted request',
                $h->mailbox->getName() === ($success ? 'Edited' : null)
                && $h->mailbox->getQuota() === ($success ? 2048 : 0)
                && $h->mailbox->getAltEmail() === ($success ? 'edited@example.test' : null)
            );
        }
        if ($action === 'password') {
            $target = $controller === 'admin' ? $h->target : $h->mailbox;
            $check(
                $name . '/' . $mode . ': password mutation matches the accepted request',
                \OSS_Auth_Password::verify(
                    $success ? 'replacement workflow password' : WorkflowHarness::PASSWORD,
                    $target->requiredPassword(),
                    ['pwhash' => 'crypt:sha512']
                )
            );
        }
        if ($name === 'login') {
            $check($name . '/' . $mode . ': session identity follows password/CSRF result', $h->container->auth()->isAuthenticated() === $success);
            $check($name . '/' . $mode . ': no persistent token is created', $h->persistence->persisted === [] && $h->actor->getRememberMes()->isEmpty());
        }
        if ($controller === 'alias') {
            $alias = $h->alias;
            if ($action === 'add' && $success) {
                $created = array_values(array_filter($h->persistence->persisted, static fn (object $e): bool => $e instanceof \Entities\Alias));
                $candidate = $created[0] ?? null;
                $check($name . '/' . $mode . ': expected alias created', count($created) === 1
                    && $candidate instanceof \Entities\Alias && $candidate->getAddress() === 'new@example.test'
                    && $candidate->getDomain() === $h->domain);
                $alias = $candidate instanceof \Entities\Alias ? $candidate : $h->alias;
            }
            $check(
                $name . '/' . $mode . ': alias destination follows the accepted request',
                $alias->getGoto() === ($success ? 'new@example.test' : 'old@example.test')
            );
            $check(
                $name . '/' . $mode . ': alias AdditionalInfo never writes preferences',
                $action === 'add' && $success ? $alias->getPreferences()->isEmpty()
                    : $alias->getPreference('xpiInfo.department') === 'existing-alias-value'
            );
            $check(
                $name . '/' . $mode . ': observer sees only the supported mutation hook',
                ViMbAdminPlugin_WorkflowProbe::$events === ($success && $mode !== 'plugin-off' ? ['alias_add_addPostflush'] : [])
            );
        }
    }
}

$_COOKIE = ['aval' => 'legacy-userhash', 'bval' => 'legacy-cookie-key'];
$h = new WorkflowHarness(guest: true);
$get = $h->run('auth', 'login');
$check('legacy remember-me cookies alone cannot authenticate', $get->status === 200 && !$h->container->auth()->isAuthenticated());
$h->actor->addPreference((new \Entities\AdminPreference())->setAttribute(\ViMbAdmin_TwoFactor::PREF_SECRET)->setValue('enabled-marker')->setIx(0)->setExpire(0)->setAdmin($h->actor));
$response = $h->run('auth', 'login', [], ['csrf' => 'workflow-csrf', 'username' => 'actor@example.test', 'password' => WorkflowHarness::PASSWORD, 'rememberme' => '1']);
$check(
    'remember-me input cannot bypass the native second-factor gate',
    ($response->headers['Location'] ?? '') === '/auth/totp' && !$h->container->auth()->isAuthenticated()
    && $h->session->get('totp_pending_admin_id') === 1 && $h->persistence->persisted === []
);
$_COOKIE = [];

foreach (['mailbox' => 'mid', 'alias' => 'alid'] as $controller => $idKey) {
    $h = new WorkflowHarness();
    $response = $h->run($controller, 'add', [$idKey => '1']);
    $check(
        $controller . ': legacy edit entry redirects to native edit',
        ($response->headers['Location'] ?? '') === '/' . $controller . '/edit/' . $idKey . '/1'
        && $response->status === 302 && $h->persistence->flushes === 0
    );
}

// Positive controls: neither boundary spy may be disabled globally to satisfy
// the removal checks. The native settings action sends through the same mailer.
$h = new WorkflowHarness();
$response = $h->run('mailbox', 'email-settings', ['mid' => '1', 'send' => '1'], ['csrf' => 'workflow-csrf', 'type' => 'username', 'email' => '']);
$check('mailer spy observes supported settings delivery', $response->body === 'ok' && count($h->transport->messages) === 1);
$message = $h->transport->messages[0] ?? null;
$check('settings email addresses the mailbox with the settings subject', $message instanceof \Symfony\Component\Mime\Email
    && $message->getTo()[0]->getAddress() === 'old@example.test'
    && $message->getSubject() === 'Settings for your mailbox on example.test');

foreach ([false, true] as $enabled) {
    $h = new WorkflowHarness(plugins: $enabled);
    ViMbAdminPlugin_WorkflowProbe::$events = [];
    $response = $h->run('mailbox', 'edit', ['mid' => '1'], ['csrf' => 'workflow-csrf', 'name' => 'Edited', 'quota' => '0', 'alt_email' => '', 'plugin_additionalInfo_department' => 'mailbox-control']);
    $check('mailbox AdditionalInfo opt-in remains functional: ' . (int) $enabled, $response->status === 302
        && $h->mailbox->getPreference('xpiInfo.department') === ($enabled ? 'mailbox-control' : 'existing-mailbox-value')
        && ViMbAdminPlugin_WorkflowProbe::$events === ($enabled ? ['mailbox_add_addPostflush'] : []));
}

// The legacy self-service admin form never had an email checkbox. Keep that
// distinction: it is a retained contract, not another claimed removal.
$h = new WorkflowHarness();
$get = $h->run('admin', 'password', ['aid' => '1']);
$response = $h->run('admin', 'password', ['aid' => '1'], ['csrf' => 'workflow-csrf', 'current_password' => WorkflowHarness::PASSWORD, 'password' => 'new workflow password', 'confirm_password' => 'new workflow password', 'email' => '1']);
$check('self-service admin password preserves no-email contract', !str_contains($get->body, 'name="email"')
    && $response->status === 302 && $h->persistence->flushes === 1 && $h->transport->messages === []);

echo $failures === 0 ? "ALL PASSED ({$checks} checks)\n" : "FAIL: {$failures} of {$checks} checks\n";
exit($failures === 0 ? 0 : 1);
