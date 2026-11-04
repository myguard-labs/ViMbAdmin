<?php

declare(strict_types=1);

/**
 * Execute the real archive delete/restore actions, Archive entity and service.
 * Collaborator aliases provide private auth/CSRF, ORM event recording, claims,
 * backend and repair queue boundaries; they do not model SQL commits, durable
 * ownership, session/cache behavior or Dovecot protocol acceptance. No source
 * under test is extracted, rewritten or replaced by this fixture.
 *
 * This standalone process must run before any application autoloader is loaded.
 */

interface ArchiveZeroOrm
{
}

interface ArchiveZeroPersistence
{
}


class ArchiveZeroAdmin
{
    public function __construct(public bool $allowed = true)
    {
    }

    public function isSuper(): bool
    {
        return false;
    }

    public function canManageDomain(\Entities\Domain $d): bool
    {
        return $this->allowed;
    }

    public function getFormattedName(): string
    {
        return 'private-test-actor';
    }
}
class ArchiveZeroMailbox
{
}
class ArchiveZeroTask
{
    public const TYPE_REPAIR = 'REPAIR';
}



class ArchiveZeroArchiveRepository
{
    public function __construct(private \ArchiveZeroState $s)
    {
    }

    public function find(mixed $id): ?\Entities\Archive
    {
        return $this->s->archive;
    }
}



abstract class ArchiveZeroControllerBase
{
    protected ArchiveZeroContainer $container;
    public function __construct(public \ArchiveZeroState $state)
    {
        $this->container = new ArchiveZeroContainer();
    }
    protected function em(): object
    {
        return $this->state->em;
    }
    protected function admin(): ?object
    {
        return $this->state->admin;
    }
    protected function param(string $key): mixed
    {
        return '1';
    }
    protected function postBodyCsrfValid(): bool
    {
        return $this->state->csrf;
    }
    protected function flash(string $message, string $level = 'success'): void
    {
        $this->state->flashes[] = [$message,$level];
    }
    protected function redirect(string $path): \ViMbAdmin\Kernel\Http\Response
    {
        return new \ViMbAdmin\Kernel\Http\Response('', 302, 'text/plain', ['Location' => $path]);
    }
}


final class ArchiveZeroFlash
{
    public const ERROR = 'error';
    public const INFO = 'info';
}


