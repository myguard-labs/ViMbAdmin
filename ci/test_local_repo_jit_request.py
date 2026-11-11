"""Pure request contracts; synthetic records cannot enable external execution."""

import contextlib
import copy
import hashlib
import importlib.util
import io
import json
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1] / ".github/scripts/local-repo-jit"
spec = importlib.util.spec_from_file_location("request_producer", ROOT / "producer.py")
if spec is None or spec.loader is None:
    raise RuntimeError("Request contract loader unavailable")
producer = importlib.util.module_from_spec(spec)
with patch.object(sys, "path", [str(ROOT), *sys.path]):
    spec.loader.exec_module(producer)
model = producer.request_model
ERRORS = (model.Refused, producer.Refused)


def request_fixture():
    request = {
        "schema": model.SCHEMA,
        "mode": "qualified-actual",
        "repository": "myguard-labs/ViMbAdmin",
        "candidate_sha": "a" * 40,
        "run_id": 312,
        "run_attempt": 2,
        "job_id": 17,
        "runner_name": "",
        "guest": {"id": "b" * 64, "boot_id": "12345678-1234-1234-1234-123456789abc"},
        "pins": {
            "runtime_image": "sha256:" + "c" * 64,
            "baseline_context_seal": "d" * 64,
            "code_manifest_sha256": producer.CODE_SEAL,
            "native_manifest_sha256": hashlib.sha256(
                (ROOT / "native-inputs.json").read_bytes()
            ).hexdigest(),
            "q2_manifest_sha256": producer.Q2_SEAL,
            "v2_manifest_sha256": producer.V2_SEAL,
        },
        "qualification_id": "e" * 64,
        "admission_id": "f" * 64,
    }
    request["runner_name"] = model.runner_name(request)
    return request


def records(request):
    # Independent data producers construct the same wire contract, not a validator-owned receipt.
    digest = hashlib.sha256(
        json.dumps(request, sort_keys=True, separators=(",", ":")).encode()
    ).hexdigest()
    body = {
        key: copy.deepcopy(request[key])
        for key in (
            "repository",
            "candidate_sha",
            "run_id",
            "run_attempt",
            "job_id",
            "runner_name",
            "guest",
            "pins",
            "qualification_id",
            "admission_id",
        )
    }
    return (
        dict(
            body,
            schema="vimbadmin-local-jit-qualification-v1",
            state="qualified",
            request_sha256=digest,
        ),
        dict(
            copy.deepcopy(body),
            schema="vimbadmin-local-jit-admission-v1",
            state="unused",
            request_sha256=digest,
        ),
    )


