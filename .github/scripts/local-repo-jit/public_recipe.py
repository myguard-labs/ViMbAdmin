"""Public recipe input identities, with no network, copying or runtime actions.

Parsing proves structure and declared dependency closure, not publisher signatures,
Debian dependency resolution, OCI manifest contents or runtime qualification.
Only a separately reviewed canonical descriptor digest authorizes file checking.
File receipts describe the completed read; a later copier must reverify its input.
"""

from __future__ import annotations

import hashlib
import json
import os
import re
import stat
from collections.abc import Mapping
from dataclasses import dataclass, fields
from typing import Any
from urllib.parse import urlsplit

MAX_DESCRIPTOR_BYTES = 1024 * 1024
MAX_ARTIFACTS = 4096
MAX_INPUT_BYTES = 64 * 1024**3
SHA256 = re.compile(r"[0-9a-f]{64}\Z")
IDENTIFIER = re.compile(r"[a-z0-9][a-z0-9._+-]{0,127}\Z")
VERSION = re.compile(r"[A-Za-z0-9][A-Za-z0-9.:~+_-]{0,127}\Z")
SINGLETONS = frozenset({"base", "runner-archive", "runtime-image"})
KINDS = SINGLETONS | {"runtime-blob", "toolchain", "guest-package"}
TOOLS = frozenset({"python3", "qemu-system-x86_64", "genisoimage"})
GUEST_ROOTS = frozenset(
    {"python3", "qemu-guest-agent", "docker-ce", "docker-ce-cli", "containerd.io"}
)


class RecipeError(ValueError):
    """An input identity, declared closure or retained byte check was refused."""


@dataclass(frozen=True)
class Artifact:
    id: str
    kind: str
    publisher: str
    url: str
    sha256: str
    bytes: int
    version: str
    architecture: str
    dependencies: tuple[str, ...]


@dataclass(frozen=True)
class Descriptor:
    canonical: bytes
    sha256: str
    artifacts: tuple[Artifact, ...]


@dataclass(frozen=True)
class RetainedFile:
    """An explicit local file choice and its recorded original public URL."""

    path: str
    origin: str


def _keys(value: Any, expected: set[str] | frozenset[str], label: str) -> None:
    if type(value) is not dict or set(value) != expected:
        raise RecipeError(f"{label}: exact fields required")


def _token(value: Any, pattern: re.Pattern[str], label: str) -> str:
    if type(value) is not str or pattern.fullmatch(value) is None:
        raise RecipeError(f"{label}: invalid token")
    return value


