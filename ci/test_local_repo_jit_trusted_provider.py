"""Disposable trust stores only; no real permits, network or runtime actions."""

import fcntl
import hashlib
import json
import multiprocessing
import os
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

from ci.test_local_repo_jit_api_binding import Backend, api
from ci.test_local_repo_jit_request import ROOT, producer, request_fixture

provider = producer.trusted_provider
ERRORS = (provider.Refused, api.Refused)


def sha(data):
    return hashlib.sha256(data).hexdigest()


def json_bytes(value):
    return json.dumps(
        value, sort_keys=True, separators=(",", ":"), ensure_ascii=True
    ).encode()


def concurrent_claim(store, request, barrier, result):
    # Child has no credentials, runtime or network. UID override is fixture-only.
    provider.TRUSTED_UID = os.getuid()
    with patch.object(provider.time, "time", return_value=1000):
        verifier = producer.TrustedLocalQualificationProvider(store)
        receipt = verifier.verify(request)
        barrier.wait(timeout=10)
        try:
            result.put(verifier.claim_unused(receipt))
        except provider.Refused:
            result.put(False)


def crash_after_durable_spend(store, request):
    provider.TRUSTED_UID = os.getuid()
    durable_fsync = os.fsync

    def crash(fd):
        durable_fsync(fd)
        os._exit(73)

    with patch.object(provider.time, "time", return_value=1000):
        verifier = producer.TrustedLocalQualificationProvider(store)
        receipt = verifier.verify(request)
        with patch.object(provider.os, "fsync", side_effect=crash):
            verifier.claim_unused(receipt)


