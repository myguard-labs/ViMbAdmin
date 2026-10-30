#!/usr/bin/env bash
set -euo pipefail

readonly profile=${1:?usage: setup-php-container.sh PROFILE}
readonly composer_version=2.10.3
readonly composer_sha256=7a2d379d5b8ffdaa028580ef26494c36d2feef4b178d3dd1473a4dbc5e17c8d6
readonly phpstan_version=2.2.17
readonly phpstan_sha256=46e0eb600188e5f6945b846e427614ad531ca575dac2659d307c3f2b7e1d6c3e
readonly psalm_version=6.16.1
readonly psalm_sha256=7ace71e4f698466e7e16810679a1895ea623060579aff46fcf9fd0f1c09abe00
readonly php_cs_fixer_version=3.95.25
readonly php_cs_fixer_sha256=80cad475fc5112fdbfab8bd66e51665ed78c1b849b918dab81fb63b7a7003b41
readonly apcu_revision=90899684be91c4f750512c2f840f487286ecb807
readonly apcu_sha256=33f9038e98d236d4833b3e311c21e1d969a96d06b0d1e4427c8a0db2dd7123e2

install_apcu() (
  set -euo pipefail
  local source_dir
  source_dir=$(mktemp -d)
  trap 'rm -rf -- "${source_dir:?}"' EXIT
  curl --fail --silent --show-error --location --connect-timeout 30 --max-time 300 \
    "https://codeload.github.com/krakjoe/apcu/tar.gz/$apcu_revision" --output "$source_dir/apcu.tgz"
  printf '%s  %s\n' "$apcu_sha256" "$source_dir/apcu.tgz" | sha256sum --check --status
  tar -xzf "$source_dir/apcu.tgz" --directory "$source_dir" --strip-components=1
  cd "$source_dir"
  phpize
  ./configure --enable-apcu
  make -j"$(nproc)"
  make install
)

install_verified_phar() {
  local name=$1 expected_sha256=$2 url=$3
  local destination="/usr/local/bin/$name" temporary
  temporary=$(mktemp)
  curl --fail --silent --show-error --location "$url" --output "$temporary"
  printf '%s  %s\n' "$expected_sha256" "$temporary" | sha256sum --check --status
  install -m 0755 "$temporary" "$destination"
  rm -f "$temporary"
  if [[ $name == psalm ]]; then
    "$destination" --root=/tmp --version
  else
    "$destination" --version
  fi
}

install_psalm_optional_models() (
  set -euo pipefail
  local repo_root=$1 parent destination staging member
  local predis_revision=f49e13ee3a2a825631562aa0223ac922ec5d058b
  local predis_sha256=d8d9a3cde3476d9237d4305f0349cdb95d62f29bf262413bc97f161a2927f036
  local relay_revision=709e512210784a7c0a677b3a89d35def844a59b9
  [[ -d $repo_root && ! -L $repo_root ]] || { echo 'Invalid Psalm model root' >&2; exit 1; }
  for parent in "$repo_root/var" "$repo_root/var/tmp"; do
    [[ ! -L $parent && ( ! -e $parent || -d $parent ) ]] || {
      echo 'Unsafe Psalm model parent' >&2; exit 1;
    }
  done
  parent="$repo_root/var/tmp"
  destination="$parent/psalm-optional"
  [[ ! -e $destination && ! -L $destination ]] || {
    echo 'Psalm model destination already exists' >&2; exit 1;
  }
  mkdir -p -- "$parent"
  staging=$(mktemp -d "$parent/.psalm-optional.XXXXXX")
  trap 'rm -rf -- "${staging:?}"' EXIT

  fetch_model_file() {
    local url=$1 expected=$2 output=$3
    curl --fail --silent --show-error --location --connect-timeout 30 --max-time 120 \
      --max-filesize 10485760 "$url" --output "$output"
    [[ -f $output && ! -L $output ]] || { echo 'Missing regular Psalm model file' >&2; exit 1; }
    printf '%s  %s\n' "$expected" "$output" | sha256sum --check --status
  }

  fetch_model_file "https://codeload.github.com/predis/predis/tar.gz/$predis_revision" \
    "$predis_sha256" "$staging/predis.tgz"
  tar -tzf "$staging/predis.tgz" > "$staging/members"
  [[ $(wc -l < "$staging/members") -le 5000 ]] || { echo 'Oversized Psalm model inventory' >&2; exit 1; }
  while IFS= read -r member; do
    [[ $member == "predis-$predis_revision/"* && $member != *'/../'* && $member != *'/./'* ]] || {
      echo 'Unsupported Psalm model archive path' >&2; exit 1;
    }
  done < "$staging/members"
  tar -tvzf "$staging/predis.tgz" | awk '
    substr($0, 1, 1) !~ /^[-d]$/ { exit 1 }
    { bytes += $3 }
    END { if (bytes > 20971520) exit 1 }
  '
  mkdir "$staging/predis" "$staging/relay" "$staging/redis"
  tar -xzf "$staging/predis.tgz" --directory "$staging/predis" --strip-components=1 \
    "predis-$predis_revision/src" "predis-$predis_revision/LICENSE"
  [[ -f $staging/predis/src/ClientInterface.php && -f $staging/predis/LICENSE ]] || {
    echo 'Incomplete Predis analysis source' >&2; exit 1;
  }
  local expected name url output
  while read -r expected name; do
    url="https://raw.githubusercontent.com/JetBrains/phpstorm-stubs/$relay_revision/relay/$name"
    [[ $name != LICENSE ]] || url="https://raw.githubusercontent.com/JetBrains/phpstorm-stubs/$relay_revision/LICENSE"
    output="$staging/relay/$name"
    if [[ $name == RedisArray.php || $name == RedisCluster.php ]]; then
      url="https://raw.githubusercontent.com/JetBrains/phpstorm-stubs/$relay_revision/redis/$name"
      output="$staging/redis/$name"
    fi
    fetch_model_file "$url" "$expected" "$output"
  done <<'PSALM_RELAY_PINS'
45dd2e0feaac0b8ee13ab5ae349521ffb3b8a417a9ae7be9e3abd301baa6b9a9 AdaptiveCache.php
d0a0bad17415db8cc2e27ff2a968f8dba043ed8c888ef597b319a111ad3dd9ce Cluster.php
d9ba2f5f9c5c833297e15e4f59f72d00c211f74290e56ea91d84c13d591ef4dd Event.php
cd03f1caa43c681b467c1cabd6c80b24f233f88a576fb1f256d4bcc7cb6b0802 Events.php
d1d51612664b91f212065559206e85d191f2112bf885c12de26a75c168c220a1 Exception.php
593d273102493a6148313180ef294af39df4a0eb3288333a0d77529c8821106c KeyType.php
1eff27f960e172fc1941cb6d85c7ca05fdef54e70b857abbc89e56854a3b4e27 Relay.php
dc3114aaac60851fb58fb0629b25d943b3f5aa4dae1ed214e563e1b021340a6c Sentinel.php
90ba01acc9618d8c24ab29f648242641c1fff6862da4a1ad33485b0b636b3049 Table.php
0b6bf6984e35b44f04c68ec5e18950cc10fea56b04576fab95fde116d0290408 LICENSE
ed6d350cb4cf7219454e8af354cce69d980597ec448c97db0fd76893f3963220 RedisArray.php
4c4db458ca8986059e710c1c984e42cb56b5602d52f86738dee1586257a99043 RedisCluster.php
PSALM_RELAY_PINS
  # These are analysis inputs only: never add them to Composer or require them.
  printf 'Predis %s MIT\nJetBrains relay/redis stubs %s Apache-2.0\n' \
    "$predis_revision" "$relay_revision" > "$staging/PROVENANCE"
  rm -- "$staging/members"
  mv -T -n -- "$staging" "$destination"
  [[ ! -e $staging && -d $destination && ! -L $destination ]] || {
    echo 'Psalm model promotion failed' >&2; exit 1;
  }
)

