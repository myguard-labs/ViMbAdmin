"""Data-only request consistency. Supplied records are never execution authority.

Fixture proof and qualified-actual request shapes are separate. Qualified records
must describe the same candidate/job/guest generation and immutable inputs. This
module performs no transitions, trusted-record lookup, consumption, mint or I/O.
Even matching state=unused/qualified records do not verify authority or runtime
qualification. A later executor must independently verify and atomically consume
trusted admission for the still-current guest before any external action.
"""

import hashlib
import json
import re
from typing import cast

REPOSITORY = "myguard-labs/ViMbAdmin"
SCHEMA = "vimbadmin-local-jit-request-v1"
MAX_RECORD_BYTES = 16384
MAX_JOB_ID = 2**63 - 1
PIN_FIELDS = {
    "runtime_image",
    "baseline_context_seal",
    "code_manifest_sha256",
    "native_manifest_sha256",
    "q2_manifest_sha256",
    "v2_manifest_sha256",
}
BINDING_FIELDS = {
    "repository",
    "candidate_sha",
    "run_id",
    "run_attempt",
    "job_id",
    "guest",
    "pins",
    "qualification_id",
    "admission_id",
}
REQUEST_FIELDS = BINDING_FIELDS | {"schema", "mode", "runner_name"}


class Refused(ValueError):
    """Fixed public diagnostics; never include supplied record contents."""


def require(condition: object, reason: str) -> None:
    if not condition:
        raise Refused(reason)


def _fields(value: object, fields: set[str]) -> dict:
    require(
        type(value) is dict and set(value) == fields, "request/record fields refused"
    )
    return cast(dict, value)


def _pin(value: object, pattern: str = "[0-9a-f]{64}") -> None:
    require(
        type(value) is str and len(value) <= 80 and re.fullmatch(pattern, value),
        "immutable identity/pin refused",
    )


def _digest(value: dict) -> str:
    return hashlib.sha256(
        json.dumps(
            value, sort_keys=True, separators=(",", ":"), ensure_ascii=True
        ).encode("ascii")
    ).hexdigest()


def _binding(request: dict) -> dict:
    _fields(request, REQUEST_FIELDS)
    require(
        request["schema"] == SCHEMA and request["mode"] == "qualified-actual",
        "request schema/mode refused",
    )
    require(request["repository"] == REPOSITORY, "request repository refused")
    _pin(request["candidate_sha"], "[0-9a-f]{40}")
    for key in ("run_id", "run_attempt", "job_id"):
        require(
            type(request[key]) is int and 1 <= request[key] <= MAX_JOB_ID,
            "request job identity refused",
        )
    _fields(request["guest"], {"id", "boot_id"})
    _pin(request["guest"]["id"])  # Maintained Docker outer immutable ID.
    _pin(request["guest"]["boot_id"], "[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}")
    _fields(request["pins"], PIN_FIELDS)
    for key, value in request["pins"].items():
        _pin(value, "sha256:[0-9a-f]{64}" if key == "runtime_image" else "[0-9a-f]{64}")
    for key in ("qualification_id", "admission_id"):
        _pin(request[key])
    require(
        request["qualification_id"] != request["admission_id"],
        "record identities must differ",
    )
    return {key: request[key] for key in BINDING_FIELDS}


def runner_name(request: dict) -> str:
    """Exact identity derived from validated candidate/job/guest/input/record data.

    runner_name itself is ignored while deriving it; callers may use an empty
    placeholder to construct a request. Changing any bound generation changes it.
    """
    binding = _binding(request)
    return "vi-local-ci-" + request["candidate_sha"][:12] + "-" + _digest(binding)[:24]


def request_digest(request: dict) -> str:
    """Content identity after strict shape validation; grants no authority."""
    expected = runner_name(request)
    require(
        type(request["runner_name"]) is str and request["runner_name"] == expected,
        "fresh bound runner identity refused",
    )
    return _digest(request)


def _same_record(record: object, expected: dict) -> None:
    record = _fields(record, set(expected))
    require(
        all(
            type(record[key]) is type(value) and record[key] == value
            for key, value in expected.items()
        ),
        "independent record binding/state mismatch",
    )


def validate_request(
    request: dict, *, qualification: object, admission: object, maintained_pins: dict
) -> dict:
    """Check supplied data only. All authority/execution/qualification flags stay false."""
    digest = request_digest(request)
    require(
        set(maintained_pins) == PIN_FIELDS - {"runtime_image", "baseline_context_seal"},
        "maintained pin fields refused",
    )
    require(
        all(request["pins"][key] == value for key, value in maintained_pins.items()),
        "maintained source pins changed",
    )
    binding = {key: request[key] for key in BINDING_FIELDS | {"runner_name"}}
    _same_record(
        qualification,
        dict(
            binding,
            schema="vimbadmin-local-jit-qualification-v1",
            state="qualified",
            request_sha256=digest,
        ),
    )
    _same_record(
        admission,
        dict(
            binding,
            schema="vimbadmin-local-jit-admission-v1",
            state="unused",
            request_sha256=digest,
        ),
    )
    return {
        "mode": "qualified-actual",
        "request_sha256": digest,
        "runner_name": request["runner_name"],
        "consistency_only": True,
        "authority_verified": False,
        "runtime_qualified": False,
        "execution_allowed": False,
    }


def _unique_object(pairs: list) -> dict:
    result = {}
    for key, value in pairs:
        require(key not in result, "duplicate record field refused")
        result[key] = value
    return result


def decode_record(data: bytes) -> dict:
    """Bounded UTF-8 JSON with duplicate keys refused at every object depth."""
    require(
        type(data) is bytes and len(data) <= MAX_RECORD_BYTES,
        "record size/type refused",
    )
    try:
        result = json.loads(data.decode("utf-8"), object_pairs_hook=_unique_object)
    except (UnicodeError, ValueError, RecursionError):
        raise Refused("record JSON refused") from None
    require(type(result) is dict, "record object required")
    return result
