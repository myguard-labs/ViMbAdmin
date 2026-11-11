"""Privileged local qualification and admission; no issuer or executable CLI.

Provisioning boundary: an independent trusted operator/service must install this
store on a local filesystem with durable fsync and advisory flock semantics.
The service and every writer run as root; public callers cannot write its code,
store or ancestors. Root (and an in-process code injector) is inside this trust
boundary. A configurable path is privileged service configuration, never request
input. No anchor is installed by this module. Ordinary public records are not
accepted here. The current observation and evidence must come from maintained
backend/closure qualification, not from schema validation or this provider.

Store: qualifications/<qualification_id>.json, evidence/<qualification_id>.json,
current.json, source/<flat source name>, admissions/<admission_id>.json,
and admissions/<admission_id>.permit. The sealed admission JSON binds the
qualification hash; the permit expires at the earlier qualification/current expiry.
Sealed JSON/source files have no write bits. Directories are root-owned and
not group/other writable. Permits are pre-existing root-owned 0600 regular files
with one link, containing b'0\\n' + canonical admission JSON. No create, copy,
reset, renew, delete or rollback operation exists. Provisioning must never reuse
an admission ID or replace a spent inode. Its lifecycle belongs to the issuer.

States: 0=unused, 1=spent, anything else=unknown/denied. Under an exclusive
nonblocking inode lock, re-open and re-verify every bound input and path identity,
then replace only the first byte with 1 and fsync before returning True. A crash
before durable spend cannot have authorized a transport; after durable spend
replay is denied. Partial writes, fsync errors and unknown outcomes never return
success, and never restore 0. A stale actor, changed path or snapshot is denied.
The caller must still perform its actual owned-backend guard before and after
admission. This provider cannot query or qualify a live runtime.
"""

import fcntl
import hashlib
import json
import math
import os
import re
import stat
import time
from pathlib import Path
from typing import NamedTuple

TRUSTED_UID = 0
MAX_RECORD = 16384
MAX_SOURCE = 1024 * 1024
MAX_SOURCES = 64
CHECKS = [
    "backend-identity",
    "consumer-closure",
    "native-policy",
    "owned-reset",
    "source-closure",
]
PIN_FILES = {
    "code_manifest_sha256": "code-inputs.json",
    "native_manifest_sha256": "native-inputs.json",
    "q2_manifest_sha256": "q2-inputs.json",
    "v2_manifest_sha256": "v2-inputs.json",
}
REQUIRED_SOURCE = {
    "trusted_provider.py",
    "repo_api.py",
    "request_model.py",
    "producer.py",
} | set(PIN_FILES.values())


class Refused(RuntimeError):
    """Fixed diagnostics without supplied data or paths."""


def require(condition, reason):
    if not condition:
        raise Refused(reason)


def canonical(record):
    return json.dumps(
        record, sort_keys=True, separators=(",", ":"), ensure_ascii=True
    ).encode("ascii")


def digest(data):
    return hashlib.sha256(data).hexdigest()


def unique_object(pairs):
    result = {}
    for key, value in pairs:
        require(key not in result, "duplicate trusted field")
        result[key] = value
    return result


def decode(data):
    try:
        value = json.loads(data, object_pairs_hook=unique_object)
    except (ValueError, UnicodeError, RecursionError):
        raise Refused("trusted JSON refused") from None
    require(type(value) is dict, "trusted object required")
    return value


def identity(info):
    return (
        info.st_dev,
        info.st_ino,
        info.st_uid,
        info.st_gid,
        info.st_mode,
        info.st_nlink,
        info.st_size,
        info.st_mtime_ns,
        info.st_ctime_ns,
    )


def safe_name(name):
    require(
        type(name) is str
        and re.fullmatch(r"[a-zA-Z0-9_.-]{1,100}", name)
        and name not in (".", ".."),
        "trusted leaf refused",
    )


