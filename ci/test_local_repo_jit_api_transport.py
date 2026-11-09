"""Offline transcript and protocol controls for the constrained HTTPS adapter."""

import contextlib
import copy
import http.client
import importlib.util
import io
import json
import os
import resource
import socket
import ssl
import time
import traceback
import unittest
from pathlib import Path
from unittest.mock import Mock, patch

from ci.test_local_repo_jit_api_binding import Backend, api
from ci.test_local_repo_jit_request import producer, records, request_fixture

ROOT = Path(__file__).resolve().parents[1] / ".github/scripts/local-repo-jit"
SPEC = importlib.util.spec_from_file_location("constrained_api_transport", ROOT / "api_transport.py")
if SPEC is None or SPEC.loader is None:
    raise RuntimeError("fixture module unavailable")
transport = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(transport)
TOKEN = "fixture-explicit-token"
AMBIENT = "fixture-ambient-token"
REAL_HTTPS_CONNECTION = http.client.HTTPSConnection


class Response:
    def __init__(self, status=200, obj=None, *, raw=None, headers=None):
        self.status = status
        self.raw = json.dumps(obj if obj is not None else {}).encode() if raw is None else raw
        self.stream = io.BytesIO(self.raw)
        self.headers = {"Content-Type": "application/json", "Content-Length": str(len(self.raw))}
        self.headers.update(headers or {})
        self.reads = []

    def getheader(self, name, default=None):
        return self.headers.get(name, default)

    def read1(self, limit):
        self.reads.append(limit)
        return self.stream.read(limit)


