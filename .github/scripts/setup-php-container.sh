#!/usr/bin/env bash
set -euo pipefail

readonly profile=${1:?usage: setup-php-container.sh PROFILE}
readonly composer_version=2.10.3
readonly composer_sha256=7a2d379d5b8ffdaa028580ef26494c36d2feef4b178d3dd1473a4dbc5e17c8d6
readonly phpstan_version=2.2.5
readonly phpstan_sha256=1b2f03384ebcfd67053b06b69cbc0b9f62bf239349f69eaf723649409789e2e6
readonly psalm_version=6.16.1
readonly psalm_sha256=7ace71e4f698466e7e16810679a1895ea623060579aff46fcf9fd0f1c09abe00
readonly php_cs_fixer_version=3.95.25
readonly php_cs_fixer_sha256=80cad475fc5112fdbfab8bd66e51665ed78c1b849b918dab81fb63b7a7003b41

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
printf 'memory_limit=-1\n' >/usr/local/etc/php/conf.d/ci-memory.ini

if [[ $profile == cache ]]; then
  # shellcheck disable=SC2086 # PHPIZE_DEPS is an image-provided package list.
  apt-get install --yes --no-install-recommends $PHPIZE_DEPS
  pecl install apcu
  docker-php-ext-enable apcu
  printf 'apc.enable_cli=1\n' >/usr/local/etc/php/conf.d/apcu-cli.ini
fi

if [[ $profile == static ]]; then
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