def directory(path):
    """Walk every component without following links, including ancestors."""
    path = Path(path)
    require(
        path.is_absolute() and ".." not in path.parts, "absolute trusted path required"
    )
    fd = os.open("/", os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        for part in path.parts[1:]:
            child = os.open(
                part, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=fd
            )
            os.close(fd)
            fd = child
            info = os.fstat(fd)
            # Root-owned sticky /tmp permits safe private fixture/service roots;
            # its sticky rule prevents an untrusted UID replacing our child.
            sticky = info.st_uid == 0 and bool(info.st_mode & stat.S_ISVTX)
            require(
                info.st_uid in (0, TRUSTED_UID)
                and (not info.st_mode & 0o022 or sticky),
                "unsafe trusted ancestor",
            )
        info = os.fstat(fd)
        require(
            info.st_uid == TRUSTED_UID and not info.st_mode & 0o022,
            "unsafe trusted directory",
        )
        return fd
    except BaseException:
        os.close(fd)
        raise


def open_leaf(parent, name, *, writable=False):
    safe_name(name)
    fd = os.open(
        name,
        (os.O_RDWR if writable else os.O_RDONLY) | os.O_NOFOLLOW | os.O_NONBLOCK,
        dir_fd=parent,
    )
    try:
        info = os.fstat(fd)
        require(
            stat.S_ISREG(info.st_mode)
            and info.st_uid == TRUSTED_UID
            and info.st_nlink == 1
            and (
                stat.S_IMODE(info.st_mode) == 0o600
                if writable
                else not info.st_mode & 0o222
            ),
            "unsafe trusted file",
        )
        return fd
    except BaseException:
        os.close(fd)
        raise


def read_leaf(parent, name, limit=MAX_RECORD, *, writable=False):
    fd = open_leaf(parent, name, writable=writable)
    try:
        before = identity(os.fstat(fd))
        require(before[6] <= limit, "trusted file too large")
        data = os.read(fd, limit + 1)
        require(
            len(data) == before[6]
            and identity(os.fstat(fd)) == before
            and identity(os.stat(name, dir_fd=parent, follow_symlinks=False)) == before,
            "trusted file changed",
        )
        return data, before
    finally:
        os.close(fd)


class LocalReceipt(NamedTuple):
    request_sha256: str
    repository: str
    candidate_sha: str
    qualification_id: str
    admission_id: str
    verified_at: int
    expires_at: int


class LocalQualificationProvider:
    """One pending verified receipt per instance; callers serialize verify/claim.

    receipt_type adapts the inert tuple to the maintained API type, not a verifier
    callback. All trust decisions and atomic consumption are implemented here.
    Separate instances/processes coordinate exclusively through the permit inode.
    """

    receipt_type = LocalReceipt

    def __init__(self, store):
        self.store = Path(store)
        self._pending = None

    def _now(self):
        now = time.time()
        require(
            type(now) in (int, float) and math.isfinite(now) and now >= 0,
            "trusted clock refused",
        )
        return int(now)

    def _snapshot(self, request):
        require(
            type(request) is dict
            and request.get("repository") == "myguard-labs/ViMbAdmin"
            and request.get("mode") == "qualified-actual",
            "trusted request refused",
        )
        for key in ("qualification_id", "admission_id"):
            require(
                type(request.get(key)) is str
                and re.fullmatch("[0-9a-f]{64}", request[key]),
                "trusted record identity refused",
            )
        request_hash = digest(canonical(request))
        now = self._now()
        seals = {}
        root = directory(self.store)
        try:
            seals["root"] = identity(os.fstat(root))

            # Each child is opened relative to the same held trusted root.
            def record(folder, name):
                safe_name(folder)
                child = os.open(
                    folder, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=root
                )
                try:
                    info = os.fstat(child)
                    require(
                        info.st_uid == TRUSTED_UID and not info.st_mode & 0o022,
                        "unsafe trusted directory",
                    )
                    seals[folder] = identity(info)
                    raw, seal = read_leaf(child, name)
                    seals[folder + "/" + name] = seal
                    return decode(raw)
                finally:
                    os.close(child)

            qname = request["qualification_id"] + ".json"
            qualified = record("qualifications", qname)
            require(
                set(qualified)
                == {
                    "schema",
                    "request",
                    "sources",
                    "evidence_sha256",
                    "issued_at",
                    "expires_at",
                }
                and qualified["schema"] == "vimbadmin-trusted-qualification-v1"
                and canonical(qualified["request"]) == canonical(request),
                "trusted qualification binding refused",
            )
            issued, expires = qualified["issued_at"], qualified["expires_at"]
            require(
                type(issued) is int
                and type(expires) is int
                and 0 <= issued <= now < expires <= issued + 300,
                "trusted qualification stale",
            )
            sources = qualified["sources"]
            require(
                type(sources) is dict
                and REQUIRED_SOURCE <= set(sources)
                and len(sources) <= MAX_SOURCES,
                "trusted source closure refused",
            )
            sourcefd = os.open(
                "source", os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=root
            )
            try:
                info = os.fstat(sourcefd)
                require(
                    info.st_uid == TRUSTED_UID and not info.st_mode & 0o022,
                    "unsafe trusted source directory",
                )
                seals["source"] = identity(info)
                code_manifest = {}
                for name, expected in sources.items():
                    raw, seal = read_leaf(sourcefd, name, MAX_SOURCE)
                    require(
                        type(expected) is str
                        and re.fullmatch("[0-9a-f]{64}", expected)
                        and digest(raw) == expected,
                        "trusted source changed",
                    )
                    seals["source/" + name] = seal
                    if name == "code-inputs.json":
                        code_manifest = decode(raw)
                require(
                    0 < len(code_manifest) <= MAX_SOURCES, "code manifest size refused"
                )
                for name, entry in code_manifest.items():
                    safe_name(name)
                    require(
                        type(entry) is dict
                        and set(entry) == {"sha256"}
                        and name in sources
                        and entry["sha256"] == sources[name],
                        "code manifest closure changed",
                    )
                require(
                    REQUIRED_SOURCE - set(PIN_FILES.values()) - {"producer.py"}
                    <= set(code_manifest),
                    "code manifest missing trusted verifier inputs",
                )
                require(
                    type(request.get("pins")) is dict
                    and all(
                        request["pins"].get(pin) == sources[name]
                        for pin, name in PIN_FILES.items()
                    ),
                    "trusted source pins changed",
                )
            finally:
                os.close(sourcefd)
            evidence = record("evidence", qname)
            require(
                digest(canonical(evidence)) == qualified["evidence_sha256"]
                and evidence
                == {
                    "schema": "vimbadmin-trusted-runtime-evidence-v1",
                    "request_sha256": request_hash,
                    "sources_sha256": digest(canonical(sources)),
                    "checks": CHECKS,
                    "issued_at": issued,
                    "expires_at": expires,
                },
                "trusted runtime evidence refused",
            )
            raw, seal = read_leaf(root, "current.json")
            seals["current.json"] = seal
            current = decode(raw)
            require(
                set(current)
                == {"schema", "request_sha256", "observed_at", "expires_at"}
                and current["schema"] == "vimbadmin-trusted-current-v1"
                and current["request_sha256"] == request_hash
                and type(current["observed_at"]) is int
                and type(current["expires_at"]) is int
                and 0
                <= current["observed_at"]
                <= now
                < current["expires_at"]
                <= current["observed_at"] + 30,
                "trusted current observation stale or changed",
            )
            permit = record("admissions", request["admission_id"] + ".json")
            require(
                permit
                == {
                    "schema": "vimbadmin-trusted-admission-v1",
                    "request_sha256": request_hash,
                    "qualification_sha256": digest(canonical(qualified)),
                    "expires_at": expires,
                },
                "trusted admission binding refused",
            )
            receipt = self.receipt_type(
                request_hash,
                request["repository"],
                request["candidate_sha"],
                request["qualification_id"],
                request["admission_id"],
                now,
                min(expires, current["expires_at"]),
            )
            admissionfd = os.open(
                "admissions", os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=root
            )
            try:
                require(
                    identity(os.fstat(admissionfd)) == seals["admissions"],
                    "admission directory changed",
                )
                raw, seal = read_leaf(
                    admissionfd, request["admission_id"] + ".permit", writable=True
                )
                require(raw == self._permit(receipt), "unused exact permit required")
                seals["permit"] = seal
            finally:
                os.close(admissionfd)
            return receipt, seals
        finally:
            os.close(root)

    @staticmethod
    def _permit(receipt):
        return b"0\n" + canonical(
            {
                "schema": "vimbadmin-trusted-permit-v1",
                "request_sha256": receipt.request_sha256,
                "admission_id": receipt.admission_id,
                "qualification_id": receipt.qualification_id,
                "expires_at": receipt.expires_at,
            }
        )

    def verify(self, request):
        self._pending = None
        try:
            receipt, seals = self._snapshot(request)
            self._pending = (receipt, decode(canonical(request)), seals)
            return receipt
        except (
            OSError,
            ValueError,
            TypeError,
            KeyError,
            OverflowError,
            RecursionError,
        ):
            raise Refused("trusted qualification unavailable") from None

    def claim_unused(self, receipt):
        pending, self._pending = self._pending, None
        require(
            pending is not None and receipt is pending[0],
            "provider-issued receipt required",
        )
        _, request, seals = pending
        parent = fd = None
        try:
            parent = directory(self.store / "admissions")
            name = request["admission_id"] + ".permit"
            fd = open_leaf(parent, name, writable=True)
            fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
            # Hold the lock across all checks, spending and fsync. No callbacks.
            current, fresh = self._snapshot(request)
            require(
                fresh == seals
                and current.expires_at == receipt.expires_at
                and current.verified_at >= receipt.verified_at
                and self._now() < receipt.expires_at,
                "verified snapshot changed",
            )
            before = identity(os.fstat(fd))
            require(
                identity(os.fstat(parent)) == seals["admissions"]
                and identity(os.stat(name, dir_fd=parent, follow_symlinks=False))
                == before,
                "admission path changed",
            )
            expected = self._permit(receipt)
            require(
                before[6] == len(expected)
                and os.pread(fd, MAX_RECORD + 1, 0) == expected,
                "unused exact permit required",
            )
            require(identity(os.fstat(fd)) == before, "admission changed before spend")
            require(os.pwrite(fd, b"1", 0) == 1, "admission spend uncertain")
            os.fsync(fd)
            after = identity(os.fstat(fd))
            require(
                after[:6] == before[:6]
                and after[6] == before[6]
                and identity(os.stat(name, dir_fd=parent, follow_symlinks=False))
                == after
                and os.pread(fd, MAX_RECORD + 1, 0) == b"1" + expected[1:]
                and self._now() < receipt.expires_at,
                "admission spend uncertain",
            )
            return True
        except (
            OSError,
            ValueError,
            TypeError,
            KeyError,
            OverflowError,
            RecursionError,
        ):
            raise Refused("admission unavailable or outcome uncertain") from None
        finally:
            if fd is not None:
                os.close(fd)
            if parent is not None:
                os.close(parent)