install_verified_phar composer "$composer_sha256" \
  "https://getcomposer.org/download/$composer_version/composer.phar"

case "$profile" in
tools)
  exit 0
  ;;
runtime | cache | static)
  ;;
*)
  printf 'Unknown PHP container profile: %s\n' "$profile" >&2
  exit 64
  ;;
esac

export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install --yes --no-install-recommends \
  ca-certificates git libfreetype6-dev libicu-dev libjpeg62-turbo-dev \
  libpng-dev unzip

docker-php-ext-configure gd --with-freetype --with-jpeg
docker-php-ext-install -j"$(nproc)" gd gettext intl pdo_mysql
if ! php --ri mbstring >/dev/null 2>&1; then
  apt-get install --yes --no-install-recommends libonig-dev
  docker-php-ext-install -j"$(nproc)" mbstring
fi
printf 'memory_limit=-1\n' >/usr/local/etc/php/conf.d/ci-memory.ini

if [[ $profile == cache ]]; then
  # shellcheck disable=SC2086 # PHPIZE_DEPS is an image-provided package list.
  apt-get install --yes --no-install-recommends $PHPIZE_DEPS
  # PHP 8.6 images no longer ship PECL; build the verified release directly.
  install_apcu
  docker-php-ext-enable apcu
  printf 'apc.enable_cli=1\n' >/usr/local/etc/php/conf.d/apcu-cli.ini
fi

if [[ $profile == static ]]; then
  install_psalm_optional_models "$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd -P)"
  install_verified_phar phpstan "$phpstan_sha256" \
    "https://github.com/phpstan/phpstan/releases/download/$phpstan_version/phpstan.phar"
  # Psalm owns only the taint lens (see psalm.xml), so keep its dependency out
  # of the application's Composer graph.
  install_verified_phar psalm "$psalm_sha256" \
    "https://github.com/vimeo/psalm/releases/download/$psalm_version/psalm.phar"
  # PHP-CS-Fixer enforces the PSR-12 formatting contract in .php-cs-fixer.dist.php.
  install_verified_phar php-cs-fixer "$php_cs_fixer_sha256" \
    "https://github.com/PHP-CS-Fixer/PHP-CS-Fixer/releases/download/v$php_cs_fixer_version/php-cs-fixer.phar"
fi

required_extensions=(ctype dom gd gettext iconv intl json mbstring pdo pdo_mysql sodium)
[[ $profile != cache ]] || required_extensions+=(apcu simplexml)

for extension in "${required_extensions[@]}"; do
  php --ri "$extension" >/dev/null || {
    printf 'Required PHP extension is unavailable: %s\n' "$extension" >&2
    exit 1
  }
done

if php -m | grep -Eiq '^(pcov|xdebug)$'; then
  printf 'Coverage extension loaded despite coverage: none contract.\n' >&2
  exit 1
fi

rm -rf /var/lib/apt/lists/* /tmp/pear
