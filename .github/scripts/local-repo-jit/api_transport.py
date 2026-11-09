"""Request-bound HTTPS adapter for QualifiedJobAPI; carries no admission authority.

Supply the token explicitly in memory and inject this callable through Q3's
transport argument. A Linux child bounds the complete blocking HTTPS exchange.
No environment credential, proxy, redirect, retry, or backend qualification
is part of this adapter; the caller must be single-threaded.
"""

import http.client
import json
import math
import os
import re
import resource
import select
import signal
import ssl
import sys
import threading
import time

HOST = "api.github.com"
PREFIX = "/repos/myguard-labs/ViMbAdmin/"
REPOSITORY = "myguard-labs/ViMbAdmin"
IO_TIMEOUT = 10
BODY_TIMEOUT = 30
CALL_TIMEOUT = 45
MAX_RESULT = 6 * 1024 * 1024 + 1024  # Worst-case ASCII JSON escaping of MAX_BODY.
CHILD_MEMORY = 512 * 1024 * 1024
MAX_BODY = 1024 * 1024  # JIT configuration plus one run/job/runner, never a list.
MAX_DEPTH = 32
MAX_ID = 2**63 - 1


class Refused(RuntimeError):
    """Only fixed diagnostics cross the credential boundary."""


def require(value):
    if not value:
        raise Refused("repository transport refused")


def positive_id(value):
    return type(value) is int and 0 < value <= MAX_ID


def object_pairs(pairs):
    result = {}
    for key, value in pairs:
        require(key not in result)
        result[key] = value
    return result


def finite_float(value):
    result = float(value)
    require(math.isfinite(result))
    return result


def invalid_constant(_value):
    raise Refused("repository response refused")


def decode_object(raw):
    # Bound nesting before the recursive decoder; quotes/escapes do not add depth.
    depth, quoted, escaped = 0, False, False
    for byte in raw:
        if quoted:
            if escaped:
                escaped = False
            elif byte == 92:
                escaped = True
            elif byte == 34:
                quoted = False
        elif byte == 34:
            quoted = True
        elif byte in (91, 123):
            depth += 1
            require(depth <= MAX_DEPTH)
        elif byte in (93, 125):
            depth -= 1
    result = json.loads(raw.decode("utf-8"), object_pairs_hook=object_pairs,
                        parse_constant=invalid_constant, parse_float=finite_float)
    require(type(result) is dict)
    return result



def caller_ready():
    """Qualify the calling Linux process before a credential-bearing child."""
    require(sys.platform == "linux" and threading.active_count() == 1
            and signal.getsignal(signal.SIGCHLD) == signal.SIG_DFL)
    # active_count sees only registered Python threads, not _thread/native ones.
    # Read at most two kernel tasks; the only supported caller is its main task.
    with os.scandir("/proc/self/task") as tasks:
        task = next(tasks, None)
        require(task is not None and task.name == str(os.getpid())
                and next(tasks, None) is None)
    # Probe the actual kernel/namespace with the exact flags before network use.
    # A process cannot be its own child: ECHILD is the sole expected outcome.
    # This never observes or reaps another caller's children.
    try:
        os.waitid(os.P_PID, os.getpid(), os.WEXITED | os.WNOHANG | os.WNOWAIT)
    except ChildProcessError:
        return
    raise Refused("repository transport refused")


def child_limits():
    # Preserve stricter inherited limits. No core, diagnostic file, or terminal
    # output may carry the token or JIT response, even on an unexpected failure.
    with open(os.devnull, "r+b", buffering=0) as sink:
        for descriptor in (0, 1, 2):
            os.dup2(sink.fileno(), descriptor)
    for key, limit in ((resource.RLIMIT_CORE, 0), (resource.RLIMIT_FSIZE, 0),
                       (resource.RLIMIT_AS, CHILD_MEMORY),
                       (resource.RLIMIT_CPU, math.ceil(CALL_TIMEOUT))):
        soft, hard = resource.getrlimit(key)
        cap = min(value for value in (limit, soft, hard) if value != resource.RLIM_INFINITY)
        resource.setrlimit(key, (cap, cap))


def reap_child(pid):
    # The unreaped child's PID cannot be reused. Its new session's group ID is
    # exactly pid; before setsid succeeds that group does not exist. Never use
    # the caller's process group, and also kill pid to cover the setsid race.
    try:
        os.killpg(pid, signal.SIGKILL)
    except ProcessLookupError:
        pass
    try:
        os.kill(pid, signal.SIGKILL)
    except ProcessLookupError:
        pass
    while True:
        try:
            os.waitpid(pid, 0)
            return
        except InterruptedError:
            continue