class TransportTests(unittest.TestCase):
    def setUp(self):
        # Protocol transcripts inspect mutable fake objects in this process.
        # EnvelopeTests separately exercises the public call with real children.
        direct = patch.object(transport.APITransport, "_exchange_process", autospec=True,
                              side_effect=lambda client, *args: client._exchange(*args))
        direct.start()
        self.addCleanup(direct.stop)
        self.binding = request_fixture()
        self.backend = Backend(self.binding)
        self.responses = []
        self.connections = []
        self.calls = []
        self.failure = None
        self.close_failure = False
        self.no_socket = False
        self.redirect = None
        self.env = patch.dict(os.environ, {"GITHUB_TOKEN": AMBIENT, "GH_TOKEN": AMBIENT,
                                          "GITHUB_CLASSIC_TOKEN": AMBIENT,
                                          "HTTPS_PROXY": "http://fixture.invalid/", "ALL_PROXY": "http://fixture.invalid/",
                                          "SSL_CERT_FILE": "/nonexistent-fixture", "SSL_CERT_DIR": "/nonexistent-fixture"})
        self.env.start()
        self.addCleanup(self.env.stop)
        # Even accidental fixture fall-through cannot perform a network request.
        self.net = patch.object(socket.socket, "connect", side_effect=AssertionError("real network forbidden"))
        self.net.start()
        self.addCleanup(self.net.stop)
        self.factory = patch.object(transport.http.client, "HTTPSConnection", side_effect=self.connection)
        self.factory.start()
        self.addCleanup(self.factory.stop)
        self.process = patch("subprocess.run", side_effect=AssertionError("subprocess forbidden"))
        self.process.start()
        self.addCleanup(self.process.stop)
        self.client = transport.APITransport(TOKEN, self.binding, runner_group_id=1)

    def connection(self, *args, **kwargs):
        outer = self

        class Connection:
            def __init__(self):
                self.sock = None if outer.no_socket else Mock()
                self.response = None
                self.closed = False

            def set_debuglevel(self, level):
                outer.assertEqual(level, 0)

            def request(self, method, path, body, headers):
                outer.calls.append((method, path, body, headers))
                if outer.failure is not None:
                    raise outer.failure
                relative = path.removeprefix(transport.PREFIX)
                payload = json.loads(body) if body is not None else None
                if outer.responses:
                    self.response = outer.responses.pop(0)
                else:
                    status, result = outer.backend.transport(method, relative, payload)
                    self.response = Response(status, result)

            def getresponse(self):
                return self.response

            def close(self):
                self.closed = True
                if outer.close_failure:
                    raise OSError(TOKEN + AMBIENT)

        connection = Connection()
        outer.connections.append((args, kwargs, connection))
        return connection

    def body(self):
        return {"name": self.binding["runner_name"], "runner_group_id": 1,
                "labels": api.scoped_job_labels(self.binding["run_id"], self.binding["run_attempt"]), "work_folder": "_work"}

    def mint(self):
        return self.client("POST", "actions/runners/generate-jitconfig", self.body())

    def own_idle(self):
        self.mint()
        return self.client("GET", "actions/runners/61")

    def refused(self, method="GET", path="actions/runs/312", body=None):
        with self.assertRaises(transport.Refused) as caught:
            self.client(method, path, body)
        exc = caught.exception
        self.assertEqual(str(exc), "repository transport failed")
        self.assertIsNone(exc.__context__)
        self.assertIsNone(exc.__cause__)
        rendered = "".join(traceback.format_exception(exc))
        self.assertNotIn(TOKEN, rendered)
        self.assertNotIn(AMBIENT, rendered)
        return exc

    def test_bound_q3_transcript_admission_order_identity_and_owned_cleanup(self):
        qualification, admission = records(self.binding)
        client = producer.QualifiedJobAPI(self.binding, qualification=qualification, admission=admission,
                                         transport=self.client, verifier=self.backend,
                                         guard=self.backend.guard, clock=lambda: 1001)
        self.assertEqual(client.begin(1, self.backend.listener), (61, "fixture-process"))
        self.assertEqual(self.backend.events[:4], ["guard", "verify", "claim-unused", "guard"])
        self.assertEqual(self.backend.events[-1], ("handoff", 61))
        client.delete_idle(61)
        self.assertEqual(client.owned_runners, {})
        self.assertEqual([(call[0], call[1]) for call in self.calls], [
            ("GET", transport.PREFIX + "actions/runs/312"),
            ("GET", transport.PREFIX + "actions/jobs/17"),
            ("POST", transport.PREFIX + "actions/runners/generate-jitconfig"),
            ("GET", transport.PREFIX + "actions/runners/61"),
            ("GET", transport.PREFIX + "actions/runners/61"),
            ("DELETE", transport.PREFIX + "actions/runners/61")])
        for args, kwargs, connection in self.connections:
            self.assertEqual(args, ("api.github.com",))
            self.assertEqual(kwargs["port"], 443)
            self.assertEqual(kwargs["timeout"], 10)
            self.assertTrue(kwargs["context"].check_hostname)
            self.assertEqual(kwargs["context"].verify_mode, ssl.CERT_REQUIRED)
            self.assertGreaterEqual(kwargs["context"].minimum_version, ssl.TLSVersion.TLSv1_2)
            self.assertTrue(connection.closed)
        for _method, path, data, headers in self.calls:
            self.assertEqual(headers["Authorization"], "Bearer " + TOKEN)
            self.assertEqual(headers["Accept-Encoding"], "identity")
            self.assertNotIn(AMBIENT, repr(headers))
            for value in (path, data):
                self.assertNotIn(TOKEN, str(value))
                self.assertNotIn(AMBIENT, str(value))

    def test_tls_context_uses_system_trust_and_fails_closed_on_load_error(self):
        paths = ssl.get_default_verify_paths()
        loads = []
        real_load = ssl.SSLContext.load_verify_locations

        def load(context, *args, **kwargs):
            loads.append((args, kwargs))
            return real_load(context, *args, **kwargs)

        with (patch.object(ssl.SSLContext, "load_verify_locations", new=load),
              patch.dict(os.environ, {"SSLKEYLOGFILE": "/nonexistent-fixture/keylog"})):
            client = transport.APITransport(TOKEN, self.binding, runner_group_id=1)
        self.assertEqual(loads, [((), {"cafile": paths.openssl_cafile, "capath": paths.openssl_capath})])
        self.assertTrue(client._context.check_hostname)
        self.assertEqual(client._context.verify_mode, ssl.CERT_REQUIRED)
        self.assertEqual(client._context.minimum_version, ssl.TLSVersion.TLSv1_2)
        self.assertIsNone(client._context.keylog_filename)
        self.assertGreater(client._context.cert_store_stats()["x509_ca"], 0)
        with (patch.object(ssl.SSLContext, "load_verify_locations", side_effect=OSError("fixture trust unavailable")),
              self.assertRaisesRegex(OSError, "fixture trust unavailable")):
            transport.APITransport(TOKEN, self.binding, runner_group_id=1)
        self.assertEqual(self.calls, [])

    def test_real_https_connect_passes_fixed_hostname_and_verified_context(self):
        raw = Mock()
        wrapped = []

        def reject_certificate(context, sock, *, server_hostname):
            wrapped.append((context, sock, server_hostname))
            raise ssl.SSLCertVerificationError("fixture certificate rejected")

        with (patch.object(transport.http.client, "HTTPSConnection", REAL_HTTPS_CONNECTION),
              patch.object(socket, "create_connection", return_value=raw) as connect,
              patch.object(ssl.SSLContext, "wrap_socket", new=reject_certificate)):
            self.refused()
        connect.assert_called_once_with(("api.github.com", 443), transport.IO_TIMEOUT, None)
        self.assertEqual(wrapped, [(self.client._context, raw, "api.github.com")])
        self.assertEqual(self.calls, [])

    def test_actual_http_encoder_parser_with_fake_tls_socket_and_debug_disabled(self):
        class WireSocket:
            def __init__(self):
                self.sent = []
                self.closed = False

            def sendall(self, data):
                self.sent.append(data)

            def makefile(self, *_args):
                return io.BytesIO(b"HTTP/1.1 200 OK\r\nContent-Type: application/json\r\n"
                                  b"Content-Length: 2\r\nConnection: close\r\n\r\n{}")

            def close(self):
                self.closed = True

        wire = WireSocket()
        with patch.object(transport.http.client, "HTTPSConnection", REAL_HTTPS_CONNECTION):
            connection = REAL_HTTPS_CONNECTION(transport.HOST, context=ssl.SSLContext(ssl.PROTOCOL_TLS_CLIENT))
        connection.sock = wire
        connection.debuglevel = 1
        stdout, stderr = io.StringIO(), io.StringIO()
        with (patch.object(transport.http.client, "HTTPSConnection", return_value=connection),
              contextlib.redirect_stdout(stdout), contextlib.redirect_stderr(stderr)):
            self.assertEqual(self.client("GET", "actions/runs/312"), (200, {}))
        self.assertEqual(stdout.getvalue() + stderr.getvalue(), "")
        raw = b"".join(wire.sent).decode("ascii")
        self.assertTrue(raw.startswith("GET " + transport.PREFIX + "actions/runs/312 HTTP/1.1\r\n"))
        self.assertIn("Host: api.github.com\r\n", raw)
        self.assertIn("Authorization: Bearer " + TOKEN + "\r\n", raw)
        self.assertNotIn("Proxy-Authorization", raw)
        self.assertNotIn(AMBIENT, raw)
        self.assertTrue(wire.closed)

    def test_missing_invalid_explicit_credentials_and_public_binding(self):
        for token in (None, "", True, b"token", "a\nb", "a\rb", "a b", "é", "x" * 4097):
            with self.subTest(token_type=type(token).__name__), self.assertRaisesRegex(transport.Refused, "refused"):
                transport.APITransport(token, self.binding, runner_group_id=1)
        changes = [("repository", "foreign/repository"), ("mode", "proof"), ("run_id", True),
                   ("run_id", 0), ("run_id", transport.MAX_ID + 1), ("job_id", "17"),
                   ("run_attempt", -1), ("runner_name", "vi-local-ci-foreign"), ("runner_name", None)]
        for key, value in changes:
            binding = dict(self.binding, **{key: value})
            with self.subTest(field=key, value=value), self.assertRaises(transport.Refused):
                transport.APITransport(TOKEN, binding, runner_group_id=1)
        for group in (False, 0, -1, "1", transport.MAX_ID + 1):
            with self.assertRaises(transport.Refused):
                transport.APITransport(TOKEN, self.binding, runner_group_id=group)
        with self.assertRaises(transport.Refused):
            transport.APITransport(TOKEN, [], runner_group_id=1)
        self.assertEqual(self.calls, [])
        self.assertNotIn(TOKEN, repr(self.client))

    def test_id_boundaries_and_binding_copy(self):
        binding = copy.deepcopy(self.binding)
        binding.update(run_id=transport.MAX_ID, job_id=transport.MAX_ID, run_attempt=transport.MAX_ID)
        client = transport.APITransport("a", binding, runner_group_id=transport.MAX_ID)
        binding["run_id"] = 1
        self.responses = [Response(200, {})]
        self.assertEqual(client("GET", "actions/runs/" + str(transport.MAX_ID)), (200, {}))
        self.assertEqual(self.calls[-1][1], transport.PREFIX + "actions/runs/" + str(transport.MAX_ID))

    def test_exact_path_query_host_method_and_no_foreign_actions(self):
        paths = ["https://api.github.com/repos/myguard-labs/ViMbAdmin/actions/runs/312",
                 "https://foreign.invalid/", "//foreign.invalid/actions/runs/312", "/actions/runs/312",
                 "actions/runs/313", "actions/runs/0312", "actions/runs/+312", "actions/runs/312/",
                 "actions/runs/312?per_page=1", "actions/runs/312#x", "actions/runs/%33%31%32",
                 "actions/runs/../runs/312", "actions/runs/312\r\nHost: foreign.invalid",
                 "actions/jobs/18", "actions/runners", "actions/runners?per_page=1",
                 "actions/runners/61", "actions/runs/312/cancel", "git/ref/heads/master",
                 "actions/workflows/static-analysis.yml/dispatches", "actions/runs/312/jobs"]
        for path in paths:
            with self.subTest(path=path):
                self.refused(path=path)
        for method in ("HEAD", "PUT", "PATCH", "OPTIONS", "get", None, [], True):
            self.refused(method=method)
        self.refused(path=None)
        self.refused(body={})
        self.refused("DELETE", "actions/runs/312")
        self.refused("POST", "actions/runs/312/cancel", {})
        self.assertEqual(self.calls, [])

    def test_post_schema_exact_group_name_labels_and_work_folder(self):
        base = self.body()
        cases = [None, [], dict(base, extra=1), {k: v for k, v in base.items() if k != "name"}]
        changes = [("name", "foreign"), ("runner_group_id", True), ("runner_group_id", 2),
                   ("labels", tuple(base["labels"])), ("labels", base["labels"][:-1]),
                   ("labels", [True] + base["labels"][1:]),
                   ("labels", [base["labels"][0]] * 5), ("work_folder", "/absolute-work"), ("work_folder", None)]
        cases += [dict(base, **{key: value}) for key, value in changes]
        for body in cases:
            with self.subTest(body=body):
                self.refused("POST", "actions/runners/generate-jitconfig", body)
        self.assertEqual(self.calls, [])
        reversed_body = self.body()
        reversed_body["labels"].reverse()
        self.assertEqual(self.client("POST", "actions/runners/generate-jitconfig", reversed_body)[0], 201)
        count = len(self.calls)
        self.refused("POST", "actions/runners/generate-jitconfig", base)
        self.assertEqual(len(self.calls), count)

    def test_redirects_status_errors_ignore_headers_and_unread_bodies(self):
        for status in (300, 301, 302, 303, 304, 307, 308, 199, 202, True, "200"):
            response = Response(status, obj={"secret": TOKEN + AMBIENT}, headers={"Location": "https://foreign.invalid/" + TOKEN})
            self.responses = [response]
            self.refused()
            self.assertEqual(response.reads, [])
        for status in (400, 401, 403, 404, 429, 500, 502, 599):
            response = Response(status, raw=(TOKEN + AMBIENT).encode() * 100000)
            self.responses = [response]
            self.assertEqual(self.client("GET", "actions/runs/312"), (status, {}))
            self.assertEqual(response.reads, [])
        self.assertTrue(all(connection.closed for _args, _kw, connection in self.connections))

    def test_malformed_duplicate_depth_unicode_and_json_types_are_redacted(self):
        cases = [b"", b"\xff", b"{", b"[]", b"true", b"null", b"1", b'"text"',
                 b'{"x":1,"x":2}', b'{"nested":{"x":1,"x":2}}', b'{"x":NaN}',
                 b'{"x":Infinity}', b'{"x":-Infinity}', b'{"x":1e309}', b'{"x":' + b"[" * 33 + b"]" * 33 + b"}",
                 b'{"secret":"' + TOKEN.encode() + b'"', b'{} trailing']
        for raw in cases:
            self.responses = [Response(raw=raw)]
            self.refused()
        obj = {"text": 'escaped " \\ [ { } ]', "nested": [False, None, 1, 1.5]}
        self.responses = [Response(obj=obj)]
        self.assertEqual(self.client("GET", "actions/runs/312"), (200, obj))
        obj = {"x": [[]]}
        self.responses = [Response(obj=obj, headers={"Content-Length": None, "Transfer-Encoding": "chunked"})]
        self.assertEqual(self.client("GET", "actions/jobs/17"), (200, obj))

    def test_size_length_encoding_and_content_type_boundaries(self):
        exact = b'{"x":"' + b"a" * (transport.MAX_BODY - 8) + b'"}'
        self.assertEqual(len(exact), transport.MAX_BODY)
        self.responses = [Response(raw=exact)]
        self.assertEqual(len(self.client("GET", "actions/runs/312")[1]["x"]), transport.MAX_BODY - 8)
        for raw, headers in [(exact + b" ", {}), (exact + b" ", {"Content-Length": None}),
                             (b"{}", {"Content-Length": "-1"}), (b"{}", {"Content-Length": "3"}),
                             (b"{}", {"Content-Length": "99999999999"}), (b"{}", {"Content-Length": "2,2"}),
                             (b"{}", {"Content-Encoding": "gzip"}), (b"{}", {"Content-Type": "text/html"}),
                             (b"{}", {"Content-Type": ""}), (b"{}", {"Transfer-Encoding": "chunked"}),
                             (b"{}", {"Transfer-Encoding": "gzip", "Content-Length": None})]:
            self.responses = [Response(raw=raw, headers=headers)]
            self.refused()
        self.responses = [Response(headers={"Content-Type": "Application/JSON; charset=utf-8", "Content-Encoding": "IDENTITY"})]
        self.assertEqual(self.client("GET", "actions/runs/312"), (200, {}))
        self.no_socket = True
        self.responses = [Response(headers={"Content-Type": "application/vnd.github+json"})]
        self.assertEqual(self.client("GET", "actions/runs/312"), (200, {}))

    def test_timeouts_exception_redaction_close_and_no_mutation_retries(self):
        for error in (OSError(TOKEN + AMBIENT), TimeoutError(TOKEN), ssl.SSLError(AMBIENT),
                      http.client.HTTPException(TOKEN), ValueError(AMBIENT)):
            self.failure = error
            self.close_failure = True
            self.refused()
            self.assertTrue(self.connections[-1][2].closed)
        self.failure = None
        self.close_failure = False
        self.responses = [Response()]
        with patch.object(transport.time, "monotonic", side_effect=[1, 32]):
            self.refused()
        self.failure = OSError(TOKEN)
        previous = len(self.calls)
        self.refused("POST", "actions/runners/generate-jitconfig", self.body())
        self.refused("POST", "actions/runners/generate-jitconfig", self.body())
        self.assertEqual(len(self.calls), previous + 1)
        self.failure = None
        self.client = transport.APITransport(TOKEN, self.binding, runner_group_id=1)
        self.own_idle()
        self.failure = TimeoutError(TOKEN)
        previous = len(self.calls)
        self.refused("DELETE", "actions/runners/61")
        self.refused("DELETE", "actions/runners/61")
        self.assertEqual(len(self.calls), previous + 1)

    def test_cleanup_requires_exact_post_and_fresh_idle_get(self):
        self.mint()
        self.refused("DELETE", "actions/runners/61")
        self.refused("GET", "actions/runners/62")
        self.refused("DELETE", "actions/runners/62")
        for busy in (True, None, 0, "false"):
            self.backend.runner["busy"] = busy
            self.client("GET", "actions/runners/61")
            self.refused("DELETE", "actions/runners/61")
        self.backend.runner["busy"] = False
        self.client("GET", "actions/runners/61")
        self.responses = [Response(404)]
        self.assertEqual(self.client("GET", "actions/runners/61"), (404, {}))
        self.refused("DELETE", "actions/runners/61")
        self.client("GET", "actions/runners/61")
        self.assertEqual(self.client("DELETE", "actions/runners/61"), (204, {}))
        self.refused("GET", "actions/runners/61")

    def test_foreign_runner_post_get_identity_and_label_refusals(self):
        for key, value in [("id", True), ("id", 0), ("id", transport.MAX_ID + 1), ("name", "foreign"),
                           ("labels", None), ("labels", []), ("labels", [None] * 5),
                           ("labels", [{"name": True}] * 5), ("labels", [{"name": "foreign"}] * 5)]:
            with self.subTest(key=key, value=value):
                self.client = transport.APITransport(TOKEN, self.binding, runner_group_id=1)
                obj = copy.deepcopy(self.backend.post)
                obj["runner"][key] = value
                self.responses = [Response(201, obj)]
                self.refused("POST", "actions/runners/generate-jitconfig", self.body())
                self.refused("DELETE", "actions/runners/61")
        self.client = transport.APITransport(TOKEN, self.binding, runner_group_id=1)
        self.responses = [Response(201, {"runner": []})]
        self.refused("POST", "actions/runners/generate-jitconfig", self.body())
        self.client = transport.APITransport(TOKEN, self.binding, runner_group_id=1)
        self.own_idle()
        obj = copy.deepcopy(self.backend.runner)
        obj["id"] = 62
        self.responses = [Response(200, obj)]
        self.refused(path="actions/runners/61")
        self.refused("DELETE", "actions/runners/61")

    def test_q3_guards_foreign_run_job_and_proof_production_deny_still_apply(self):
        qualification, admission = records(self.binding)
        for target in ("run", "job"):
            self.client = transport.APITransport(TOKEN, self.binding, runner_group_id=1)
            backend = Backend(self.binding)
            getattr(backend, target)["head_sha"] = "0" * 40
            self.backend = backend
            client = producer.QualifiedJobAPI(self.binding, qualification=qualification, admission=admission,
                                             transport=self.client, verifier=backend,
                                             guard=backend.guard, clock=lambda: 1001)
            with self.assertRaisesRegex(api.Refused, "identity changed"):
                client.begin(1, backend.listener)
            self.assertFalse(any(call[0] == "POST" for call in self.calls))
        proof = api.RepoAPI(transport=self.client, guard=lambda: None)
        with self.assertRaisesRegex(api.Refused, "production capability"):
            proof.mint(self.binding["runner_name"], 1, api.scoped_job_labels(312, 2))


