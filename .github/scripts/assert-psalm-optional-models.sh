#!/usr/bin/env bash
# Presence preflight only; setup owns the pinned downloads and hash validation.
set -euo pipefail
root=${1:-$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd -P)}
models=$root/var/tmp/psalm-optional
required=(predis/src/ClientInterface.php redis/RedisArray.php redis/RedisCluster.php
  relay/AdaptiveCache.php relay/Cluster.php relay/Event.php relay/Events.php
  relay/Exception.php relay/KeyType.php relay/Relay.php relay/Sentinel.php relay/Table.php)
for input in "${required[@]}"; do
  if [[ ! -f $models/$input || ! -r $models/$input || ! -s $models/$input ]]; then
    printf 'Missing Psalm optional model: %s\n' "$input" >&2
    printf 'Prepare the pinned inputs in a disposable PHP container with: bash .github/scripts/setup-php-container.sh psalm\n' >&2
    exit 1
  fi
done
