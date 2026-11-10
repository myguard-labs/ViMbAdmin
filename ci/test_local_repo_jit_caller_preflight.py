"""Offline qualification of the transport's actual Linux caller prerequisites."""

import _thread
import contextlib
import errno
import os
import threading
import time
import unittest
from types import SimpleNamespace
from unittest.mock import patch

from ci import test_local_repo_jit_api_transport as fixtures

transport = fixtures.transport


class CallerTests(unittest.TestCase):
    def setUp(self):
        self.case = fixtures.EnvelopeTests()
        self.addCleanup(self.case.doCleanups)
        self.case.setUp()

    def refused_before_fork(self):
        with patch.object(transport.os, "fork") as fork:
            self.case.refused()
        fork.assert_not_called()
        self.assertEqual(self.case.observed(), "", "preflight reached network work")

    def test_actual_linux_caller_and_child_specific_waitid(self):
        calls = []
        real_waitid = os.waitid

        def waitid(*args):
            calls.append(args)
            return real_waitid(*args)

        with self.case.wire(), patch.object(transport.os, "waitid", side_effect=waitid):
            self.assertEqual(self.case.client("GET", "actions/runs/312"), (200, {}))
        flags = os.WEXITED | os.WNOHANG | os.WNOWAIT
        self.assertEqual(calls[0], (os.P_PID, os.getpid(), flags))
        self.assertTrue(calls[1:])
        self.assertTrue(all(call == (os.P_PID, self.case.children[0], flags)
                            for call in calls[1:]))
        self.assertEqual(self.case.observed(), "send\nclose\n")

    def test_kernel_probe_preserves_unrelated_child(self):
        pid = self.case.real_fork()
        if pid == 0:
            os._exit(0)
        try:
            transport.caller_ready()
        finally:
            observed, status = os.waitpid(pid, 0)
        self.assertEqual(observed, pid)
        self.assertEqual(status, 0)

    def test_missing_unreadable_and_shadowed_task_view_refused_before_fork(self):
        for error in (FileNotFoundError(), PermissionError(), OSError(errno.EIO, "fixture")):
            with self.subTest(error=type(error).__name__), patch.object(transport.os, "scandir", side_effect=error):
                self.refused_before_fork()
        for tasks in ([], [SimpleNamespace(name="shadowed")],
                      [SimpleNamespace(name=str(os.getpid())), SimpleNamespace(name="extra")]):
            with (self.subTest(tasks=tasks), patch.object(transport.os, "scandir",
                  return_value=contextlib.nullcontext(iter(tasks)))):
                self.refused_before_fork()

    def test_task_enumeration_is_bounded(self):
        def tasks():
            yield SimpleNamespace(name=str(os.getpid()))
            yield SimpleNamespace(name="extra")
            self.fail("preflight enumerated beyond two tasks")
        with patch.object(transport.os, "scandir", return_value=contextlib.nullcontext(tasks())):
            self.refused_before_fork()

    def test_missing_shadowed_waitid_or_flags_and_kernel_refusal_before_fork(self):
        waitid = os.waitid
        try:
            del os.waitid
            self.refused_before_fork()
        finally:
            os.waitid = waitid
        for attribute in ("waitid", "WNOWAIT", "WNOHANG", "WEXITED", "P_PID"):
            with self.subTest(attribute=attribute), patch.object(transport.os, attribute, None):
                self.refused_before_fork()
        for error in (OSError(errno.ENOSYS, "fixture"), OSError(errno.EINVAL, "fixture"),
                      PermissionError(errno.EPERM, "fixture")):
            with self.subTest(error=error.errno), patch.object(transport.os, "waitid", side_effect=error):
                self.refused_before_fork()
        with patch.object(transport.os, "waitid", return_value=None):
            self.refused_before_fork()

    def test_platform_python_threads_and_signal_handler_refused_before_fork(self):
        with patch.object(transport.sys, "platform", "freebsd"):
            self.refused_before_fork()
        with patch.object(transport.threading, "active_count", return_value=2):
            self.refused_before_fork()
        for handler in (transport.signal.SIG_IGN, lambda *_args: None):
            with self.subTest(handler=handler), patch.object(transport.signal, "getsignal", return_value=handler):
                self.refused_before_fork()

    def test_unregistered_python_task_is_refused_before_fork(self):
        started, release, done = threading.Event(), threading.Event(), threading.Event()

        def unregistered():
            try:
                started.set()
                release.wait()
            finally:
                done.set()

        _thread.start_new_thread(unregistered, ())
        try:
            self.assertTrue(started.wait(2), "fixture thread did not start")
            self.assertEqual(threading.active_count(), 1, "fixture unexpectedly registered with threading")
            self.assertGreater(len(os.listdir("/proc/self/task")), 1)
            self.refused_before_fork()
        finally:
            release.set()
            self.assertTrue(done.wait(2), "fixture thread did not finish")
            deadline = time.monotonic() + 2
            while len(os.listdir("/proc/self/task")) != 1 and time.monotonic() < deadline:
                time.sleep(0.001)
            self.assertEqual(len(os.listdir("/proc/self/task")), 1, "fixture kernel task still alive")
        transport.caller_ready()
        with self.case.wire():
            self.assertEqual(self.case.client("GET", "actions/runs/312"), (200, {}))


if __name__ == "__main__":
    unittest.main()