class EnvelopeTests(unittest.TestCase):
    """Real Linux children; no live network, token, API, or backend."""

    def setUp(self):
        self.binding = request_fixture()
        self.client = transport.APITransport(TOKEN, self.binding, runner_group_id=1)
        self.deadline = patch.object(transport, "CALL_TIMEOUT", 0.25)
        self.deadline.start()
        self.addCleanup(self.deadline.stop)
        self.network = patch.object(socket.socket, "connect", side_effect=AssertionError("network forbidden"))
        self.network.start()
        self.addCleanup(self.network.stop)
        self.children = []
        self.real_fork = os.fork
        fork = patch.object(transport.os, "fork", side_effect=self.fork)
        fork.start()
        self.addCleanup(fork.stop)
        self.fd_count = len(os.listdir("/proc/self/fd"))
        self.addCleanup(self.assert_reaped)
        self.events_read, self.events_write = os.pipe()
        self.addCleanup(os.close, self.events_write)
        self.addCleanup(os.close, self.events_read)

    def fork(self):
        pid = self.real_fork()
        if pid:
            self.children.append(pid)
        return pid

    def assert_reaped(self):
        unreaped = []
        for pid in self.children:
            try:
                observed, _ = os.waitpid(pid, os.WNOHANG)
            except ChildProcessError:
                continue
            unreaped.append(pid)
            if observed == 0:
                # A failed cleanup mutation must not leave its fixture behind.
                try:
                    os.killpg(pid, transport.signal.SIGKILL)
                except ProcessLookupError:
                    pass
                os.kill(pid, transport.signal.SIGKILL)
                os.waitpid(pid, 0)
        self.assertEqual(unreaped, [], "owned child was not reaped by transport")
        self.assertEqual(len(os.listdir("/proc/self/fd")), self.fd_count)
        for pid in self.children:
            with self.assertRaises(ProcessLookupError):
                os.kill(pid, 0)

    def mark(self, phase):
        os.write(self.events_write, phase.encode("ascii") + b"\n")

    def observed(self):
        os.set_blocking(self.events_read, False)
        try:
            return os.read(self.events_read, 65536).decode("ascii")
        except BlockingIOError:
            return ""

    def refused(self, method="GET", path="actions/runs/312", body=None):
        started = time.monotonic()
        with self.assertRaisesRegex(transport.Refused, "^repository transport failed$") as caught:
            self.client(method, path, body)
        self.assertLess(time.monotonic() - started, 1.25, "whole-call deadline was not enforced")
        self.assertIsNone(caught.exception.__context__)
        self.assertIsNone(caught.exception.__cause__)
        rendered = "".join(traceback.format_exception(caught.exception))
        self.assertNotIn(TOKEN, rendered)
        self.assertNotIn(AMBIENT, rendered)

    def body(self):
        return {"name": self.binding["runner_name"], "runner_group_id": 1,
                "labels": api.scoped_job_labels(312, 2), "work_folder": "_work"}

    def wire(self, *, phase=None, raw=None):
        outer = self
        class Stream(io.BytesIO):
            def readline(self, *args):
                if phase == "headers":
                    outer.mark("headers")
                    time.sleep(0.07)  # Each line is well below the socket timeout.
                return super().readline(*args)

        class Wire:
            def sendall(self, _data):
                outer.mark("send")
                if phase == "send":
                    time.sleep(2)

            def makefile(self, *_args):
                return Stream(raw if raw is not None else
                              b"HTTP/1.1 200 OK\r\nContent-Type: application/json\r\n"
                              b"X-A: 1\r\nX-B: 2\r\nContent-Length: 2\r\n\r\n{}")

            def close(self):
                outer.mark("close")
                if phase == "close":
                    time.sleep(2)

            def settimeout(self, _timeout):
                pass

        with patch.object(transport.http.client, "HTTPSConnection", REAL_HTTPS_CONNECTION):
            connection = REAL_HTTPS_CONNECTION(transport.HOST, context=ssl.SSLContext(ssl.PROTOCOL_TLS_CLIENT))
        connection.sock = Wire()
        return patch.object(transport.http.client, "HTTPSConnection", return_value=connection)

    def test_public_dns_stall_is_killed_and_reaped(self):
        def dns(*_args, **_kwargs):
            self.mark("dns")
            time.sleep(2)
            raise OSError(TOKEN)
        with patch.object(socket, "getaddrinfo", side_effect=dns):
            self.refused()
        self.assertEqual(self.observed(), "dns\n")

    def test_public_slow_headers_send_body_parse_and_close_share_deadline(self):
        for phase in ("headers", "send", "close"):
            with self.subTest(phase=phase), self.wire(phase=phase):
                self.refused()
                self.assertIn(phase, self.observed())
        for phase, name in (("body", "_read"), ("parse", "decode_object")):
            def stall(*_args, phase=phase):
                self.mark(phase)
                time.sleep(2)
                return {}
            target = transport.APITransport if name == "_read" else transport
            with self.subTest(phase=phase), self.wire(), patch.object(target, name, side_effect=stall):
                self.refused()
                self.assertIn(phase, self.observed())

    def test_public_success_error_and_complete_protocol_cleanup(self):
        with self.wire():
            self.assertEqual(self.client("GET", "actions/runs/312"), (200, {}))
        self.assertIn("close", self.observed())
        with self.wire(raw=b"HTTP/1.1 429 Error\r\nContent-Length: 99999999\r\n\r\n"):
            self.assertEqual(self.client("GET", "actions/runs/312"), (429, {}))
        self.assertIn("close", self.observed())
        with self.wire(raw=b"HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: 1\r\n\r\n{"):
            self.refused()
        self.assertIn("close", self.observed())

    def test_parent_retains_mint_runner_and_fresh_idle_state_across_children(self):
        backend = Backend(self.binding)
        def exchange(method, path, data, _expected):
            return backend.transport(method, path, json.loads(data) if data is not None else None)
        with patch.object(self.client, "_exchange", side_effect=exchange):
            self.assertEqual(self.client("POST", "actions/runners/generate-jitconfig", self.body())[0], 201)
            self.assertEqual(self.client._owned_id, 61)
            self.refused("POST", "actions/runners/generate-jitconfig", self.body())
            self.refused("DELETE", "actions/runners/61")
            self.assertEqual(self.client("GET", "actions/runners/61")[0], 200)
            self.assertTrue(self.client._idle)
            self.assertEqual(self.client("DELETE", "actions/runners/61"), (204, {}))
            self.assertIsNone(self.client._owned_id)
            self.refused("GET", "actions/runners/61")

    def test_uncertain_post_never_retries_and_stale_idle_is_revoked(self):
        def stall(*_args):
            self.mark("exchange")
            time.sleep(2)
            return 201, {}
        with patch.object(self.client, "_exchange", side_effect=stall):
            self.refused("POST", "actions/runners/generate-jitconfig", self.body())
            self.refused("POST", "actions/runners/generate-jitconfig", self.body())
        self.assertEqual(self.observed(), "exchange\n")
        self.assertEqual(len(self.children), 1)
        self.assertTrue(self.client._mint_attempted)
        self.assertIsNone(self.client._owned_id)
        self.client._owned_id, self.client._idle = 61, True
        with patch.object(self.client, "_exchange", side_effect=stall):
            self.refused(path="actions/runners/61")
            self.refused("DELETE", "actions/runners/61")
        self.assertFalse(self.client._idle)
        self.assertEqual(self.observed(), "exchange\n")

    def test_child_output_is_discarded_and_limits_are_effective(self):
        def exchange(*_args):
            os.write(1, (TOKEN * 100000).encode())
            os.write(2, (AMBIENT * 100000).encode())
            return 200, {"pid": os.getpid(), "group": os.getpgrp(),
                         "limits": [list(resource.getrlimit(key)) for key in
                                    (resource.RLIMIT_CORE, resource.RLIMIT_FSIZE,
                                     resource.RLIMIT_AS, resource.RLIMIT_CPU)]}
        # Capture descriptors themselves: Python stream redirects cannot observe
        # a child writing directly to inherited stdout/stderr.
        reader, writer = os.pipe()
        saved = [os.dup(descriptor) for descriptor in (1, 2)]
        failure, status, result = None, None, None
        try:
            for descriptor in (1, 2):
                os.dup2(writer, descriptor)
            with patch.object(self.client, "_exchange", side_effect=exchange):
                try:
                    status, result = self.client("GET", "actions/runs/312")
                except transport.Refused as error:
                    failure = error
        finally:
            for descriptor, original in zip((1, 2), saved, strict=True):
                os.dup2(original, descriptor)
                os.close(original)
            os.close(writer)
            os.set_blocking(reader, False)
            try:
                captured = os.read(reader, 65536)
            finally:
                os.close(reader)
        self.assertEqual(captured, b"", "child diagnostic output escaped")
        self.assertIsNone(failure)
        self.assertEqual(status, 200)
        self.assertEqual(result["pid"], self.children[-1])
        self.assertEqual(result["group"], result["pid"])
        self.assertEqual(result["limits"], [[0, 0], [0, 0], [transport.CHILD_MEMORY] * 2, [1, 1]])
        self.assertNotEqual(os.getpgrp(), result["group"])

    def test_child_missing_crashed_oversize_and_ipc_overflow_are_refused(self):
        def missing(*_args):
            os._exit(0)
        def crashed(*_args):
            raise RuntimeError(TOKEN + AMBIENT)
        for exchange in (missing, crashed, lambda *_args: (200, {"x": "a" * transport.MAX_RESULT})):
            with self.subTest(exchange=exchange), patch.object(self.client, "_exchange", side_effect=exchange):
                self.refused()
        # The child writer and parent reader each enforce their own bound.
        real_write = os.write
        def overflow(fd, _data):
            chunk = b"x" * 65536
            for _ in range(100):
                real_write(fd, chunk)
            os._exit(0)
        real_read, read_limits = os.read, []
        def read(fd, limit):
            read_limits.append(limit)
            return real_read(fd, limit)
        with (patch.object(self.client, "_exchange", return_value=(200, {})),
              patch.object(transport.os, "write", side_effect=overflow),
              patch.object(transport.os, "read", side_effect=read)):
            self.refused()
        self.assertTrue(all(limit > 0 for limit in read_limits), "IPC bound was crossed")

    def test_result_size_boundary_and_ascii_expansion(self):
        obj = {"x": "a" * 100}
        raw_size = len(b"200\n" + json.dumps(obj, separators=(",", ":")).encode())
        with (patch.object(self.client, "_exchange", return_value=(200, obj)),
              patch.object(transport, "MAX_RESULT", raw_size)):
            self.assertEqual(self.client("GET", "actions/runs/312"), (200, obj))
        with (patch.object(self.client, "_exchange", return_value=(200, obj)),
              patch.object(transport, "MAX_RESULT", raw_size - 1)):
            self.refused()
        self.deadline.stop()  # Large valid IPC uses the shipped 45-second budget.
        # Raw UTF-8 and escaped surrogate values preserve the accepted JSON data.
        obj = {"x": "é" * (transport.MAX_BODY // 2 - 4), "surrogate": "\ud800"}
        with patch.object(self.client, "_exchange", return_value=(200, obj)):
            self.assertEqual(self.client("GET", "actions/runs/312"), (200, obj))

    def test_parent_baseexception_and_fork_failure_close_owned_resources(self):
        with patch.object(transport.os, "fork", side_effect=OSError(TOKEN)):
            self.refused()
        with (self.wire(), patch.object(transport.select, "select", side_effect=KeyboardInterrupt),
              self.assertRaises(KeyboardInterrupt)):
            self.client("GET", "actions/runs/312")
        with patch.object(transport.threading, "active_count", return_value=2):
            self.refused()
        with patch.object(transport.signal, "getsignal", return_value=transport.signal.SIG_IGN):
            self.refused()

    def test_session_setup_stall_and_parent_read_failure_cleanup(self):
        def stall():
            self.mark("setsid")
            time.sleep(2)
        with patch.object(transport.os, "setsid", side_effect=stall):
            self.refused()
        self.assertEqual(self.observed(), "setsid\n")
        with self.wire(), patch.object(transport.os, "read", side_effect=OSError(TOKEN)):
            self.refused()

    def test_child_limits_failure_and_inherited_stricter_limits(self):
        with self.wire(), patch.object(transport.resource, "setrlimit", side_effect=OSError(TOKEN)):
            self.refused()
        with (patch.object(transport.os, "dup2"),
              patch.object(transport.resource, "getrlimit", return_value=(0, 0)),
              patch.object(transport.resource, "setrlimit") as limits):
            transport.child_limits()
        self.assertEqual([call.args[1] for call in limits.call_args_list], [(0, 0)] * 4)

    def test_reap_handles_already_exited_child_and_interrupted_wait(self):
        with (patch.object(transport.os, "killpg", side_effect=ProcessLookupError),
              patch.object(transport.os, "kill", side_effect=ProcessLookupError),
              patch.object(transport.os, "waitpid", side_effect=[InterruptedError, (999, 0)]) as wait):
            transport.reap_child(999)
        self.assertEqual(wait.call_count, 2)

    def test_child_result_unit_success_error_and_oversize_exit(self):
        # Execute the child branch in isolation to qualify result framing and
        # fixed exit codes as well as the real-fork behavioral checks above.
        def exit_process(code):
            raise SystemExit(code)
        for result, expected in (((200, {"x": 1}), 0),
                                 ((200, {"x": "a" * transport.MAX_RESULT}), 1),
                                 (OSError(TOKEN + AMBIENT), 1)):
            written = bytearray()
            def write(_fd, raw, *, written=written):
                written.extend(raw[:3])  # Exercise partial writes without copies.
                return min(3, len(raw))
            exchange = (patch.object(self.client, "_exchange", side_effect=result)
                        if isinstance(result, Exception) else
                        patch.object(self.client, "_exchange", return_value=result))
            with (self.subTest(expected=expected), exchange,
                  patch.object(transport.os, "fork", return_value=0),
                  patch.object(transport.os, "setsid"),
                  patch.object(transport, "child_limits"),
                  patch.object(transport.os, "write", side_effect=write),
                  patch.object(transport.os, "_exit", side_effect=exit_process) as exit_child):
                with self.assertRaises(SystemExit) as caught:
                    self.client("GET", "actions/runs/312")
                self.assertEqual(caught.exception.code, expected)
                exit_child.assert_called_once_with(expected)
            self.assertEqual(written, b'200\n{"x":1}' if expected == 0 else b"")

    def test_complete_result_waits_for_child_exit_within_same_deadline(self):
        real_wait = os.waitid
        calls = []
        def wait(*args):
            if args[1] == os.getpid():
                return real_wait(*args)  # Caller capability probe must yield ECHILD.
            calls.append(args)
            return None if len(calls) == 1 else real_wait(*args)
        with self.wire(), patch.object(transport.os, "waitid", side_effect=wait):
            self.assertEqual(self.client("GET", "actions/runs/312"), (200, {}))
        self.assertGreaterEqual(len(calls), 2)


if __name__ == "__main__":
    unittest.main()
