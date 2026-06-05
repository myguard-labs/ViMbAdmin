<?php
/**
 * Unit test: ViMbAdmin\Kernel\Flash\FlashMessages (Phase 5 foundation,
 * docs/ZF1-REMOVAL.md). Pure logic over the SessionStorage port — no framework,
 * no database, no Composer install.
 *
 * Exit 0 = all passed, 1 = a failure.
 */

require __DIR__ . '/../src/Kernel/Session/SessionStorage.php';
require __DIR__ . '/../src/Kernel/Flash/FlashMessage.php';
require __DIR__ . '/../src/Kernel/Flash/FlashMessages.php';

use ViMbAdmin\Kernel\Session\SessionStorage;
use ViMbAdmin\Kernel\Flash\FlashMessages;
use ViMbAdmin\Kernel\Flash\FlashMessage;

final class ArraySession implements SessionStorage
{
    /** @var array<string,mixed> */
    private array $data = [];
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

echo "== ViMbAdmin\\Kernel\\Flash\\FlashMessages ==\n";

$s = new ArraySession();
$f = new FlashMessages($s);

check('starts empty',                 $f->isEmpty() === true);
check('peek empty -> []',             $f->peek() === []);

$f->success('saved');
$f->error('<b>bad</b>', false);
$f->info('fyi');
$f->warning('careful');

check('not empty after adds',         $f->isEmpty() === false);

$peek = $f->peek();
check('peek returns 4 FlashMessage',  count($peek) === 4 && $peek[0] instanceof FlashMessage);
check('order preserved + levels',
    $peek[0]->level === FlashMessages::SUCCESS && $peek[0]->text === 'saved' &&
    $peek[1]->level === FlashMessages::ERROR   && $peek[1]->isHtml === false &&
    $peek[2]->level === FlashMessages::INFO &&
    $peek[3]->level === FlashMessages::WARNING);
check('peek does NOT clear',          $f->isEmpty() === false);

$drained = $f->drain();
check('drain returns all 4',          count($drained) === 4);
check('drain clears the queue',       $f->isEmpty() === true && $f->peek() === []);
check('second drain -> []',           $f->drain() === []);

// add() generic + level constants match OSS_Message values
$s2 = new ArraySession();
$f2 = new FlashMessages($s2);
$f2->add('x', FlashMessages::WARNING);
check('WARNING == "warning" (OSS alert)', FlashMessages::WARNING === 'warning' && $f2->peek()[0]->level === 'warning');
check('SUCCESS/ERROR/INFO constants',
    FlashMessages::SUCCESS === 'success' && FlashMessages::ERROR === 'error' && FlashMessages::INFO === 'info');

// custom key isolation + round-trip through the raw session array
$s3 = new ArraySession();
$f3 = new FlashMessages($s3, 'altFlash');
$f3->success('hi');
check('custom key used',              is_array($s3->get('altFlash')) && $s3->get('flashMessages') === null);
check('round-trips via session array',$f3->peek()[0]->text === 'hi' && $f3->peek()[0]->level === 'success');

echo "\n";
if ($failures === 0) {
    echo "OK: all FlashMessages assertions passed (PHP " . PHP_VERSION . ")\n";
    exit(0);
}
echo "FAIL: $failures assertion(s) failed\n";
exit(1);
