"""Offline baseline-install admission. No credentials, native execution or network."""
import hashlib
import os
import posixpath
import stat
import tarfile
from pathlib import Path

ARCHIVE_SHA = '04cf0be1aff4c3ec3554466c39124ca250e3effd8873bb7e8d68535aa9505d5d'
PAYLOAD_BYTES = 675809636
ENTRIES = 11432
RESERVE_BYTES = 268435456
RESERVE_INODES = 1024
MIN_ROOT_BYTES = 3221225472
ROOT_INODES = 1  # The new installation directory, excluded from ENTRIES.


def require(value, message):
    if not value:
        raise ValueError(message)


def owned_directory(path):
    value = path.lstat()
    require(stat.S_ISDIR(value.st_mode) and value.st_uid == os.geteuid(), 'foreign/symlink directory')


def root_ready(root=Path('/'), filesystem=os.statvfs):
    owned_directory(root)
    value = filesystem(root)
    require(value.f_blocks * value.f_frsize >= MIN_ROOT_BYTES, 'root filesystem growth unproved before provisioning')


def admit(archive, target, filesystem=os.statvfs):
    """Conservative full unpacked payload and all entries plus explicit reserves."""
    owned_directory(target.parent)
    require(not target.exists() and not target.is_symlink(), 'runner destination preexists')
    info = archive.lstat()
    # Public ISO ownership originates on the host, not from guest-root identity.
    # Authenticate immutable public content below; target-directory ownership is separate.
    require(stat.S_ISREG(info.st_mode), 'symlink/nonregular public archive')
    with archive.open('rb') as stream:
        require(hashlib.file_digest(stream, 'sha256').hexdigest() == ARCHIVE_SHA, 'archive hash/truncation refused')
    inventory(archive)
    value = filesystem(target.parent)
    available = value.f_bavail * value.f_frsize
    require(available >= PAYLOAD_BYTES + RESERVE_BYTES, 'insufficient available bytes before extraction')
    require(value.f_favail >= ENTRIES + ROOT_INODES + RESERVE_INODES, 'insufficient available inodes before extraction')
    return {'payload_bytes': PAYLOAD_BYTES, 'entries': ENTRIES, 'reserve_bytes': RESERVE_BYTES,
            'reserve_inodes': RESERVE_INODES, 'available_bytes': available, 'available_inodes': value.f_favail}


def inventory(archive):
    """Count canonical non-root entries; one inert root directory is optional."""
    members, seen, root_seen, total = [], set(), False, 0
    with tarfile.open(archive) as payload:
        for member in payload:
            if member.name in ('.', './'):
                require(not root_seen and member.isdir() and member.size == 0, 'archive root entry refused')
                root_seen = True
                continue
            name = member.name
            require(not name.startswith('/') and '\\' not in name and '\x00' not in name, 'archive path refused')
            while name.startswith('./'):
                name = name[2:]
            name = name.rstrip('/')
            require(name and '..' not in name.split('/') and name == posixpath.normpath(name), 'archive path refused')
            require(name not in seen, 'duplicate archive entry refused')
            seen.add(name)
            require(member.isfile() or member.isdir() or member.issym(), 'archive special entry refused')
            require(0 <= member.size <= 536870912 and (member.isfile() or member.size == 0), 'archive member size refused')
            if member.issym():
                link = member.linkname
                require(link and not link.startswith('/') and '\\' not in link and '\x00' not in link, 'archive link refused')
                resolved = posixpath.normpath(posixpath.join(posixpath.dirname(name), link))
                require(resolved != '..' and not resolved.startswith(('../', '/')), 'archive link refused')
            total += member.size
            require(len(members) < ENTRIES and total <= PAYLOAD_BYTES, 'archive header inventory refused')
            members.append((name, member))
    require(len(members) == ENTRIES and total == PAYLOAD_BYTES, 'archive header inventory refused')
    return members


def verify_extracted(archive, target):
    """Detect a partial/truncated unpack, in addition to the installer's pinned hashes."""
    owned_directory(target)
    for name, member in inventory(archive):
        path = target / name
        info = path.lstat()
        require(path.resolve().is_relative_to(target.resolve()), 'extracted path escapes destination')
        if member.isfile():
            require(stat.S_ISREG(info.st_mode) and info.st_size == member.size,
                    'incomplete regular payload extraction')
        elif member.isdir():
            require(stat.S_ISDIR(info.st_mode), 'incomplete directory extraction')
        else:
            require(stat.S_ISLNK(info.st_mode) and os.readlink(path) == member.linkname, 'incomplete symlink extraction')
