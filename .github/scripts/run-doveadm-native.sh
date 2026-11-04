#!/usr/bin/env bash
set -euo pipefail
[[ ${RUNNER_ENVIRONMENT:-} == self-hosted ]]
[[ ${GITHUB_RUN_ID:-} =~ ^[0-9]+$ && ${GITHUB_RUN_ATTEMPT:-} =~ ^[0-9]+$ ]]
[[ ${DOVEADM_PHP_MINOR:-} =~ ^8\.[3456]$ ]]
[[ ${DOVEADM_BASE_IMAGE:-} =~ ^mirror\.gcr\.io/library/php@sha256:[0-9a-f]{64}$ ]]
[[ ${DOVEADM_BUILD_ESSENTIAL_VERSION:-} =~ ^[0-9][0-9A-Za-z.+:~_-]*$ ]]
[[ ${DOVEADM_LIBCURL_DEV_VERSION:-} =~ ^[0-9][0-9A-Za-z.+:~_-]*$ ]]
readonly owner="doveadm-${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}-${DOVEADM_PHP_MINOR//./}"
case "$DOVEADM_PHP_MINOR" in
  8.3) readonly container=vi-ci307-doveadm-native-83 image=vimbadmin-doveadm-native:83 ;;
  8.4) readonly container=vi-ci307-doveadm-native-84 image=vimbadmin-doveadm-native:84 ;;
  8.5) readonly container=vi-ci307-doveadm-native-85 image=vimbadmin-doveadm-native:85 ;;
  8.6) readonly container=vi-ci307-doveadm-native-86 image=vimbadmin-doveadm-native:86 ;;
