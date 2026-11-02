#!/usr/bin/env bash
set -euo pipefail
root=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)
php_bin=${PHP_BIN:-php}
psalm_bin=${PSALM_BIN:-$(command -v psalm)}
setup_script=${PSALM_MODEL_SETUP_SCRIPT:-$root/.github/scripts/setup-php-container.sh}
models="$root/var/tmp/psalm-optional"
[[ -f $models/predis.tgz && -d $models/relay ]] || { echo 'FAIL: prepared Psalm analysis inputs missing' >&2; exit 1; }
"$php_bin" "$psalm_bin" --version | grep -q '^Psalm 6\.16\.1' || { echo 'FAIL: pinned Psalm version required' >&2; exit 1; }
fixture=$(mktemp -d "$root/var/tmp/.test-psalm-models.XXXXXX")
trap 'rm -rf -- "${fixture:?}"' EXIT
export MODEL_TEST_CACHE=$models
export MODEL_TEST_REAL_TAR
MODEL_TEST_REAL_TAR=$(command -v tar)
sed -n '/^install_psalm_optional_models() (/,/^)/p' "$setup_script" > "$fixture/prepare.sh"
[[ -s $fixture/prepare.sh ]] || { echo 'FAIL: model preparation owner missing' >&2; exit 1; }
mkdir "$fixture/bin"
cat > "$fixture/bin/curl" <<'CURL'
#!/usr/bin/env bash
set -euo pipefail
url= output=
while [[ $# -gt 0 ]]; do
  case $1 in
    --output) output=$2; shift 2 ;;
    https://*) url=$1; shift ;;
    *) shift ;;
  esac
done
case $url in
  https://codeload.github.com/predis/predis/tar.gz/*) source_file="$MODEL_TEST_CACHE/predis.tgz" ;;
  https://raw.githubusercontent.com/JetBrains/phpstorm-stubs/*/redis/*.php) source_file="$MODEL_TEST_CACHE/redis/${url##*/}" ;;
  https://raw.githubusercontent.com/JetBrains/phpstorm-stubs/*/relay/*.php) source_file="$MODEL_TEST_CACHE/relay/${url##*/}" ;;
  https://raw.githubusercontent.com/JetBrains/phpstorm-stubs/*/LICENSE) source_file="$MODEL_TEST_CACHE/relay/LICENSE" ;;
  *) echo 'unsupported fixture source' >&2; exit 90 ;;
esac
case ${MODEL_TEST_MODE:-ok} in
  fetch-error) exit 22 ;;
  missing-file) exit 0 ;;
  symlink-file) ln -s "$source_file" "$output"; exit 0 ;;
  bad-hash)
    if [[ $url == */relay/AdaptiveCache.php ]]; then printf 'corrupted model\n' > "$output"; exit 0; fi
    ;;
esac
cp -- "$source_file" "$output"
CURL
cat > "$fixture/bin/tar" <<'TAR'
#!/usr/bin/env bash
set -euo pipefail
if [[ ${MODEL_TEST_MODE:-ok} == unsupported-source && $1 == -tzf ]]; then
  echo '../unsupported-source'
  exit 0
fi
exec "$MODEL_TEST_REAL_TAR" "$@"
TAR
chmod +x "$fixture/bin/curl" "$fixture/bin/tar"
prepare_at() {
  MODEL_TEST_MODE=$1 PATH="$fixture/bin:$PATH" bash -c 'source "$1"; install_psalm_optional_models "$2"' bash "$fixture/prepare.sh" "$2"
}
mkdir "$fixture/positive"
prepare_at ok "$fixture/positive" > "$fixture/prepare-positive.log" 2>&1
[[ -f $fixture/positive/var/tmp/psalm-optional/predis/src/ClientInterface.php ]] || { echo 'FAIL: real Predis source prepared' >&2; exit 1; }
[[ -f $fixture/positive/var/tmp/psalm-optional/relay/Cluster.php && -f $fixture/positive/var/tmp/psalm-optional/relay/LICENSE ]] || { echo 'FAIL: licensed Relay declarations prepared' >&2; exit 1; }
echo 'PASS: pinned licensed analysis-source preparation'
for mode in bad-hash fetch-error missing-file symlink-file unsupported-source; do
  mkdir "$fixture/$mode"
  if prepare_at "$mode" "$fixture/$mode" > "$fixture/$mode.log" 2>&1; then
    echo "FAIL: $mode must reject preparation" >&2; exit 1
  fi
  [[ ! -e $fixture/$mode/var/tmp/psalm-optional ]] || { echo "FAIL: $mode promoted partial sources" >&2; exit 1; }
  [[ -z $(find "$fixture/$mode" -name '.psalm-optional.*' -print -quit) ]] || { echo "FAIL: $mode leaked staging" >&2; exit 1; }
  echo "PASS: $mode rejects without promotion"
