<?php
/**
 * Unit test: the native form core — Form + Field + Validators (Phase 4,
 * docs/ZF1-REMOVAL.md). Pure logic over an in-memory session for the CSRF guard;
 * no framework, no DB, no view.
 *
 * Exit 0 = all passed, 1 = a failure.
 */

require __DIR__ . '/../src/Kernel/Session/SessionStorage.php';
require __DIR__ . '/../src/Kernel/Security/Csrf.php';
require __DIR__ . '/../src/Kernel/Form/Field.php';
require __DIR__ . '/../src/Kernel/Form/Validators.php';
require __DIR__ . '/../src/Kernel/Form/Form.php';
require __DIR__ . '/../src/Kernel/Form/FormRenderer.php';

use ViMbAdmin\Kernel\Form\Field;
use ViMbAdmin\Kernel\Form\Form;
use ViMbAdmin\Kernel\Form\FormRenderer;
use ViMbAdmin\Kernel\Form\Validators;
use ViMbAdmin\Kernel\Security\Csrf;
use ViMbAdmin\Kernel\Session\SessionStorage;

final class ArraySession implements SessionStorage
{
    /** @param array<string,mixed> $data */
    public function __construct(private array $data = []) {}
    public function has(string $key): bool { return array_key_exists($key, $this->data); }
    public function get(string $key): mixed { return $this->data[$key] ?? null; }
    public function set(string $key, mixed $value): void { $this->data[$key] = $value; }
    public function remove(string $key): void { unset($this->data[$key]); }
}

$failures = 0;
function check(string $label, bool $ok): void {
    echo ($ok ? "  ok   " : "  FAIL ") . $label . "\n";
    if (!$ok) { $GLOBALS['failures']++; }
}

echo "== native form core ==\n";

// --- validators ------------------------------------------------------- //
$req = Validators::required();
check('required: empty -> error',   $req('') !== null);
check('required: spaces -> error',  $req('   ') !== null);
check('required: value -> ok',      $req('x') === null);

$email = Validators::email();
check('email: bad -> error',        $email('nope') !== null);
check('email: good -> ok',          $email('a@b.com') === null);
check('email: empty -> ok',         $email('') === null);

check('minLength: short -> error',  Validators::minLength(3)('ab') !== null);
check('minLength: ok',              Validators::minLength(3)('abc') === null);
check('regex: no match -> error',   Validators::regex('/^\d+$/')('a1') !== null);
check('regex: match -> ok',         Validators::regex('/^\d+$/')('123') === null);

// --- form validation -------------------------------------------------- //
$form = new Form();
$form->add(new Field('username', 'Username', 'text', [Validators::required(), Validators::email()]))
     ->add(new Field('password', 'Password', 'password', [Validators::required(), Validators::minLength(4)]))
     ->add(new Field('super', 'Super', 'checkbox'));

check('invalid: missing required fields',
    $form->isValid(['username' => '', 'password' => '']) === false);
$errs = $form->errors();
check('errors map has username + password',
    isset($errs['username']) && isset($errs['password']));
check('checkbox defaults to false when absent',
    $form->values()['super'] === false);

$form2 = new Form();
$form2->add(new Field('username', 'Username', 'text', [Validators::required(), Validators::email()]))
      ->add(new Field('password', 'Password', 'password', [Validators::required(), Validators::minLength(4)]));
check('valid submission passes',
    $form2->isValid(['username' => 'a@b.com', 'password' => 'secret']) === true);
check('no errors on valid form', $form2->errors() === []);
check('values() returns the bound data',
    $form2->values() === ['username' => 'a@b.com', 'password' => 'secret']);

// --- matches (password confirmation) ---------------------------------- //
$confirm = new Form();
$pw = new Field('password', 'Password', 'password', [Validators::required()]);
$confirm->add($pw)
        ->add(new Field('confirm', 'Confirm', 'password', [Validators::matches(static fn() => $pw->value())]));
check('matches: mismatch -> invalid',
    $confirm->isValid(['password' => 'a', 'confirm' => 'b']) === false);
check('matches: equal -> valid',
    $confirm->isValid(['password' => 'a', 'confirm' => 'a']) === true);

// --- CSRF guard ------------------------------------------------------- //
$csrf  = new Csrf(new ArraySession());
$token = $csrf->token();
$cform = new Form($csrf);
$cform->add(new Field('name', 'Name', 'text', [Validators::required()]));

check('csrf: valid token + valid field -> valid',
    $cform->isValid(['name' => 'x', 'csrf' => $token]) === true);
check('csrf: bad token -> invalid',
    $cform->isValid(['name' => 'x', 'csrf' => 'wrong']) === false);
check('csrf: bad token -> _form error',
    isset($cform->errors()['_form']));
check('csrf: missing token -> invalid',
    $cform->isValid(['name' => 'x']) === false);
check('csrfToken() returns the session token',
    $cform->csrfToken() === $token);

// --- renderer --------------------------------------------------------- //
$rcsrf = new Csrf(new ArraySession());
$rtok  = $rcsrf->token();
$rform = new Form($rcsrf);
$rform->add(new Field('username', 'Username', 'text', [Validators::required(), Validators::email()]))
      ->add(new Field('password', 'Password', 'password', [Validators::required()]))
      ->add(new Field('super', 'Super admin', 'checkbox'));

$renderer = new FormRenderer();

// invalid submission so we can assert error markup + value repopulation
$rform->isValid(['username' => 'bad', 'password' => '', 'super' => '1', 'csrf' => $rtok]);
$out = $renderer->render($rform, '/admin/add', 'Add');

check('render: form posts to the action',     str_contains($out, 'action="/admin/add"'));
check('render: text input for username',      str_contains($out, 'name="username"') && str_contains($out, 'type="text"'));
check('render: label rendered',               str_contains($out, '>Username</label>'));
check('render: invalid field gets .error',    str_contains($out, 'control-group error'));
check('render: inline error shown',           str_contains($out, 'help-inline'));
check('render: username value repopulated',   str_contains($out, 'value="bad"'));
check('render: password value NOT echoed',    !str_contains($out, 'value="secret"'));
check('render: checkbox checked from submit',  str_contains($out, 'type="checkbox"') && str_contains($out, 'checked="checked"'));
check('render: hidden csrf token present',     str_contains($out, 'name="csrf"') && str_contains($out, 'value="' . $rtok . '"'));
check('render: submit button label',          str_contains($out, '>Add</button>'));

// escaping
$xform = new Form();
$xform->add(new Field('q', 'Q', 'text'));
$xform->bind(['q' => '"><script>x</script>']);
$xout = $renderer->render($xform, '/x');
check('render: value is HTML-escaped',        str_contains($xout, '&quot;&gt;&lt;script&gt;') && !str_contains($xout, '<script>x'));

// readonly field (edit forms render the domain name read-only)
$roform = new Form();
$roform->add((new Field('domain', 'Domain', 'text'))->setReadonly());
$roform->add(new Field('other', 'Other', 'text'));
$roout = $renderer->render($roform, '/domain/edit/did/1');
check('field: setReadonly is reported',       (new Field('x'))->setReadonly()->isReadonly());
check('render: readonly attr on readonly field', preg_match('/name="domain"[^>]*readonly="readonly"/', $roout) === 1);
check('render: non-readonly field has no readonly', !str_contains(substr($roout, strpos($roout, 'name="other"'), 80), 'readonly'));

echo "\n";
if ($failures === 0) {
    echo "OK: all form-core assertions passed (PHP " . PHP_VERSION . ")\n";
    exit(0);
}
echo "FAIL: {$failures} assertion(s) failed\n";
exit(1);