final class ArchiveZeroContainer
{ /** @return array<string,bool> */ public function options(): array
{
    return ['private_fixture' => true];
}
}
error_reporting(E_ALL);
set_error_handler(static function (int $s, string $m, string $f, int $l): never {
    throw new \ErrorException($m, 0, $s, $f, $l);
});
$root = dirname(__DIR__);
$controllerSource = $root.'/src/Kernel/Controller/ArchiveController.php';
// No application Composer autoloader is installed in this process: only these
// named collaborators are substituted. The controller and entities are loaded
// unchanged from their real paths below. A duplicate target is a fixture error.
foreach ([
 ArchiveZeroOrm::class => 'Doctrine\\ORM\\EntityManagerInterface',
 ArchiveZeroPersistence::class => 'Doctrine\\Persistence\\ObjectManager',
 ArchiveZeroAdmin::class => 'Entities\\Admin',
 ArchiveZeroMailbox::class => 'Entities\\Mailbox',
 ArchiveZeroTask::class => 'Entities\\MailboxTask',
 ArchiveZeroArchiveRepository::class => 'Repositories\\Archive',
 ArchiveZeroControllerBase::class => 'ViMbAdmin\\Kernel\\Mvc\\AbstractController',
 ArchiveZeroFlash::class => 'ViMbAdmin\\Kernel\\Flash\\FlashMessages',
] as $fixtureClass => $targetClass) {
    if (class_exists($targetClass, false) || interface_exists($targetClass, false)) {
        throw new RuntimeException('Fixture target already loaded: '.$targetClass);
    }
    class_alias($fixtureClass, $targetClass);
}
foreach (['library/ViMbAdmin/Exception.php','library/OSS/Doctrine2/WithPreferences.php','application/Entities/Domain.php','application/Entities/Archive.php','application/Entities/Log.php','src/Kernel/Http/Response.php','src/Kernel/Input/Reader.php','library/ViMbAdmin/Service/Archive.php'] as $f) {
    require $root.'/'.$f;
}
require $controllerSource;
final class ArchiveZeroState
{
    /** @var list<string> */
    public array $events = [];
    /** @var list<array{string,string}> */
    public array $flashes = [];
    public Entities\Archive $archive;
    public ?ArchiveZeroAdmin $admin;
    public ArchiveZeroEntityManager $em;
    public bool $csrf = true;
    public string $failure = '';
    public bool $guardFailure = false;
    public bool $removed = false;
    public int $finishes = 0;
    public function __construct(?string $dest)
    {
        $this->admin = new ArchiveZeroAdmin();
        $domain = (new \ReflectionClass(Entities\Domain::class))->newInstanceWithoutConstructor();
        $this->archive = (new Entities\Archive())->setUsername('private@example.test')->setDomain($domain)->setStatus(Entities\Archive::STATUS_ARCHIVED);
        if ($dest !== null) {
            $this->archive->setMaildirFile($dest);
        }
        $this->em = new ArchiveZeroEntityManager($this);
    }
}
final class ArchiveZeroEntityManager implements ArchiveZeroOrm, ArchiveZeroPersistence
{
    public function __construct(public ArchiveZeroState $s)
    {
    }
    public function getConnection(): self
    {
        return $this;
    }
    public function getRepository(string $class): object
    {
        return str_ends_with($class, 'Archive') ? new ArchiveZeroArchiveRepository($this->s) : new class () {
            /** @param array<string,mixed> $criteria */
            public function findOneBy(array $criteria): ArchiveZeroMailbox
            {
                return new ArchiveZeroMailbox();
            }
        };
    }
    public function remove(object $o): void
    {
        $this->s->events[] = 'remove';
        $this->s->removed = true;
    }
    public function persist(object $o): void
    {
        $this->s->events[] = 'persist:'.$o::class;
    }
    public function flush(): void
    {
        $this->s->events[] = 'flush';
    }
}
final class ArchiveZeroClaim
{
    public function __construct(private ArchiveZeroState $s)
    {
    }
    public static function acquire(ArchiveZeroEntityManager $em, string $user): self
    {
        $em->s->events[] = 'acquire:'.$user;
        return new self($em->s);
    }
    public function guardArchive(Entities\Archive $a): void
    {
        $this->s->events[] = 'guard';
        if ($this->s->guardFailure) {
            throw new \ViMbAdmin_Exception('fixture archive generation rejected');
        }
    }
    /** @param callable():mixed $op */
    public function remote(callable $op): mixed
    {
        $this->s->events[] = 'remote';
        return $op();
    }
    public function assertReady(): void
    {
        $this->s->events[] = 'ready';
    }
    public function finish(): void
    {
        $this->s->events[] = 'finish';
        $this->s->finishes++;
    }
    public function retainOnFailure(\Throwable $e): void
    {
        $this->s->events[] = 'retain';
    }
}
final class ArchiveZeroBackend
{
    public static ArchiveZeroState $s;
    /** @param array<string,mixed> $o */
    public static function fromOptions(array $o): self
    {
        return new self();
    }
    /** @return array<never,never> */
    public function fsDelete(string $dest): array
    {
        return $this->call('erase', $dest);
    }
    /** @return array<never,never> */
    public function restoreFrom(string $user, string $dest): array
    {
        return $this->call('sync', $dest);
    }
    /** @return array<never,never> */
    private function call(string $what, string $dest): array
    {
        self::$s->events[] = $what.':'.$dest;
        if (self::$s->failure === $what) {
            throw new \RuntimeException('fixture backend rejected '.$what.' destination');
        }
        return [];
    }
}
final class ArchiveZeroQueue
{
    public static function enqueue(ArchiveZeroEntityManager $em, ArchiveZeroMailbox $m, string $type, ArchiveZeroAdmin $actor): bool
    {
        $em->s->events[] = 'enqueue-repair';
        return true;
    }
}
foreach ([ArchiveZeroClaim::class => 'ViMbAdmin_ResourceClaim',ArchiveZeroBackend::class => 'ViMbAdmin_Doveadm',ArchiveZeroQueue::class => 'ViMbAdmin_MailboxQueue'] as $fixtureClass => $targetClass) {
    if (class_exists($targetClass, false)) {
        throw new RuntimeException('Fixture target already loaded');
    }
    class_alias($fixtureClass, $targetClass);
}
final class ArchiveZeroAssertions
{
    public static int $checks = 0;
}
$cases = [];
function archiveZeroCheck(bool $ok, string $label): void
{
    if (!$ok) {
        throw new \RuntimeException('FAIL '.$label);
    }
    ArchiveZeroAssertions::$checks++;
}
function archiveZeroAction(string $action, ?string $dest, string $failure = '', string $restriction = ''): ArchiveZeroState
{
    $s = new ArchiveZeroState($dest);
    $s->failure = $failure;
    if ($restriction === 'tenant') {
        if ($s->admin === null) {
            throw new LogicException('Fixture actor unexpectedly absent');
        }
        $s->admin->allowed = false;
    }
    if ($restriction === 'login') {
        $s->admin = null;
    }
    if ($restriction === 'csrf') {
        $s->csrf = false;
    }
    if ($restriction === 'guard') {
        $s->guardFailure = true;
    }
    ArchiveZeroBackend::$s = $s;
    $c = (new ReflectionClass(ViMbAdmin\Kernel\Controller\ArchiveController::class))->newInstance($s);
    $response = $action === 'delete' ? $c->deleteAction() : $c->restoreAction();
    archiveZeroCheck($response->status === 302, 'action redirects');
    return $s;
}
function archiveZeroHas(ArchiveZeroState $s, string $event): bool
{
    return in_array($event, $s->events, true);
}
foreach (['ordinary' => '/backups/private','zero' => '0','missing' => null,'empty' => ''] as $kind => $dest) {
    foreach (['delete','restore'] as $action) {
        $s = archiveZeroAction($action, $dest);
        $calls = $dest !== null && $dest !== '';
        archiveZeroCheck(archiveZeroHas($s, 'erase:'.$dest) === $calls, $kind.' '.$action.' erase backend called');
        if ($action === 'restore') {
            archiveZeroCheck(archiveZeroHas($s, 'sync:'.$dest) === $calls, $kind.' restore sync backend called');
        }
        archiveZeroCheck($s->removed && $s->finishes === 1, $kind.' '.$action.' row/claim completion');
        archiveZeroCheck(archiveZeroHas($s, 'guard'), $kind.' '.$action.' archive guard invoked');
        if ($action === 'restore') {
            archiveZeroCheck(array_search('finish', $s->events, true) < array_search('enqueue-repair', $s->events, true), $kind.' restore releases claim before repair');
        }
        $cases[$kind.':'.$action] = $s->events;
    }
}
foreach (['delete','restore'] as $action) {
    foreach (['tenant','login','csrf','guard'] as $restriction) {
        $s = archiveZeroAction($action, '0', '', $restriction);
        archiveZeroCheck(!$s->removed && !archiveZeroHas($s, 'erase:0') && !archiveZeroHas($s, 'sync:0'), $action.' '.$restriction.' stops backend/row mutation');
        if ($restriction === 'guard') {
            archiveZeroCheck($s->finishes === 1, $action.' rejected guard finishes claim');
        } else {
            archiveZeroCheck($s->events === [], $action.' '.$restriction.' before claim');
        }
    }
}
foreach (['/backups/private','0'] as $dest) {

    foreach (['delete' => 'erase','restore' => 'sync'] as $action => $failure) {
        $s = archiveZeroAction($action, $dest, $failure);
        archiveZeroCheck(!$s->removed, $action.' backend rejection preserves row for '.json_encode($dest));
        archiveZeroCheck($s->finishes === 1, $action.' backend rejection finalizes claim');
        archiveZeroCheck(!archiveZeroHas($s, 'enqueue-repair'), $action.' failed backend cannot enqueue repair');
    }
    $s = archiveZeroAction('restore', $dest, 'erase');
    archiveZeroCheck($s->removed && $s->finishes === 1, 'nonfatal cleanup keeps restore completion');
    archiveZeroCheck(archiveZeroHas($s, 'erase:'.$dest), 'nonfatal cleanup actually attempted');
}
foreach ([[],new \stdClass()] as $bad) {
    try {
        // Deliberately cross the documented string input boundary for malformed cases.
        (new ReflectionMethod(Entities\Archive::class, 'setMaildirFile'))->invoke(new Entities\Archive(), $bad);
        throw new \RuntimeException('FAIL malformed dest accepted');
    } catch (\TypeError) {
        ArchiveZeroAssertions::$checks++;
    }
}
echo json_encode(['php' => PHP_VERSION,'controller_sha256' => hash_file('sha256', $controllerSource),'checks' => ArchiveZeroAssertions::$checks,'cases' => $cases,'missing_empty_behavior' => 'Current and strict proposal both skip backend and remove archive row; not rejected. No new policy invented.','scope' => 'Actual complete deleteAction and restoreAction (existing mailbox), real Archive entity and Archive service. Parent auth/CSRF/flash container, ORM persistence, claim implementation, backend transport and repair queue are recording/throwing seams; no actual DB/network/cache/session semantics proved.'], JSON_THROW_ON_ERROR)."\n";
echo 'ALL PASSED ('.ArchiveZeroAssertions::$checks.' checks)'."\n";