done
for mode in symlink-parent symlink-destination; do
  mkdir -p "$fixture/$mode/var/tmp" "$fixture/target-$mode"
  if [[ $mode == symlink-parent ]]; then
    rmdir "$fixture/$mode/var/tmp"
    ln -s "$fixture/target-$mode" "$fixture/$mode/var/tmp"
  else
    ln -s "$fixture/target-$mode" "$fixture/$mode/var/tmp/psalm-optional"
  fi
  if prepare_at ok "$fixture/$mode" > "$fixture/$mode.log" 2>&1; then
    echo "FAIL: $mode must reject preparation" >&2; exit 1
  fi
  [[ -z $(find "$fixture/target-$mode" -mindepth 1 -print -quit) ]] || { echo "FAIL: $mode modified symlink target" >&2; exit 1; }
  echo "PASS: $mode rejected"
done
cat > "$fixture/probe.php" <<'PHP'
<?php
function optionalClientProbe(string $dsn): Psr\Cache\CacheItemPoolInterface
{
    $client = Symfony\Component\Cache\Adapter\RedisAdapter::createConnection($dsn);
    return new Symfony\Component\Cache\Adapter\RedisAdapter($client, 'micro');
}
PHP
cat > "$fixture/contract.php" <<'PHP'
<?php
$root = $argv[2];
$fixture = $argv[3];
if ($argv[1] === 'configure') {
    $source = new DOMDocument();
    if (!$source->load($root . '/psalm.xml')) {
        throw new RuntimeException('FAIL: actual Psalm config parses');
    }
    $psalm = realpath($argv[4]);
    $candidates = ['phar://' . $psalm . '/config.xsd', dirname($psalm) . '/../vimeo/psalm/config.xsd'];
    $schema = array_values(array_filter($candidates, 'is_file'))[0] ?? null;
    if ($schema === null || !$source->schemaValidate($schema)) {
        throw new RuntimeException('FAIL: pinned Psalm configuration schema');
    }
    foreach (['off', 'on', 'no-redis', 'no-predis', 'no-relay', 'restored'] as $mode) {
        $doc = new DOMDocument();
        $doc->load($root . '/psalm.xml');
        $config = $doc->documentElement;
        $config->removeAttribute('errorBaseline'); // The inert probe has no application baseline.
        $config->setAttribute('autoloader', $root . '/vendor/autoload.php');
        foreach (iterator_to_array($config->childNodes) as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            if ($node->localName === 'projectFiles') {
                $config->removeChild($node);
            } elseif (($mode === 'off' && in_array($node->localName, ['extraFiles', 'stubs', 'enableExtensions'], true))
                || ($mode === 'no-redis' && $node->localName === 'enableExtensions')
                || ($mode === 'no-predis' && $node->localName === 'extraFiles')
) {
                $config->removeChild($node);
            } else {
                if ($node->localName === 'stubs' && in_array($mode, ['no-redis', 'no-relay'], true)) {
                    $group = $mode === 'no-redis' ? '/redis/' : '/relay/';
                    foreach (iterator_to_array($node->childNodes) as $entry) {
                        if ($entry instanceof DOMElement && str_contains($entry->getAttribute('name'), $group)) {
                            $node->removeChild($entry);
                        }
                    }
                }
                foreach ($node->getElementsByTagNameNS('https://getpsalm.org/schema/config', '*') as $entry) {
                    if (in_array($entry->localName, ['file', 'directory'], true) && $entry->hasAttribute('name')) {
                        $entry->setAttribute('name', $root . '/' . $entry->getAttribute('name'));
                    }
                }
            }
        }
        $project = $doc->createElementNS($config->namespaceURI, 'projectFiles');
        $file = $doc->createElementNS($config->namespaceURI, 'file');
        $file->setAttribute('name', $fixture . '/probe.php');
        $project->appendChild($file);
        $config->appendChild($project);
        $doc->save($fixture . '/' . $mode . '.xml');
    }
    exit;
}
if ($argv[1] === 'assert-native') {
    $mode = $argv[4];
    $issues = json_decode(file_get_contents($fixture . '/' . $mode . '.json'), true, 512, JSON_THROW_ON_ERROR);
    $wanted = match ($mode) {
        'off' => ['Redis', 'RedisArray', 'RedisCluster', 'Predis\\ClientInterface', 'Relay\\Relay', 'Relay\\Cluster'],
        'no-redis' => ['Redis', 'RedisArray', 'RedisCluster'],
        'no-predis' => ['Predis\\ClientInterface'],
        'no-relay' => ['Relay\\Relay', 'Relay\\Cluster'],
        default => [],
    };
    $actual = [];
    foreach ($issues as $issue) {
        if ($issue['type'] !== 'UndefinedClass' || !preg_match('/named (.+) does not exist$/D', $issue['message'], $matches)) {
            throw new RuntimeException('FAIL: unexpected native diagnostic in ' . $mode);
        }
        $actual[] = $matches[1];
    }
    sort($wanted);
    sort($actual);
    if ($actual !== $wanted) {
        throw new RuntimeException('FAIL: exact optional-type diagnostics in ' . $mode . ': ' . json_encode($actual));
    }
    echo 'PASS: native ' . $mode . ' exact ' . count($wanted) . " diagnostic(s)\n";
    exit;
}
throw new RuntimeException('unknown contract operation');
PHP
"$php_bin" "$fixture/contract.php" configure "$root" "$fixture" "$psalm_bin"
for mode in off on no-redis no-predis no-relay restored; do
  status=0
  # Model the CI environment without the optional Redis extension. This flag
  # changes the analyzer process only; production config/runtime is untouched.
  "$php_bin" "$psalm_bin" --disable-extension=redis --no-progress --no-cache --output-format=json \
    --config="$fixture/$mode.xml" > "$fixture/$mode.json" 2> "$fixture/$mode.log" || status=$?
  case $mode in on | restored) expected=0 ;; *) expected=2 ;; esac
  [[ $status -eq $expected ]] || { cat "$fixture/$mode.json" >&2; cat "$fixture/$mode.log" >&2; echo "FAIL: native $mode exit $status" >&2; exit 1; }
  "$php_bin" "$fixture/contract.php" assert-native "$root" "$fixture" "$mode"
  cat "$fixture/$mode.json"
  printf '\n'
