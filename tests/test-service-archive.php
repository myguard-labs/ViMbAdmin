<?php
/**
 * Unit test: ViMbAdmin_Service_Archive (docs/ZF1-REMOVAL.md, Phase 4). Pure
 * logic over a fake ObjectManager + real entities — no framework, no DB. Proves
 * the autoprune flip in both directions, the timestamp bookkeeping (OFF→ON
 * resets archivedAt + statusChangedAt; ON→OFF stamps only statusChangedAt), the
 * single flush, the Log write, and the returned state.
 *
 * Exit 0 = all passed, 1 = a failure.
 */

require __DIR__ . '/../vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
    foreach (['Entities\\' => 'Entities', 'Repositories\\' => 'Repositories'] as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $rel  = str_replace('\\', '/', substr($class, strlen($prefix)));
            $file = __DIR__ . '/../application/' . $dir . '/' . $rel . '.php';
            if (is_file($file)) {
                require $file;
            }
            return;
        }
    }
});

require __DIR__ . '/../library/ViMbAdmin/Service/Archive.php';

final class FakeObjectManager implements \Doctrine\Persistence\ObjectManager
{
    /** @var object[] */ public array $persisted = [];
    /** @var object[] */ public array $removed = [];
    public int $flushes = 0;

    public function persist(object $object): void { $this->persisted[] = $object; }
    public function remove(object $object): void { $this->removed[] = $object; }
    public function flush(): void { $this->flushes++; }
    public function find(string $className, $id) { return null; }
    public function clear(): void {}
    public function detach(object $object): void {}
    public function refresh(object $object): void {}
    public function getRepository(string $className) { throw new \RuntimeException('not used'); }
    public function getClassMetadata(string $className) { throw new \RuntimeException('not used'); }
    public function getMetadataFactory() { throw new \RuntimeException('not used'); }
    public function initializeObject(object $obj): void {}
    public function contains(object $object): bool { return false; }

    public function lastLog(): ?\Entities\Log
    {
        for ($i = count($this->persisted) - 1; $i >= 0; $i--) {
            if ($this->persisted[$i] instanceof \Entities\Log) {
                return $this->persisted[$i];
            }
        }
        return null;
    }
}

$failures = 0;
function check(string $label, bool $ok): void {
    echo ($ok ? "  ok   " : "  FAIL ") . $label . "\n";
    if (!$ok) { $GLOBALS['failures']++; }
}

echo "== ViMbAdmin_Service_Archive ==\n";

$actor = new \Entities\Admin();
$actor->setUsername('admin@example.com');

$mkArchive = static function (bool $autoprune): \Entities\Archive {
    $ar = new \Entities\Archive();
    $ar->setUsername('box@example.com');
    $ar->setAutoprune($autoprune);
    return $ar;
};

// --- OFF -> ON: sets autoprune, resets archivedAt + statusChangedAt ----- //
$emOn = new FakeObjectManager();
$arOff = $mkArchive(false);
$arOff->setArchivedAt(new \DateTime('2000-01-01'));
$resOn = (new ViMbAdmin_Service_Archive($emOn))->toggleAutoprune($arOff, $actor);
check('OFF->ON returns true',                $resOn === true);
check('OFF->ON sets autoprune',              (bool) $arOff->getAutoprune() === true);
check('OFF->ON reset archivedAt to now',     $arOff->getArchivedAt() instanceof \DateTime && $arOff->getArchivedAt()->getTimestamp() > strtotime('2001-01-01'));
check('OFF->ON stamped statusChangedAt',     $arOff->getStatusChangedAt() instanceof \DateTime);
check('OFF->ON one flush',                   $emOn->flushes === 1);
check('OFF->ON logged ARCHIVE_REQUEST',      $emOn->lastLog()?->getAction() === \Entities\Log::ACTION_ARCHIVE_REQUEST);
check('OFF->ON log mentions enabled',        str_contains((string) $emOn->lastLog()?->getData(), 'enabled autoprune'));

// --- ON -> OFF: clears autoprune, stamps only statusChangedAt ----------- //
$emOff = new FakeObjectManager();
$arOn  = $mkArchive(true);
$arOn->setArchivedAt(new \DateTime('2000-01-01'));
$resOff = (new ViMbAdmin_Service_Archive($emOff))->toggleAutoprune($arOn, $actor);
check('ON->OFF returns false',               $resOff === false);
check('ON->OFF clears autoprune',            (bool) $arOn->getAutoprune() === false);
check('ON->OFF did NOT touch archivedAt',    $arOn->getArchivedAt()->getTimestamp() === strtotime('2000-01-01'));
check('ON->OFF stamped statusChangedAt',     $arOn->getStatusChangedAt() instanceof \DateTime);
check('ON->OFF one flush',                   $emOff->flushes === 1);
check('ON->OFF logged ARCHIVE_REQUEST',      $emOff->lastLog()?->getAction() === \Entities\Log::ACTION_ARCHIVE_REQUEST);
check('ON->OFF log mentions disabled',       str_contains((string) $emOff->lastLog()?->getData(), 'disabled autoprune'));

echo "\n";
if ($failures === 0) {
    echo "OK: all Service_Archive assertions passed (PHP " . PHP_VERSION . ")\n";
    exit(0);
}
echo "FAIL: {$failures} assertion(s) failed\n";
exit(1);
