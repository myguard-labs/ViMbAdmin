"""Format only a fresh owned file descriptor; never resize the immutable base."""
import hashlib
import json
import os
import stat
import subprocess

import baseline_budget as budget

VIRTUAL_BYTES = budget.VIRTUAL_BYTES  # baseline-install proof only
RUN = 'vi-local-ci-208c2d13-v4-capacity-r3-q1'


def require(value, message):
    if not value:
        raise ValueError(message)


def sha(path):
    with path.open('rb') as stream:
        return hashlib.file_digest(stream, 'sha256').hexdigest()


def sync_directory(path):
    fd = os.open(path, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        os.fsync(fd)
    finally:
        os.close(fd)


def fresh_overlay(base, expected, work, command=subprocess.run):
    parent = work.lstat()
    require(stat.S_ISDIR(parent.st_mode) and parent.st_uid == os.geteuid()
            and parent.st_mode & 0o777 == 0o700, 'private owned work directory required')
    source = base.lstat()
    require(stat.S_ISREG(source.st_mode) and expected == budget.BASE_SHA and sha(base) == expected, 'immutable base identity changed')
    baseline = json.loads(command(['qemu-img', 'info', '--output=json', str(base)],
                                 check=True, capture_output=True, text=True).stdout)
    require(baseline['format'] == 'qcow2' and not baseline.get('backing-filename')
            and 0 < baseline['virtual-size'] <= VIRTUAL_BYTES, 'unbound base backing chain/geometry')
    # The read-only base may itself be hardlinked public data. It is never an output.
    destination = work / 'owned.qcow2'
    receipt_path = work / 'overlay-origin.json'
    require(not receipt_path.exists() and not receipt_path.is_symlink(), 'overlay receipt preexists')
    fd = os.open(destination, os.O_RDWR | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    try:
        identity = os.fstat(fd)
        require(identity.st_nlink == 1, 'overlay hardlink refused')
        origin = {'project': 'vimbadmin', 'run': RUN, 'base_sha256': expected,
                  'base_device': source.st_dev, 'base_inode': source.st_ino,
                  'overlay_device': identity.st_dev, 'overlay_inode': identity.st_ino,
                  'overlay_virtual_bytes': VIRTUAL_BYTES, 'scope': 'baseline-install-only'}
        origin.update({'root_start_bytes': budget.ROOT_START_BYTES, 'minimum_root_bytes': budget.MIN_ROOT_BYTES,
                       'filesystem_headroom_bytes': budget.FILESYSTEM_HEADROOM_BYTES,
                       'provision_headroom_bytes': budget.PROVISION_HEADROOM_BYTES})
        with receipt_path.open('x') as stream:
            json.dump(origin, stream, sort_keys=True); stream.write('\n'); stream.flush(); os.fsync(stream.fileno())
        sync_directory(work)
        # Stable descriptor, not a check-then-name resize: replacement cannot redirect writes.
        descriptor = '/proc/self/fd/' + str(fd)
        command(['qemu-img', 'create', '-f', 'qcow2', '-F', 'qcow2', '-b', str(base),
                 descriptor, str(VIRTUAL_BYTES)], pass_fds=(fd,), check=True,
                stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        os.fsync(fd)
        result = command(['qemu-img', 'info', '--output=json', descriptor], pass_fds=(fd,),
                         check=True, capture_output=True, text=True)
        metadata = json.loads(result.stdout)
        require(metadata['format'] == 'qcow2' and metadata['virtual-size'] == VIRTUAL_BYTES
                and metadata['backing-filename'] == str(base), 'fresh overlay geometry/backing refused')
        actual, named = os.fstat(fd), destination.lstat()
        require(actual.st_nlink == 1 and (actual.st_dev, actual.st_ino) == (named.st_dev, named.st_ino)
                and stat.S_ISREG(named.st_mode), 'overlay path replacement/hardlink refused')
        after = base.lstat()
        # Reading public data may update atime; content and identity must stay unchanged.
        require((after.st_dev, after.st_ino, after.st_size, after.st_mtime_ns, after.st_ctime_ns)
                == (source.st_dev, source.st_ino, source.st_size, source.st_mtime_ns, source.st_ctime_ns)
                and sha(base) == expected, 'immutable base changed')
        sync_directory(work)
        return origin
    finally:
        os.close(fd)


def standalone(path, command=subprocess.run):
    metadata = json.loads(command(['qemu-img', 'info', '--output=json', str(path)],
                                 check=True, capture_output=True, text=True).stdout)
    require(metadata['format'] == 'qcow2' and metadata['virtual-size'] == VIRTUAL_BYTES
            and not metadata.get('backing-filename'), 'sealed export is not standalone grown QCOW2')