class TrustedProvider(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory(prefix="vi-q5a-fixture-")
        self.addCleanup(temporary.cleanup)
        self.store = Path(temporary.name)
        self.uid = patch.object(provider, "TRUSTED_UID", os.getuid())
        self.uid.start()
        self.addCleanup(self.uid.stop)
        self.clock = patch.object(provider.time, "time", return_value=1000)
        self.clock.start()
        self.addCleanup(self.clock.stop)
        self.network = patch(
            "urllib.request.urlopen", side_effect=AssertionError("network forbidden")
        )
        self.network.start()
        self.addCleanup(self.network.stop)
        for folder in ("qualifications", "evidence", "source", "admissions"):
            (self.store / folder).mkdir(mode=0o700)
        self.request = request_fixture()
        # The real historical Q2/V2 manifests are not present or requalified.
        # Independent disposable manifests have their own content hashes/pins.
        manifest_names = set(json.loads((ROOT / "code-inputs.json").read_bytes()))
        source_data = {
            name: (ROOT / name).read_bytes()
            if (ROOT / name).is_file()
            else b'{"disposable_fixture":true}'
            for name in provider.REQUIRED_SOURCE | manifest_names
        }
        source_data["code-inputs.json"] = json_bytes(
            {
                name: {"sha256": sha(data)}
                for name, data in source_data.items()
                if name in manifest_names
            }
        )
        self.sources = {name: sha(data) for name, data in source_data.items()}
        for pin, name in provider.PIN_FILES.items():
            self.request["pins"][pin] = self.sources[name]
        self.request["runner_name"] = producer.request_model.runner_name(self.request)
        self.request_hash = sha(json_bytes(self.request))
        for name, data in source_data.items():
            self.write("source/" + name, data)
        self.evidence = {
            "schema": "vimbadmin-trusted-runtime-evidence-v1",
            "request_sha256": self.request_hash,
            "sources_sha256": sha(json_bytes(self.sources)),
            "checks": [
                "backend-identity",
                "consumer-closure",
                "native-policy",
                "owned-reset",
                "source-closure",
            ],
            "issued_at": 990,
            "expires_at": 1290,
        }
        self.qualified = {
            "schema": "vimbadmin-trusted-qualification-v1",
            "request": self.request,
            "sources": self.sources,
            "evidence_sha256": sha(json_bytes(self.evidence)),
            "issued_at": 990,
            "expires_at": 1290,
        }
        self.current = {
            "schema": "vimbadmin-trusted-current-v1",
            "request_sha256": self.request_hash,
            "observed_at": 990,
            "expires_at": 1020,
        }
        self.admission = {
            "schema": "vimbadmin-trusted-admission-v1",
            "request_sha256": self.request_hash,
            "qualification_sha256": sha(json_bytes(self.qualified)),
            "expires_at": 1290,
        }
        self.permit = {
            "schema": "vimbadmin-trusted-permit-v1",
            "request_sha256": self.request_hash,
            "admission_id": self.request["admission_id"],
            "qualification_id": self.request["qualification_id"],
            "expires_at": 1020,
        }
        self.qpath = "qualifications/" + self.request["qualification_id"] + ".json"
        self.epath = "evidence/" + self.request["qualification_id"] + ".json"
        self.apath = "admissions/" + self.request["admission_id"] + ".json"
        self.ppath = "admissions/" + self.request["admission_id"] + ".permit"
        self.write(self.qpath, self.qualified)
        self.write(self.epath, self.evidence)
        self.write("current.json", self.current)
        self.write(self.apath, self.admission)
        self.write(self.ppath, b"0\n" + json_bytes(self.permit), mode=0o600)
        self.verifier = self.verifier_for()

    def write(self, name, value, mode=0o400):
        path = self.store / name
        if path.exists():
            path.chmod(0o600)
        path.write_bytes(value if isinstance(value, bytes) else json_bytes(value))
        path.chmod(mode)

    def verifier_for(self, store=None):
        return producer.TrustedLocalQualificationProvider(store or self.store)

    def client(self, verifier=None):
        backend = Backend(self.request)
        client = api.QualifiedJobAPI(
            self.request,
            self.request_hash,
            transport=backend.transport,
            verifier=verifier or self.verifier,
            guard=backend.guard,
            clock=lambda: 1000,
        )
        return client, backend

    def assert_denied_before_transport(self, verifier=None):
        client, backend = self.client(verifier)
        with self.assertRaises(ERRORS):
            client.begin(1, backend.listener)
        self.assertEqual(backend.calls(), [])
        self.assertEqual(backend.events, ["guard"])
        self.assertEqual(client.owned_runners, {})

    def test_verified_bound_receipt_spend_and_guard_order(self):
        client, backend = self.client()
        self.assertEqual(client.begin(1, backend.listener), (61, "fixture-process"))
        self.assertTrue(client.admitted)
        self.assertEqual(backend.events[:2], ["guard", "guard"])
        self.assertEqual(backend.events[-1], ("handoff", 61))
        self.assertEqual(
            (self.store / self.ppath).read_bytes(), b"1\n" + json_bytes(self.permit)
        )
        self.assertEqual(self.request, self.qualified["request"])
        # A fresh instance cannot verify or spend a previously spent binding.
        self.assert_denied_before_transport(self.verifier_for())

    def test_missing_qualification_blocks_every_transport(self):
        (self.store / self.qpath).unlink()
        with self.assertRaisesRegex(provider.Refused, "unavailable"):
            self.verifier.verify(self.request)
        self.assert_denied_before_transport()

    def test_receipt_replay_is_denied(self):
        receipt = self.verifier.verify(self.request)
        self.assertIs(type(receipt), api.VerifiedJobReceipt)
        self.assertEqual(receipt.request_sha256, self.request_hash)
        self.assertEqual((receipt.verified_at, receipt.expires_at), (1000, 1020))
        self.assertIs(self.verifier.claim_unused(receipt), True)
        with self.assertRaisesRegex(provider.Refused, "provider-issued"):
            self.verifier.claim_unused(receipt)

    def test_unissued_copied_changed_foreign_and_superseded_receipts(self):
        with self.assertRaisesRegex(provider.Refused, "provider-issued"):
            self.verifier.claim_unused(None)
        for kind in ("copy", "changed", "foreign", "superseded"):
            with self.subTest(kind=kind):
                verifier = self.verifier_for()
                receipt = verifier.verify(self.request)
                if kind == "copy":
                    receipt = api.VerifiedJobReceipt(*receipt)
                elif kind == "changed":
                    receipt = receipt._replace(admission_id="1" * 64)
                elif kind == "foreign":
                    receipt = self.verifier_for().verify(self.request)
                else:
                    verifier.verify(self.request)
                with self.assertRaisesRegex(provider.Refused, "provider-issued"):
                    verifier.claim_unused(receipt)
        self.assertTrue((self.store / self.ppath).read_bytes().startswith(b"0\n"))

    def test_whole_request_binding_is_independent_of_public_records(self):
        for key, value in [
            ("candidate_sha", "1" * 40),
            ("guest", {"id": "1" * 64, "boot_id": "2" * 36}),
            ("job_id", 18),
            ("run_id", 313),
            ("run_attempt", 3),
            ("pins", dict(self.request["pins"], runtime_image="sha256:" + "1" * 64)),
            ("runner_name", "vi-local-ci-forged"),
            ("repository", "foreign/repo"),
            ("mode", "fixture-proof"),
            ("qualification_id", "1" * 64),
            ("admission_id", "../permit"),
        ]:
            changed = dict(self.request, **{key: value})
            with self.subTest(key=key), self.assertRaises(provider.Refused):
                self.verifier.verify(changed)
        self.assertTrue((self.store / self.ppath).read_bytes().startswith(b"0\n"))

    def test_missing_each_input(self):
        for name in (
            self.epath,
            self.apath,
            self.ppath,
            "current.json",
            "source/repo_api.py",
        ):
            path = self.store / name
            saved = path.read_bytes()
            mode = path.stat().st_mode & 0o777
            path.unlink()
            with self.subTest(name=name):
                self.assert_denied_before_transport()
            self.write(name, saved, mode)

    def test_malformed_oversize_and_unknown_records(self):
        original = (self.store / self.qpath).read_bytes()
        for value in (
            b"not-json",
            b"[]",
            b'{"schema":1,"schema":2}',
            b"\xff",
            b" " * (provider.MAX_RECORD + 1),
            {},
            {"schema": "unknown"},
            dict(self.qualified, extra=True),
        ):
            self.write(self.qpath, value)
            with self.subTest(value_type=type(value).__name__):
                self.assert_denied_before_transport()
        self.write(self.qpath, original)
        # Oversize source reads are refused before allocating/reading it.
        self.write("source/repo_api.py", b"x" * (provider.MAX_SOURCE + 1))
        self.assert_denied_before_transport()

    def test_stale_future_boundary_and_bad_clock(self):
        for now in (989, 1020, 1290, -1, float("nan"), float("inf"), True):
            with (
                self.subTest(now=now),
                patch.object(provider.time, "time", return_value=now),
            ):
                self.assert_denied_before_transport()
        with patch.object(provider.time, "time", return_value=990):
            receipt = self.verifier.verify(self.request)
            self.assertEqual(receipt.verified_at, 990)
        receipt = self.verifier.verify(self.request)
        with (
            patch.object(provider.time, "time", return_value=1020),
            self.assertRaises(provider.Refused),
        ):
            self.verifier.claim_unused(receipt)

    def test_qualification_current_evidence_and_admission_fields(self):
        cases = [
            (self.qpath, dict(self.qualified, issued_at=True)),
            (self.qpath, dict(self.qualified, expires_at=1291)),
            (self.qpath, dict(self.qualified, schema="public-consistency-only")),
            (self.qpath, dict(self.qualified, sources={})),
            (
                self.qpath,
                dict(
                    self.qualified,
                    sources=dict(self.sources, **{"../escape": "a" * 64}),
                ),
            ),
            (
                self.qpath,
                dict(
                    self.qualified,
                    sources=dict(self.sources, **{"repo_api.py": "a" * 64}),
                ),
            ),
            (self.epath, dict(self.evidence, checks=[])),
            ("current.json", dict(self.current, request_sha256="1" * 64)),
            ("current.json", dict(self.current, observed_at=1001)),
            ("current.json", dict(self.current, expires_at=1021)),
            ("current.json", dict(self.current, observed_at=True)),
            (self.apath, dict(self.admission, qualification_sha256="1" * 64)),
            (self.apath, dict(self.admission, expires_at=1291)),
        ]
        for name, record in cases:
            saved = (self.store / name).read_bytes()
            self.write(name, record)
            with self.subTest(name=name, record=record["schema"]):
                self.assert_denied_before_transport()
            self.write(name, saved)

    def test_source_pins_really_hashed_and_not_merely_attested(self):
        self.write("source/repo_api.py", b"changed immutable source")
        self.assert_denied_before_transport()
        # Attesting a changed pin without a corresponding source must also fail.
        self.request["pins"]["code_manifest_sha256"] = "1" * 64
        self.request["runner_name"] = producer.request_model.runner_name(self.request)
        self.qualified["request"] = self.request
        self.write(self.qpath, self.qualified)
        with self.assertRaises(provider.Refused):
            self.verifier.verify(self.request)

    def test_attested_but_inconsistent_code_manifest_is_independently_refused(self):
        manifest = json.loads((self.store / "source/code-inputs.json").read_bytes())
        for kind in ("wrong-hash", "missing-provider"):
            changed = json.loads(json_bytes(manifest))
            if kind == "wrong-hash":
                changed["repo_api.py"]["sha256"] = "1" * 64
                reason = "code manifest closure changed"
            else:
                del changed["trusted_provider.py"]
                reason = "code manifest missing trusted verifier inputs"
            raw = json_bytes(changed)
            self.write("source/code-inputs.json", raw)
            self.sources["code-inputs.json"] = sha(raw)
            self.request["pins"]["code_manifest_sha256"] = sha(raw)
            self.request["runner_name"] = producer.request_model.runner_name(
                self.request
            )
            self.write(self.qpath, self.qualified)
            with (
                self.subTest(kind=kind),
                self.assertRaisesRegex(provider.Refused, reason),
            ):
                self.verifier.verify(self.request)

    def test_symlink_leaf_directory_root_and_ancestor(self):
        for name in (self.qpath, self.ppath, "source/repo_api.py"):
            path = self.store / name
            target = self.store / "fixture-save"
            path.rename(target)
            path.symlink_to(target)
            with self.subTest(name=name):
                self.assert_denied_before_transport()
            path.unlink()
            target.rename(path)
        folder = self.store / "qualifications"
        folder.rename(self.store / "saved")
        folder.symlink_to(self.store / "saved", target_is_directory=True)
        self.assert_denied_before_transport()
        folder.unlink()
        (self.store / "saved").rename(folder)
        link = self.store / "alias"
        link.symlink_to(self.store, target_is_directory=True)
        for path in (link, link / "source"):
            with self.subTest(path=path), self.assertRaises(provider.Refused):
                self.verifier_for(path).verify(self.request)

    def test_unsafe_modes_owners_hardlinks_and_nonregular_files(self):
        for name, mode in (
            (self.qpath, 0o600),
            (self.ppath, 0o640),
            ("source", 0o777),
            ("evidence", 0o775),
            ("admissions", 0o777),
            (".", 0o777),
        ):
            path = self.store / name
            previous = path.stat().st_mode & 0o777
            path.chmod(mode)
            with self.subTest(name=name):
                self.assert_denied_before_transport()
            path.chmod(previous)
        for name in (self.qpath, self.ppath):
            path = self.store / name
            hardlink = self.store / "second-link"
            os.link(path, hardlink)
            self.assert_denied_before_transport()
            hardlink.unlink()
        with patch.object(provider, "TRUSTED_UID", os.getuid() + 1):
            self.assert_denied_before_transport()
        path = self.store / self.ppath
        path.unlink()
        os.mkfifo(path, 0o600)
        self.assert_denied_before_transport()

    def test_swap_identical_files_and_metadata_between_verify_and_claim(self):
        for name in (
            self.qpath,
            self.epath,
            self.apath,
            self.ppath,
            "current.json",
            "source/repo_api.py",
        ):
            receipt = self.verifier.verify(self.request)
            path = self.store / name
            mode = path.stat().st_mode & 0o777
            raw = path.read_bytes()
            replacement = self.store / "replacement"
            replacement.write_bytes(raw)
            replacement.chmod(mode)
            replacement.replace(path)
            with (
                self.subTest(name=name),
                self.assertRaisesRegex(provider.Refused, "snapshot changed"),
            ):
                self.verifier.claim_unused(receipt)
        receipt = self.verifier.verify(self.request)
        (self.store / "source").chmod(0o500)
        with self.assertRaisesRegex(provider.Refused, "snapshot changed"):
            self.verifier.claim_unused(receipt)

    def test_wrong_spent_partial_and_unknown_permit_bytes(self):
        original = (self.store / self.ppath).read_bytes()
        for raw in (
            b"1" + original[1:],
            b"",
            b"0",
            b"?",
            original + b"\n",
            original.replace(self.request_hash.encode(), b"a" * 64),
            b"0\n" + json_bytes(dict(self.permit, expires_at=1021)),
        ):
            self.write(self.ppath, raw, 0o600)
            with self.subTest(size=len(raw)):
                self.assert_denied_before_transport()

    def test_lock_contention_fails_closed_without_waiting(self):
        receipt = self.verifier.verify(self.request)
        with (self.store / self.ppath).open("r+b") as held:
            fcntl.flock(held, fcntl.LOCK_EX | fcntl.LOCK_NB)
            with self.assertRaisesRegex(provider.Refused, "unavailable"):
                self.verifier.claim_unused(receipt)
        self.assertTrue((self.store / self.ppath).read_bytes().startswith(b"0\n"))

    def test_fsync_error_is_spent_no_transport_and_no_rollback(self):
        client, backend = self.client()
        with (
            patch.object(
                provider.os, "fsync", side_effect=OSError("fixture disk failure")
            ),
            self.assertRaisesRegex(provider.Refused, "uncertain"),
        ):
            client.begin(1, backend.listener)
        self.assertEqual(backend.calls(), [])
        self.assertFalse(client.admitted)
        self.assertTrue((self.store / self.ppath).read_bytes().startswith(b"1\n"))
        self.assert_denied_before_transport(self.verifier_for())

    def test_expiry_during_fsync_keeps_spent_and_blocks_transport(self):
        client, backend = self.client()
        real_fsync = provider.os.fsync

        def delayed_fsync(fd):
            real_fsync(fd)
            provider.time.time.return_value = 1020

        with (
            patch.object(provider.os, "fsync", side_effect=delayed_fsync),
            self.assertRaisesRegex(provider.Refused, "uncertain"),
        ):
            client.begin(1, backend.listener)
        self.assertEqual(backend.calls(), [])
        self.assertFalse(client.admitted)
        self.assertTrue((self.store / self.ppath).read_bytes().startswith(b"1\n"))

    def test_partial_write_and_exception_never_succeed(self):
        receipt = self.verifier.verify(self.request)
        with (
            patch.object(provider.os, "pwrite", return_value=0),
            self.assertRaisesRegex(provider.Refused, "uncertain"),
        ):
            self.verifier.claim_unused(receipt)
        receipt = self.verifier.verify(self.request)
        with (
            patch.object(
                provider.os, "pwrite", side_effect=OSError("fixture write error")
            ),
            self.assertRaisesRegex(provider.Refused, "uncertain"),
        ):
            self.verifier.claim_unused(receipt)

    def test_disappearing_admission_parent_or_leaf_after_verification(self):
        receipt = self.verifier.verify(self.request)
        path = self.store / self.ppath
        raw = path.read_bytes()
        path.unlink()
        with self.assertRaisesRegex(provider.Refused, "unavailable"):
            self.verifier.claim_unused(receipt)
        self.write(self.ppath, raw, 0o600)
        receipt = self.verifier.verify(self.request)
        parent = self.store / "admissions"
        parent.rename(self.store / "disappeared")
        with self.assertRaisesRegex(provider.Refused, "unavailable"):
            self.verifier.claim_unused(receipt)

    def test_process_crash_after_durable_spend_is_not_replayable(self):
        context = multiprocessing.get_context("fork")
        process = context.Process(
            target=crash_after_durable_spend, args=(str(self.store), self.request)
        )
        process.start()
        process.join(timeout=15)
        self.assertFalse(process.is_alive())
        self.assertEqual(process.exitcode, 73)
        self.assertTrue((self.store / self.ppath).read_bytes().startswith(b"1\n"))
        self.assert_denied_before_transport(self.verifier_for())

    def test_path_replaced_during_spend_never_authorizes_transport(self):
        client, backend = self.client()
        real_write = provider.os.pwrite

        def replace_path(fd, data, offset):
            result = real_write(fd, data, offset)
            replacement = self.store / "replacement"
            replacement.write_bytes(b"unknown")
            replacement.chmod(0o600)
            replacement.replace(self.store / self.ppath)
            return result

        with (
            patch.object(provider.os, "pwrite", side_effect=replace_path),
            self.assertRaisesRegex(provider.Refused, "uncertain"),
        ):
            client.begin(1, backend.listener)
        self.assertEqual(backend.calls(), [])
        self.assert_denied_before_transport(self.verifier_for())

    def test_short_or_changed_read_and_postspend_readback_are_denied(self):
        with (
            patch.object(provider.os, "read", return_value=b""),
            self.assertRaisesRegex(provider.Refused, "changed"),
        ):
            self.verifier.verify(self.request)
        receipt = self.verifier.verify(self.request)
        real_pread = provider.os.pread
        calls = []

        def bad_readback(fd, count, offset):
            calls.append(fd)
            return real_pread(fd, count, offset) if len(calls) == 1 else b"unknown"

        with (
            patch.object(provider.os, "pread", side_effect=bad_readback),
            self.assertRaisesRegex(provider.Refused, "uncertain"),
        ):
            self.verifier.claim_unused(receipt)
        self.assertTrue((self.store / self.ppath).read_bytes().startswith(b"1\n"))

    def test_failed_verify_invalidates_previous_receipt(self):
        receipt = self.verifier.verify(self.request)
        with self.assertRaises(provider.Refused):
            self.verifier.verify(None)
        with self.assertRaisesRegex(provider.Refused, "provider-issued"):
            self.verifier.claim_unused(receipt)

    def test_two_processes_verified_before_atomic_claim_have_exactly_one_winner(self):
        context = multiprocessing.get_context("fork")
        barrier = context.Barrier(2)
        results = context.Queue()
        processes = [
            context.Process(
                target=concurrent_claim,
                args=(str(self.store), self.request, barrier, results),
            )
            for _ in range(2)
        ]
        for process in processes:
            process.start()
        for process in processes:
            process.join(timeout=15)
            self.assertFalse(
                process.is_alive(), "owned fixture claimant failed to terminate"
            )
            self.assertEqual(process.exitcode, 0)
        self.assertEqual(
            sorted(results.get(timeout=2) for _ in processes), [False, True]
        )
        results.close()
        results.join_thread()
        self.assertTrue((self.store / self.ppath).read_bytes().startswith(b"1\n"))


if __name__ == "__main__":
    unittest.main()