esac
receipt_dir=$(mktemp -d)
context=$(mktemp -d "$receipt_dir/context.XXXXXX")
readonly nonce=${receipt_dir##*/}
readonly container_receipt=$receipt_dir/container.id
readonly image_receipt=$receipt_dir/image.id
cid=''
image_id=''
archive=''
source_tree=''
retain_context=0
build_attempted=0
create_attempted=0
# Lifecycle: absent -> creation attempted -> receipt -> verified -> used -> removed.
# Docker writes immutable IDs to private receipts, including before a CLI failure.
# A failed/interrupted CLI with no receipt is recovered only by this invocation's
# unique labels, then verified by ID. Names/tags never authorize a mutation.
# Keep receipts after cleanup so an interrupted run can be investigated safely.
persist_receipt() {
  python3 - "$1" <<'PY'
import os
import stat
import sys
from pathlib import Path

path = Path(sys.argv[1])
fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
try:
    info = os.fstat(fd)
    if not stat.S_ISREG(info.st_mode) or info.st_uid != os.geteuid():
        raise ValueError('private owned receipt required')
    # The enclosing receipt directory is private; tighten the opened inode only.
    # Keep the caller's umask for the source archive's original mode semantics.
    os.fchmod(fd, 0o600)
    os.fsync(fd)
finally:
    os.close(fd)
# Persist the file entry and the freshly allocated receipt directory's entry.
for parent in (path.parent, path.parent.parent):
    directory = os.open(parent, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        os.fsync(directory)
    finally:
        os.close(directory)
PY
}
container_identity() {
  [[ $cid =~ ^[0-9a-f]{64}$ &&
    $(docker inspect --format '{{.Id}} {{.Name}} {{index .Config.Labels "myguard.run"}} {{index .Config.Labels "myguard.nonce"}} {{index .Config.Labels "myguard.source-tree"}}' "$cid") == "$cid /$container $owner $nonce $source_tree" ]]
}
image_identity() {
  [[ $image_id =~ ^sha256:[0-9a-f]{64}$ &&
    $(docker image inspect --format '{{.Id}} {{index .Config.Labels "myguard.run"}} {{index .Config.Labels "myguard.nonce"}} {{index .Config.Labels "myguard.source-tree"}}' "$image_id") == "$image_id $owner $nonce $source_tree" ]]
}
remove_owned_container() { docker rm --force "$cid"; }
remove_owned_image() { docker image rm "$image_id"; }
recover_receipt() {
  local kind=$1 receipt=$2 recovered
  # Never recover through a mutable name/tag, even after an absence preflight.
  recovered=$(docker "$kind" ls --all --quiet --no-trunc \
    --filter "label=myguard.run=$owner" --filter "label=myguard.nonce=$nonce" \
    --filter "label=myguard.source-tree=$source_tree") || return 1
  [[ -n $recovered ]] || return 0
  if [[ $kind == container ]]; then
    [[ $recovered =~ ^[0-9a-f]{64}$ ]] || return 1
  else
    [[ $recovered =~ ^sha256:[0-9a-f]{64}$ ]] || return 1
  fi
  printf '%s\n' "$recovered" > "$receipt"
  persist_receipt "$receipt"
}
cleanup() {
  local status=$? failed=0
  trap - EXIT
  trap '' INT TERM
  if [[ $create_attempted == 1 && ! -s $container_receipt ]]; then
    recover_receipt container "$container_receipt" || failed=1
  fi
  if [[ $build_attempted == 1 && ! -s $image_receipt ]]; then
    recover_receipt image "$image_receipt" || failed=1
  fi
  if [[ -s $container_receipt && -z $cid ]]; then
    cid=$(cat "$container_receipt") || failed=1
  fi
  if [[ -s $image_receipt && -z $image_id ]]; then
    image_id=$(cat "$image_receipt") || failed=1
  fi
  if [[ -n $cid ]]; then
    if [[ $cid =~ ^[0-9a-f]{64}$ && $(cat "$container_receipt") == "$cid" ]] &&
      persist_receipt "$container_receipt" && container_identity; then
      remove_owned_container || failed=1
    else
      echo 'Native container ownership mismatch; preserved' >&2
      failed=1
    fi
  fi
  if [[ -n $image_id ]]; then
    if [[ $image_id =~ ^sha256:[0-9a-f]{64}$ && $(cat "$image_receipt") == "$image_id" ]] &&
      persist_receipt "$image_receipt" && image_identity; then
      remove_owned_image || failed=1
    else
      echo 'Native image ownership mismatch; preserved' >&2
      failed=1
    fi
  fi
  [[ -z $archive ]] || rm -f -- "$archive" || failed=1
  if [[ $retain_context == 0 ]]; then rm -rf -- "$context" || failed=1; fi
  printf 'Native cleanup: check=%s cleanup=%s\n' "$status" "$failed"
  printf 'Native ownership receipts: %s\n' "$receipt_dir"
  (( status != 0 )) || status=$failed
  exit "$status"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
archive=$(mktemp)
# A supplied staged tree needs no commit. The CI default is the checked-out tree.
# End option parsing before resolving an input revision, then archive only its hex ID.
source_ref=${DOVEADM_SOURCE_TREE:-'HEAD^{tree}'}
source_tree=$(git rev-parse --verify --end-of-options "${source_ref}^{tree}")
[[ $source_tree =~ ^[0-9a-f]{40}([0-9a-f]{24})?$ ]]
readonly source_tree
[[ ${DOVEADM_CONTEXT_ONLY:-0} == 0 || ${DOVEADM_CONTEXT_ONLY:-0} == 1 ]]
git archive --format=tar "$source_tree" > "$archive"
archive_sha=$(sha256sum "$archive"); archive_sha=${archive_sha%% *}
inventory_sha=$(git ls-tree -r --full-tree "$source_tree" | sha256sum); inventory_sha=${inventory_sha%% *}
tar --no-same-permissions -xf "$archive" -C "$context"
for input in .github/scripts/doveadm-native.Dockerfile .github/scripts/setup-php-container.sh composer.json composer.lock library/ViMbAdmin/Doveadm.php ci/test-refactor-doveadm-contract.php ci/support/doveadm-curl-init-failure.c; do
  [[ -f $context/$input && ! -L $context/$input ]]
done
[[ ! -e $context/.doveadm-source-manifest.json ]]
printf '{"source_tree":"%s","archive_sha256":"%s","tracked_inventory_sha256":"%s"}\n' \
  "$source_tree" "$archive_sha" "$inventory_sha" > "$context/.doveadm-source-manifest.json"
printf 'Native source manifest: '; cat "$context/.doveadm-source-manifest.json"
cp -- "$context/.doveadm-source-manifest.json" "$receipt_dir/source.json"
persist_receipt "$receipt_dir/source.json"
if [[ ${DOVEADM_CONTEXT_ONLY:-0} == 1 ]]; then
  retain_context=1
  printf 'Prepared native context: %s\n' "$context"
  exit 0
fi
existing=$(docker container ls --all --quiet --filter "name=^/${container}$")
[[ -z $existing ]]
existing=$(docker image ls --quiet --filter "reference=$image")
[[ -z $existing ]]
build_attempted=1
docker build --iidfile "$image_receipt" --label "myguard.run=$owner" --label "myguard.nonce=$nonce" --label "myguard.source-tree=$source_tree" --build-arg "PHP_IMAGE=$DOVEADM_BASE_IMAGE" \
  --build-arg "BUILD_ESSENTIAL_VERSION=$DOVEADM_BUILD_ESSENTIAL_VERSION" --build-arg "LIBCURL_DEV_VERSION=$DOVEADM_LIBCURL_DEV_VERSION" \
  --file "$context/.github/scripts/doveadm-native.Dockerfile" "$context"
image_id=$(cat "$image_receipt")
[[ $image_id =~ ^sha256:[0-9a-f]{64}$ ]]
persist_receipt "$image_receipt"
image_identity
create_attempted=1
docker create --cidfile "$container_receipt" --name "$container" --label "myguard.run=$owner" --label "myguard.nonce=$nonce" --label "myguard.source-tree=$source_tree" \
  --network none --read-only --cap-drop ALL --security-opt no-new-privileges \
  --tmpfs /tmp:rw,exec,nosuid,nodev,size=64m --pids-limit 128 \
  "$image_id" php ci/test-refactor-doveadm-contract.php --native-curl-init-failure
cid=$(cat "$container_receipt")
[[ $cid =~ ^[0-9a-f]{64}$ ]]
persist_receipt "$container_receipt"
container_identity
printf 'Owned native witness container: %s\n' "$cid"
printf 'Owned native image: %s; run=%s nonce=%s tree=%s\n' "$image_id" "$owner" "$nonce" "$source_tree"
docker start --attach "$cid"