class RequestContract(unittest.TestCase):
    def setUp(self):
        self.request = request_fixture()
        self.qualification, self.admission = records(self.request)

    def validate(self, request=None, qualification=None, admission=None):
        return producer.validate_request(
            self.request if request is None else request,
            qualification=self.qualification
            if qualification is None
            else qualification,
            admission=self.admission if admission is None else admission,
        )

    def test_matching_records_are_data_only_and_inputs_unchanged(self):
        before = copy.deepcopy((self.request, self.qualification, self.admission))
        with (
            patch("subprocess.run", side_effect=AssertionError("subprocess forbidden")),
            patch("subprocess.Popen", side_effect=AssertionError("listener forbidden")),
            patch(
                "urllib.request.urlopen",
                side_effect=AssertionError("network forbidden"),
            ),
            patch.object(
                producer.RepoAPI, "mint", side_effect=AssertionError("mint forbidden")
            ),
            patch.object(
                producer.DockerGuest,
                "__init__",
                side_effect=AssertionError("runtime forbidden"),
            ),
            patch.object(
                Path, "write_text", side_effect=AssertionError("write forbidden")
            ),
        ):
            result = self.validate()
        self.assertTrue(result["consistency_only"])
        self.assertEqual(result["mode"], "qualified-actual")
        self.assertEqual(result["runner_name"], self.request["runner_name"])
        for key in ("execution_allowed", "runtime_qualified", "authority_verified"):
            self.assertIs(result[key], False)
        self.assertEqual(before, (self.request, self.qualification, self.admission))
        shuffled = dict(reversed(list(self.request.items())))
        self.assertEqual(self.validate(shuffled), result)

    def test_integer_boundaries_and_boolean_rejection(self):
        for field in ("run_id", "run_attempt", "job_id"):
            for value in (1, model.MAX_JOB_ID):
                request = copy.deepcopy(self.request)
                request[field] = value
                request["runner_name"] = model.runner_name(request)
                q, a = records(request)
                self.assertFalse(self.validate(request, q, a)["execution_allowed"])
            for value in (True, False, 0, -1, model.MAX_JOB_ID + 1, 1.0, "1", None):
                request = copy.deepcopy(self.request)
                request[field] = value
                with (
                    self.subTest(field=field, value=value),
                    self.assertRaisesRegex(model.Refused, "job identity"),
                ):
                    self.validate(request)

    def test_missing_unknown_and_nonobject_fields(self):
        for key in self.request:
            request = copy.deepcopy(self.request)
            del request[key]
            with self.subTest(missing=key), self.assertRaises(model.Refused):
                self.validate(request)
        for value in (None, [], "request", True):
            with self.subTest(value=value), self.assertRaises(ERRORS):
                producer.validate_request(value)
        for key in ("external_authority", "token", "credentials", "extra"):
            request = dict(self.request, **{key: "fixture-value"})
            with self.subTest(unknown=key), self.assertRaises(model.Refused):
                self.validate(request)
        for field in ("guest", "pins"):
            for value in (None, [], {}, {"extra": "x"}):
                request = dict(self.request, **{field: value})
                with (
                    self.subTest(field=field, value=value),
                    self.assertRaises(model.Refused),
                ):
                    self.validate(request)

    def test_schema_repository_and_all_immutable_pin_shapes(self):
        for field, value in [
            ("schema", "other"),
            ("mode", "actual"),
            ("repository", "fork/ViMbAdmin"),
            ("candidate_sha", "A" * 40),
            ("candidate_sha", "a" * 39),
            ("candidate_sha", "a" * 40 + "\n"),
            ("candidate_sha", True),
            ("qualification_id", None),
            ("admission_id", "x" * 65),
            ("admission_id", self.request["qualification_id"]),
        ]:
            request = dict(self.request, **{field: value})
            with (
                self.subTest(field=field, value=value),
                self.assertRaises(model.Refused),
            ):
                self.validate(request)
        for field in self.request["pins"]:
            for value in (None, True, "latest", "A" * 64, "a" * 81):
                request = copy.deepcopy(self.request)
                request["pins"][field] = value
                with (
                    self.subTest(field=field, value=value),
                    self.assertRaises(model.Refused),
                ):
                    self.validate(request)
        for field in ("id", "boot_id"):
            request = copy.deepcopy(self.request)
            request["guest"][field] = "invalid"
            with self.subTest(field=field), self.assertRaises(model.Refused):
                self.validate(request)

    def test_maintained_pin_drift_even_when_all_supplied_records_agree(self):
        for field in (
            "code_manifest_sha256",
            "native_manifest_sha256",
            "q2_manifest_sha256",
            "v2_manifest_sha256",
        ):
            request = copy.deepcopy(self.request)
            request["pins"][field] = "0" * 64
            request["runner_name"] = model.runner_name(request)
            q, a = records(request)
            with (
                self.subTest(field=field),
                self.assertRaisesRegex(model.Refused, "maintained source pins"),
            ):
                self.validate(request, q, a)
        with self.assertRaisesRegex(model.Refused, "maintained pin fields"):
            model.validate_request(
                self.request,
                qualification=self.qualification,
                admission=self.admission,
                maintained_pins={},
            )

    def test_exact_runner_identity_changes_with_each_bound_field(self):
        variants = [
            ("candidate_sha", "1" * 40),
            ("run_id", 313),
            ("run_attempt", 3),
            ("job_id", 18),
            ("qualification_id", "1" * 64),
            ("admission_id", "2" * 64),
            ("guest", dict(self.request["guest"], id="2" * 64)),
            (
                "guest",
                dict(
                    self.request["guest"],
                    boot_id="22345678-1234-1234-1234-123456789abc",
                ),
            ),
        ]
        variants += [
            (
                "pins",
                dict(
                    self.request["pins"],
                    **{
                        key: "sha256:" + "2" * 64
                        if key == "runtime_image"
                        else "2" * 64
                    },
                ),
            )
            for key in self.request["pins"]
        ]
        for field, value in variants:
            request = copy.deepcopy(self.request)
            request[field] = value
            with (
                self.subTest(field=field),
                self.assertRaisesRegex(model.Refused, "bound runner"),
            ):
                self.validate(request)
            self.assertNotEqual(model.runner_name(request), self.request["runner_name"])
        for value in (
            "vi-local-ci-208c2d13-01",
            self.request["runner_name"] + "-extra",
            "vi-local-ci-arbitrary-prefix",
            None,
            True,
        ):
            with (
                self.subTest(runner=value),
                self.assertRaisesRegex(model.Refused, "bound runner"),
            ):
                self.validate(dict(self.request, runner_name=value))

    def test_independent_records_reject_missing_consumed_and_every_stale_binding(self):
        # Removing the record guard must make this test fail (causal mutation control).
        for target in ("qualification", "admission"):
            original = getattr(self, target)
            candidates = [
                None,
                {},
                [],
                dict(original, extra="x"),
                dict(original, state="consumed"),
                dict(original, schema="other"),
                dict(original, request_sha256="0" * 64),
            ]
            for key in (
                "repository",
                "candidate_sha",
                "runner_name",
                "qualification_id",
                "admission_id",
            ):
                candidates.append(dict(original, **{key: "stale"}))
            for key in ("run_id", "run_attempt", "job_id"):
                candidates += [
                    dict(original, **{key: original[key] + 1}),
                    dict(original, **{key: True}),
                ]
            candidates += [
                dict(original, guest=dict(original["guest"], id="0" * 64)),
                dict(original, guest=dict(original["guest"], boot_id="stale")),
            ]
            candidates += [
                dict(original, pins=dict(original["pins"], **{key: "stale"}))
                for key in original["pins"]
            ]
            for candidate in candidates:
                kwargs = {
                    "qualification": self.qualification,
                    "admission": self.admission,
                    target: candidate,
                }
                with (
                    self.subTest(target=target, candidate=candidate),
                    self.assertRaises(model.Refused),
                ):
                    producer.validate_request(self.request, **kwargs)
        request = dict(self.request, candidate_sha="2" * 40)
        request["runner_name"] = model.runner_name(request)
        with self.assertRaisesRegex(model.Refused, "record binding"):
            self.validate(request)
        # Boolean record identities cannot equal legitimate integer 1.
        request = dict(self.request, run_id=1)
        request["runner_name"] = model.runner_name(request)
        q, a = records(request)
        a["run_id"] = True
        with self.assertRaises(model.Refused):
            self.validate(request, q, a)

    def test_legacy_config_fixture_shape_and_live_denials(self):
        config = json.loads((ROOT / "config.json").read_text())
        self.assertEqual(producer.validate_config(config), config)
        fixture = {"schema": model.SCHEMA, "mode": "fixture-proof", "config": config}
        self.assertFalse(producer.validate_request(fixture)["execution_allowed"])
        for request in (
            dict(fixture, extra=True),
            dict(fixture, schema="other"),
            dict(fixture, config=[]),
            dict(fixture, config=dict(config, external_authority=True)),
            dict(fixture, config=dict(config, external_authority=0)),
        ):
            with self.subTest(request=request), self.assertRaises(producer.Refused):
                producer.validate_request(request)
        with self.assertRaises(producer.Refused):
            producer.validate_request(fixture, admission=self.admission)
        calls = []

        def transport(*args):
            calls.append(args)

        with self.assertRaises(producer.Refused):
            producer.RepoAPI(external=True, transport=transport)
        api = producer.RepoAPI(transport=transport, guard=lambda: None)
        with self.assertRaisesRegex(producer.v2.Refused, "production capability"):
            api.mint(producer.RUNNER_NAME, 1, producer.v2.scoped_job_labels(312, 2))
        self.assertEqual(calls, [])
        with (
            patch("sys.argv", ["producer", "--run", "--validate-request", "missing"]),
            self.assertRaises(producer.Refused),
        ):
            producer.main()

    def test_bounded_json_duplicate_malformed_and_error_controls(self):
        wire = json.dumps(self.request).encode()
        self.assertEqual(model.decode_record(wire), self.request)
        self.assertEqual(
            model.decode_record(b"{}" + b" " * (model.MAX_RECORD_BYTES - 2)), {}
        )
        for value in (
            b" " * (model.MAX_RECORD_BYTES + 1),
            "str",
            b"[]",
            b"null",
            b"false",
            b"\xff",
            b"{",
            b'{"x":1,"x":2}',
            b'{"guest":{"id":1,"id":2}}',
            b"[" * 1100 + b"]" * 1100,
        ):
            with self.subTest(value=str(value)[:60]), self.assertRaises(model.Refused):
                model.decode_record(value)
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "record.json"
            path.write_bytes(wire)
            self.assertEqual(producer.read_request_record(str(path)), self.request)
            with self.assertRaisesRegex(producer.Refused, "unavailable"):
                producer.read_request_record(str(Path(tmp) / "absent"))
            path.write_bytes(b"x" * (model.MAX_RECORD_BYTES + 100))
            with self.assertRaisesRegex(model.Refused, "size/type"):
                producer.read_request_record(str(path))

    def test_cli_data_only_summary_and_option_refusals(self):
        with tempfile.TemporaryDirectory() as tmp:
            paths = [
                Path(tmp) / name
                for name in ("request.json", "qualification.json", "admission.json")
            ]
            for path, value in zip(
                paths, (self.request, self.qualification, self.admission), strict=True
            ):
                path.write_text(json.dumps(value))
            argv = [
                "producer",
                "--validate-request",
                str(paths[0]),
                "--qualification-record",
                str(paths[1]),
                "--admission-record",
                str(paths[2]),
            ]
            with (
                patch("sys.argv", argv),
                contextlib.redirect_stdout(io.StringIO()) as output,
            ):
                producer.main()
            self.assertEqual(json.loads(output.getvalue()), self.validate())
            for bad in (
                argv + ["--validate-config"],
                ["producer", "--admission-record", str(paths[2])],
                ["producer"],
                ["producer", "--validate-request", str(paths[0])],
            ):
                with (
                    self.subTest(argv=bad),
                    patch("sys.argv", bad),
                    self.assertRaises(ERRORS),
                ):
                    producer.main()
            fixture = {
                "schema": model.SCHEMA,
                "mode": "fixture-proof",
                "config": json.loads((ROOT / "config.json").read_text()),
            }
            paths[0].write_text(json.dumps(fixture))
            with (
                patch("sys.argv", ["producer", "--validate-request", str(paths[0])]),
                contextlib.redirect_stdout(io.StringIO()) as output,
            ):
                producer.main()
            self.assertFalse(json.loads(output.getvalue())["execution_allowed"])