def _pairs(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
    result: dict[str, Any] = {}
    for key, value in pairs:
        if key in result:
            raise RecipeError("duplicate JSON key")
        result[key] = value
    return result


def _public_url(artifact: dict[str, Any]) -> None:
    url = artifact["url"]
    if type(url) is not str or not re.fullmatch(r"https://[A-Za-z0-9._~+:/@=-]+", url):
        raise RecipeError("public URL: HTTPS ASCII URL without escapes required")
    parts = urlsplit(url)
    if parts.username or parts.password or parts.netloc != parts.hostname:
        raise RecipeError("public URL: credentials and ports refused")
    if any(part in {"", ".", ".."} for part in parts.path.split("/")[1:]):
        raise RecipeError("public URL: noncanonical path")
    publisher, kind, path = artifact["publisher"], artifact["kind"], parts.path
    if publisher == "github-actions-runner" and kind == "runner-archive":
        expected = (
            "/actions/runner/releases/download/v"
            + artifact["version"]
            + "/actions-runner-linux-x64-"
            + artifact["version"]
            + ".tar.gz"
        )
        valid = parts.hostname == "github.com" and path == expected
    elif publisher == "debian-cloud" and kind == "base":
        valid = (
            parts.hostname == "cloud.debian.org"
            and re.fullmatch(
                r"/images/cloud/[a-z0-9-]+/[0-9]{8}-[0-9]+/"
                r"debian-[A-Za-z0-9._-]+-amd64-[0-9]{8}-[0-9]+\.qcow2",
                path,
            )
            is not None
        )
    elif publisher == "debian-snapshot" and kind in {"toolchain", "guest-package"}:
        valid = (
            parts.hostname == "snapshot.debian.org"
            and re.fullmatch(
                r"/archive/debian(?:-security)?/[0-9]{8}T[0-9]{6}Z/pool/"
                r"[A-Za-z0-9._+~/-]+\.deb",
                path,
            )
            is not None
        )
    elif publisher == "docker-debian" and kind == "guest-package":
        valid = (
            parts.hostname == "download.docker.com"
            and re.fullmatch(
                r"/linux/debian/dists/[a-z]+/pool/stable/amd64/[A-Za-z0-9._+~-]+\.deb",
                path,
            )
            is not None
        )
    elif publisher == "docker-official-debian" and kind in {
        "runtime-image",
        "runtime-blob",
    }:
        segment = "manifests" if kind == "runtime-image" else "blobs"
        valid = (
            parts.hostname == "registry-1.docker.io"
            and path == f"/v2/library/debian/{segment}/sha256:{artifact['sha256']}"
        )
    else:
        valid = False
    if not valid:
        raise RecipeError("public origin: publisher, kind and pinned URL disagree")


def parse_descriptor(raw: bytes) -> Descriptor:
    """Validate a complete declared recipe and canonicalize unordered sets/maps.

    Executable inputs target amd64; Debian packages may use architecture all.
    Guest roots and tool names are fixed required contracts; dependencies must be
    complete and free of unused inputs; package dependency cycles are permitted.
    Archive metadata must independently substantiate those dependency declarations.
    No lock with unknown origins, zero sizes or missing required roles is accepted.
    """
    if type(raw) is not bytes or not 0 < len(raw) <= MAX_DESCRIPTOR_BYTES:
        raise RecipeError("descriptor: bounded nonempty JSON bytes required")
    try:
        data = json.loads(raw, object_pairs_hook=_pairs)
    except (ValueError, UnicodeError, RecursionError) as exc:
        raise RecipeError("descriptor: malformed or duplicate JSON") from exc
    _keys(data, {"schema", "recipe", "artifacts", "tools", "guest_roots"}, "descriptor")
    if type(data["schema"]) is not int or data["schema"] != 1:
        raise RecipeError("descriptor: schema 1 required")
    _token(data["recipe"], IDENTIFIER, "recipe")
    rows = data["artifacts"]
    if type(rows) is not list or not 1 <= len(rows) <= MAX_ARTIFACTS:
        raise RecipeError("artifacts: bounded nonempty array required")
    artifacts: dict[str, Artifact] = {}
    artifact_fields = frozenset(field.name for field in fields(Artifact))
    for row in rows:
        _keys(row, artifact_fields, "artifact")
        for key in ("id", "kind", "publisher"):
            _token(row[key], IDENTIFIER, key)
        if row["id"] in artifacts or row["kind"] not in KINDS:
            raise RecipeError("artifact: duplicate id or unknown kind")
        _token(row["sha256"], SHA256, "sha256")
        _token(row["version"], VERSION, "version")
        architectures = (
            ("amd64", "all")
            if row["kind"] in {"toolchain", "guest-package"}
            else ("amd64",)
        )
        if row["architecture"] not in architectures:
            raise RecipeError("artifact: unsupported architecture for kind")
        if type(row["bytes"]) is not int or not 1 <= row["bytes"] <= MAX_INPUT_BYTES:
            raise RecipeError("artifact: bounded positive byte length required")
        deps = row["dependencies"]
        if type(deps) is not list or len(deps) > MAX_ARTIFACTS:
            raise RecipeError("dependencies: bounded array required")
        for dep in deps:
            _token(dep, IDENTIFIER, "dependency")
        if len(deps) != len(set(deps)):
            raise RecipeError("dependencies: duplicate entry")
        _public_url(row)
        artifacts[row["id"]] = Artifact(**{**row, "dependencies": tuple(sorted(deps))})
    roots: set[str] = set()
    for kind in sorted(SINGLETONS):
        matches = [item.id for item in artifacts.values() if item.kind == kind]
        if len(matches) != 1:
            raise RecipeError(f"{kind}: exactly one required")
        roots.add(matches[0])
    for key, names, kind in (
        ("tools", TOOLS, "toolchain"),
        ("guest_roots", GUEST_ROOTS, "guest-package"),
    ):
        bindings = data[key]
        _keys(bindings, names, key)
        for value in bindings.values():
            _token(value, IDENTIFIER, key)
            if value not in artifacts or artifacts[value].kind != kind:
                raise RecipeError(f"{key}: missing artifact or wrong kind")
        if len(set(bindings.values())) != len(bindings):
            raise RecipeError(f"{key}: distinct named artifacts required")
        roots.update(bindings.values())
    for item in artifacts.values():
        if any(dep not in artifacts for dep in item.dependencies):
            raise RecipeError("dependencies: missing artifact")
        allowed = {
            "base": set(),
            "runner-archive": set(),
            "runtime-image": {"runtime-blob"},
            "runtime-blob": set(),
            "toolchain": {"toolchain"},
            "guest-package": {"guest-package"},
        }[item.kind]
        if any(artifacts[dep].kind not in allowed for dep in item.dependencies):
            raise RecipeError("dependencies: incompatible kind")
        if item.kind == "runtime-image" and not item.dependencies:
            raise RecipeError("runtime-image: explicit config/layer blobs required")
    # Mark before scheduling: visit each input once, including package cycles,
    # without trusting recursion depth or dropping declared dependency edges.
    reachable = set(roots)
    todo = sorted(roots)
    while todo:
        for dep in artifacts[todo.pop()].dependencies:
            if dep not in reachable:
                reachable.add(dep)
                todo.append(dep)
    if reachable != set(artifacts):
        raise RecipeError("artifacts: unreferenced input")
    ordered = tuple(artifacts[key] for key in sorted(artifacts))
    data["artifacts"] = [
        dict(vars(item), dependencies=list(item.dependencies)) for item in ordered
    ]
    canonical = (json.dumps(data, sort_keys=True, separators=(",", ":")) + "\n").encode(
        "ascii"
    )
    return Descriptor(canonical, hashlib.sha256(canonical).hexdigest(), ordered)


def _read_retained(path: str, artifact: Artifact) -> None:
    # Walk each component without following links, including parent directories.
    if type(path) is not str or not path.startswith("/") or "\x00" in path:
        raise RecipeError("retained path: absolute canonical path required")
    components = path.split("/")[1:]
    if any(part in {"", ".", ".."} for part in components):
        raise RecipeError("retained path: noncanonical component")
    fd = os.open("/", os.O_RDONLY | os.O_DIRECTORY)
    try:
        for component in components[:-1]:
            next_fd = os.open(
                component, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=fd
            )
            os.close(fd)
            fd = next_fd
        file_fd = os.open(
            components[-1], os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=fd
        )
        try:
            before = os.fstat(file_fd)
            if not stat.S_ISREG(before.st_mode) or before.st_nlink != 1:
                raise RecipeError("retained file: regular unaliased file required")
            if before.st_size != artifact.bytes:
                raise RecipeError("retained file: wrong byte length")
            digest = hashlib.sha256()
            remaining = artifact.bytes + 1
            while remaining:
                chunk = os.read(file_fd, min(1024 * 1024, remaining))
                if not chunk:
                    break
                digest.update(chunk)
                remaining -= len(chunk)
            after = os.fstat(file_fd)

            def seal(s: os.stat_result) -> tuple[int, int, int, int, int]:
                return s.st_dev, s.st_ino, s.st_size, s.st_mtime_ns, s.st_ctime_ns

            if seal(before) != seal(after) or remaining != 1:
                raise RecipeError("retained file: changed during verification")
            if digest.hexdigest() != artifact.sha256:
                raise RecipeError("retained file: wrong SHA256")
        finally:
            os.close(file_fd)
    finally:
        os.close(fd)


def verify_retained(
    raw: bytes, selected: Mapping[str, RetainedFile], *, expected_sha256: str
) -> dict[str, Any]:
    """Check explicit offline choices against a separate trusted recipe digest.

    Validate every origin/selection before opening any artifact. There is no
    downloader, filesystem scan, copy, archive extraction or automatic fallback.
    Caller supplies the trusted digest independently of the untrusted descriptor.
    """
    descriptor = parse_descriptor(raw)
    _token(expected_sha256, SHA256, "trusted recipe sha256")
    if descriptor.sha256 != expected_sha256:
        raise RecipeError("descriptor: trusted identity mismatch")
    if set(selected) != {item.id for item in descriptor.artifacts}:
        raise RecipeError("selection: exact explicit artifact choices required")
    for item in descriptor.artifacts:
        choice = selected[item.id]
        if type(choice) is not RetainedFile or choice.origin != item.url:
            raise RecipeError("selection: recorded public origin mismatch")
    try:
        for item in descriptor.artifacts:
            _read_retained(selected[item.id].path, item)
    except OSError as exc:
        raise RecipeError("retained file: inaccessible or linked path") from exc
    return {
        "schema": 1,
        "recipe_sha256": descriptor.sha256,
        "artifacts": [
            {"id": item.id, "url": item.url, "sha256": item.sha256, "bytes": item.bytes}
            for item in descriptor.artifacts
        ],
    }