class APITransport:
    """One request generation, one mint attempt, and its observed idle cleanup.

    The validated request is public binding data, never a substitute for Q3's
    trusted verifier/admission/guest guard. The caller owns credential lifetime.
    """

    def __init__(self, token, request, *, runner_group_id):
        require(type(token) is str and 1 <= len(token) <= 4096
                and re.fullmatch(r"[A-Za-z0-9_\-.]+", token) is not None)
        require(type(request) is dict and request.get("repository") == REPOSITORY
                and request.get("mode") == "qualified-actual"
                and all(positive_id(request.get(key)) for key in ("run_id", "run_attempt", "job_id"))
                and type(request.get("runner_name")) is str
                and re.fullmatch(r"vi-local-ci-[0-9a-f]{12}-[0-9a-f]{24}", request["runner_name"]) is not None
                and positive_id(runner_group_id))
        self._token = token
        self._run_id, self._job_id = request["run_id"], request["job_id"]
        self._name, self._group = request["runner_name"], runner_group_id
        suffix = "-run-" + str(self._run_id) + "-" + str(request["run_attempt"])
        self._labels = tuple(label + suffix for label in
                             ("self-hosted", "vimbadmin-owned-local-v1", "docker",
                              "jit-env-input", "vimbadmin-pristine-jit-v1"))
        self._mint_attempted = False
        self._owned_id = None
        self._idle = False
        # Use system trust paths explicitly: SSL_CERT_FILE/DIR cannot replace CA
        # trust. HTTPSConnection has no proxy, netrc or redirect discovery.
        paths = ssl.get_default_verify_paths()
        context = ssl.SSLContext(ssl.PROTOCOL_TLS_CLIENT)
        context.load_verify_locations(cafile=paths.openssl_cafile, capath=paths.openssl_capath)
        context.minimum_version = ssl.TLSVersion.TLSv1_2
        self._context = context

    def __call__(self, method, path, body=None):
        # Raise outside the handler so arbitrary connection/parser exceptions,
        # which can contain credentials or response text, are not chained.
        try:
            return self._request(method, path, body)
        except Exception:
            pass
        raise Refused("repository transport failed")

    def _validate(self, method, path, body):
        require(type(method) is str and type(path) is str)
        if method == "POST":
            require(path == "actions/runners/generate-jitconfig" and not self._mint_attempted)
            require(type(body) is dict and set(body) == {"name", "runner_group_id", "labels", "work_folder"}
                    and body["name"] == self._name and type(body["name"]) is str
                    and positive_id(body["runner_group_id"]) and body["runner_group_id"] == self._group
                    and body["work_folder"] == "_work" and type(body["work_folder"]) is str
                    and type(body["labels"]) is list and len(body["labels"]) == len(self._labels)
                    and all(type(label) is str for label in body["labels"])
                    and set(body["labels"]) == set(self._labels))
            return 201
        require(body is None)
        if method == "GET" and path in ("actions/runs/" + str(self._run_id),
                                         "actions/jobs/" + str(self._job_id)):
            return 200
        require(self._owned_id is not None and path == "actions/runners/" + str(self._owned_id))
        require(method == "GET" or (method == "DELETE" and self._idle))
        return 200 if method == "GET" else 204

    def _runner_id(self, obj):
        labels = obj.get("labels")
        require(positive_id(obj.get("id")) and obj.get("name") == self._name
                and type(labels) is list and len(labels) == len(self._labels)
                and all(type(label) is dict and type(label.get("name")) is str for label in labels)
                and {label["name"] for label in labels} == set(self._labels))
        return obj["id"]

    def _read(self, connection, response):
        require(response.getheader("Content-Encoding", "identity").lower() == "identity")
        require(response.getheader("Content-Type", "").split(";", 1)[0].strip().lower()
                in ("application/json", "application/vnd.github+json"))
        length = response.getheader("Content-Length")
        encoding = response.getheader("Transfer-Encoding")
        require(encoding is None or (encoding.lower() == "chunked" and length is None))
        if length is not None:
            require(re.fullmatch(r"[0-9]{1,10}", length) is not None and int(length) <= MAX_BODY)
        deadline = time.monotonic() + BODY_TIMEOUT
        chunks, size = [], 0
        while True:
            remaining = deadline - time.monotonic()
            require(remaining > 0)
            if connection.sock is not None:
                connection.sock.settimeout(min(IO_TIMEOUT, remaining))
            chunk = response.read1(min(65536, MAX_BODY + 1 - size))
            if not chunk:
                break
            size += len(chunk)
            require(size <= MAX_BODY)
            chunks.append(chunk)
        require(length is None or int(length) == size)
        return decode_object(b"".join(chunks))

    def _request(self, method, path, body):
        expected = self._validate(method, path, body)
        data = json.dumps(body, separators=(",", ":"), ensure_ascii=True).encode("ascii") if body is not None else None
        if method == "POST":
            self._mint_attempted = True  # An uncertain POST must never be retried.
        if path.startswith("actions/runners/"):
            self._idle = False  # Failed/stale observations cannot authorize DELETE.
        status, obj = self._exchange_process(method, path, data, expected)
        if status == 204:
            self._owned_id = None
        elif status < 400:
            if method == "POST":
                require(type(obj.get("runner")) is dict)
                self._owned_id = self._runner_id(obj["runner"])
            elif path.startswith("actions/runners/"):
                require(self._runner_id(obj) == self._owned_id)
                self._idle = obj.get("busy") is False
        return status, obj

    def _exchange_process(self, method, path, data, expected):
        # Single caller owns the idle/mint state. Validate and revoke stale
        # authority before fork; a lost/uncertain result never permits a retry.
        # Child owns network resources only. Parent publishes runner ownership
        # only after a complete bounded result and a successful child exit.
        # All exits (including BaseException in the parent) kill and reap the
        # exact child before returning. SIGKILL/reaping rely on Linux scheduling.
        caller_ready()
        deadline = time.monotonic() + CALL_TIMEOUT
        reader, writer = os.pipe()
        pid = None
        try:
            pid = os.fork()
            if pid == 0:
                child_status = 1
                try:
                    os.close(reader)
                    reader = None
                    os.setsid()
                    child_limits()
                    status, obj = self._exchange(method, path, data, expected)
                    raw = str(status).encode("ascii") + b"\n" + json.dumps(
                        obj, separators=(",", ":"), ensure_ascii=True).encode("ascii")
                    require(len(raw) <= MAX_RESULT)
                    pending = memoryview(raw)
                    while pending:
                        pending = pending[os.write(writer, pending):]
                    child_status = 0
                except BaseException:
                    pass  # Never serialize arbitrary exceptions or tracebacks.
                os._exit(child_status)
            os.close(writer)
            writer = None
            os.set_blocking(reader, False)
            chunks, size = [], 0
            while True:
                remaining = deadline - time.monotonic()
                require(remaining > 0)
                ready, _, _ = select.select([reader], [], [], remaining)
                require(ready)
                chunk = os.read(reader, min(65536, MAX_RESULT + 1 - size))
                if not chunk:
                    break
                size += len(chunk)
                require(size <= MAX_RESULT)
                chunks.append(chunk)
            # Observe without reaping: keep the PID reserved until group cleanup.
            while True:
                require(time.monotonic() < deadline)
                exited = os.waitid(os.P_PID, pid, os.WEXITED | os.WNOHANG | os.WNOWAIT)
                if exited is not None:
                    require(exited.si_code == os.CLD_EXITED and exited.si_status == 0)
                    break
                time.sleep(min(0.01, max(0, deadline - time.monotonic())))
            status_raw, raw = b"".join(chunks).split(b"\n", 1)
            require(re.fullmatch(b"[0-9]{3}", status_raw) is not None)
            status, obj = int(status_raw), decode_object(raw)
            require(status == expected or 400 <= status <= 599)
            require(time.monotonic() < deadline)
            return status, obj
        finally:
            try:
                if pid is not None and pid > 0:
                    reap_child(pid)
            finally:
                if reader is not None:
                    os.close(reader)
                if writer is not None:
                    os.close(writer)

    def _exchange(self, method, path, data, expected):
        headers = {"Authorization": "Bearer " + self._token,
                   "Accept": "application/vnd.github+json", "Accept-Encoding": "identity",
                   "Content-Type": "application/json", "User-Agent": "vimbadmin-local-jit",
                   "X-GitHub-Api-Version": "2026-03-10"}
        connection = http.client.HTTPSConnection(HOST, port=443, timeout=IO_TIMEOUT, context=self._context)
        try:
            connection.set_debuglevel(0)  # Never inherit process-wide HTTP debug logging.
            connection.request(method, PREFIX + path, body=data, headers=headers)
            response = connection.getresponse()
            status = response.status
            require(type(status) is int and (status == expected or 400 <= status <= 599))
            if status >= 400:
                return status, {}  # Do not read, echo, or retain error bodies/headers.
            if status == 204:
                return status, {}
            return status, self._read(connection, response)
        finally:
            try:
                connection.close()
            except Exception:
                pass  # A close error must not replace a redacted result/exception.