done
cat > "$fixture/runtime.php" <<'PHP'
<?php
$root = $argv[1];
require $root . '/vendor/autoload.php';
if (($argv[2] ?? '') === 'accidental-autoload') {
    // Negative control uses real Predis source, never extension stubs.
    spl_autoload_register(static function (string $class) use ($root): void {
        if ($class === 'Predis\\ClientInterface') {
            require $root . '/var/tmp/psalm-optional/predis/src/ClientInterface.php';
        }
    });
}
foreach (['Redis', 'RedisArray', 'RedisCluster', 'Predis\\ClientInterface', 'Relay\\Relay', 'Relay\\Cluster'] as $class) {
    if (class_exists($class) || interface_exists($class)) {
        throw new RuntimeException('FAIL: optional analysis type remains absent at runtime: ' . $class);
    }
}
require $root . '/src/Kernel/Doctrine/EntityManagerFactory.php';
$method = new ReflectionMethod(ViMbAdmin\Kernel\Doctrine\EntityManagerFactory::class, 'buildCache');
foreach (['RedisCache', 'PredisCache'] as $type) {
    $pool = $method->invoke(null, ['type' => $type]);
    if (!$pool instanceof Symfony\Component\Cache\Adapter\ArrayAdapter) {
        throw new RuntimeException('FAIL: missing client preserves ArrayAdapter fallback');
    }
    $item = $pool->getItem('micro');
    $item->set($type);
    $pool->save($item);
    if ($pool->getItem('micro')->get() !== $type) {
        throw new RuntimeException('FAIL: fallback cache round trip');
    }
}
echo "PASS: absent runtime clients and unchanged fallback\n";
PHP
"$php_bin" -n "$fixture/runtime.php" "$root"
if "$php_bin" -n "$fixture/runtime.php" "$root" accidental-autoload > "$fixture/accidental-autoload.log" 2>&1; then
  echo 'FAIL: accidental runtime autoload must be detected' >&2; exit 1
fi
grep -Fq 'FAIL: optional analysis type remains absent at runtime: Predis' "$fixture/accidental-autoload.log" || { cat "$fixture/accidental-autoload.log" >&2; exit 1; }
echo 'PASS: accidental runtime autoload detected'
echo 'ALL PASSED (Psalm optional-model contract)'
