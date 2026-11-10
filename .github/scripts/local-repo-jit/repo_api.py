"""Maintained repository API/lifecycle contracts extracted without Incus controller."""

import copy
import hashlib
import json
import re
import subprocess
import time
import urllib.error
import urllib.request
from typing import NamedTuple

REPO = 'myguard-labs/ViMbAdmin'

CAPABILITY = 'vimbadmin-pristine-jit-v1'

CLEAN = r'''set -eu
for p in /etc/myguard-build-env /etc/ci-gh-token /root/.docker/config.json /home/runner/.docker/config.json /var/run/incus/unix.socket; do test ! -e "$p"; done
test -z "$(find /run/credentials -mindepth 1 -maxdepth 2 -print)"
for p in /opt/actions-runner/.runner /opt/actions-runner/.credentials /opt/actions-runner/.credentials_rsaparams /opt/actions-runner/_work/.myguard-proof-marker; do test ! -e "$p"; done
test "$(docker info --format '{{.Containers}} {{.Images}}')" = '0 0'
for p in /opt/actions-runner/_work /home/runner/.local/state/vimbadmin-ci-sessions; do test -z "$(find "$p" -mindepth 1 -print -quit)"; done
'''

class Refused(RuntimeError):
    """Fixed public reason only; no subprocess/API data in diagnostics."""

def require(value, reason):
    if not value:
        raise Refused(reason)


def scoped_job_labels(run_id, run_attempt):
    """Routing only, not authority against a fork copying these public labels.

    A separately qualified producer must publish only this exact set after reset
    and verify the POST and GET label sets before starting a listener. This helper
    grants no production mint or listener authority to the maintained proof tool.
    """
    require(type(run_id) is int and run_id > 0 and type(run_attempt) is int and run_attempt > 0,
            'invalid routing run identity')
    suffix = '-run-' + str(run_id) + '-' + str(run_attempt)
    return [label + suffix for label in ('self-hosted', 'vimbadmin-owned-local-v1', 'docker',
                                        'jit-env-input', CAPABILITY)]


def require_scoped_job_labels(labels, run_id, run_attempt):
    expected = scoped_job_labels(run_id, run_attempt)
    require(isinstance(labels, list) and len(labels) == len(expected)
            and all(isinstance(label, str) for label in labels)
            and set(labels) == set(expected), 'scoped routing labels mismatch')

class RepoAPI:
    def __init__(self, external=False, transport=None, guard=None):
        require(external or transport is not None, 'external mode not enabled')
        self.transport, self.token = transport, None
        self.guard = guard
        self.owned_runners, self.owned_runs = {}, {}
        if external:
            p = subprocess.run(['/opt/myguard/tools/get-build-env-var', 'GITHUB_CLASSIC_TOKEN'],
                               capture_output=True, text=True, timeout=10, check=False)
            require(p.returncode == 0 and p.stdout.strip(), 'canonical credential unavailable')
            self.token = p.stdout.strip()

    def request(self, method, path, body=None):
        require(path.startswith(('actions/', 'git/ref/heads/')), 'API path refused')
        if method in ('POST', 'DELETE'):
            require(self.guard is not None, 'API mutation requires owned guest guard')
            self.guard()
            if method == 'DELETE':
                match = re.fullmatch(r'actions/runners/([0-9]+)', path)
                require(match and int(match[1]) in self.owned_runners, 'foreign API deletion refused')
            else:
                cancel = re.fullmatch(r'actions/runs/([0-9]+)/cancel', path)
                require(path in ('actions/runners/generate-jitconfig',
                                 'actions/workflows/static-analysis.yml/dispatches')
                        or (cancel and int(cancel[1]) in self.owned_runs), 'foreign API mutation refused')
        require(method in ('GET', 'POST', 'DELETE'), 'API method refused')
        if self.transport:
            return self.transport(method, path, body)
        req = urllib.request.Request('https://api.github.com/repos/' + REPO + '/' + path,
                                     data=json.dumps(body).encode() if body is not None else None,
                                     method=method,
                                     headers={'Authorization': 'Bearer ' + self.token,
                                              'Accept': 'application/vnd.github+json',
                                              'X-GitHub-Api-Version': '2026-03-10'})
        try:
            # Initial URL uses the fixed GitHub HTTPS origin and a validated repository path.
            with urllib.request.urlopen(req, timeout=30) as response:  # nosemgrep: python.lang.security.audit.dynamic-urllib-use-detected.dynamic-urllib-use-detected
                raw = response.read()
                return response.status, json.loads(raw) if raw else {}
        except urllib.error.HTTPError as error:
            return error.code, {}  # Never retain arbitrary error bodies or headers.
        except (OSError, ValueError):
            raise Refused('GitHub request failed') from None

    def mint(self, name, group, labels):
        require(type(group) is int and group > 0, 'invalid group id')
        require(re.fullmatch(r'vi-local-ci-[a-z0-9-]{6,50}', name), 'runner name refused')
        require(not any(isinstance(label, str) and (label.casefold() == CAPABILITY or label.casefold().startswith(CAPABILITY + '-run-'))
                        for label in labels), 'production capability not authorized by proof tool')
        status, result = self.request('POST', 'actions/runners/generate-jitconfig',
                                     {'name': name, 'runner_group_id': group,
                                      'labels': labels, 'work_folder': '_work'})
        require(status == 201, 'repository JIT request refused')
        runner, payload = result.get('runner', {}), result.get('encoded_jit_config')
        rid = runner.get('id')
        require(type(rid) is int and rid > 0 and runner.get('name') == name
                and isinstance(payload, str) and bool(payload), 'invalid JIT response')
        self.owned_runners[rid] = name
        return rid, payload

    def retired(self, rid):
        require(rid in self.owned_runners, 'foreign runner refused')
        status, _ = self.request('GET', 'actions/runners?per_page=1')
        require(status == 200, 'backend authority unproved')
        status, _ = self.request('GET', 'actions/runners/' + str(rid))
        require(status in (200, 404), 'backend retirement unproved')
        return status == 404

    def delete_idle(self, rid):
        require(rid in self.owned_runners, 'foreign runner deletion refused')
        status, _ = self.request('GET', 'actions/runners?per_page=1')
        require(status == 200, 'backend authority unproved')
        status, runner = self.request('GET', 'actions/runners/' + str(rid))
        require(status == 200 and runner.get('id') == rid
                and runner.get('name') == self.owned_runners[rid] and runner.get('busy') is False,
                'busy or unknown runner deletion refused')
        status, _ = self.request('DELETE', 'actions/runners/' + str(rid))
        require(status == 204, 'owned runner deletion failed')

    def dispatch(self, workflow, ref, sha, marker, mode):
        require(workflow == 'static-analysis.yml' and re.fullmatch(r'[a-zA-Z0-9_-]{1,80}', ref), 'proof workflow/ref refused')
        require(re.fullmatch(r'[0-9a-f]{40}', sha) and re.fullmatch(r'[a-z0-9-]{12,80}', marker)
                and mode in ('normal', 'hold', 'probe'), 'proof identity refused')
        status, obj = self.request('GET', 'git/ref/heads/' + ref)
        require(status == 200 and obj.get('object', {}).get('sha') == sha, 'proof ref changed')
        title = 'local-jit-proof:' + marker + ':' + mode
        path = 'actions/workflows/' + workflow + '/runs?event=workflow_dispatch&per_page=100'
        status, result = self.request('GET', path)
        require(status == 200 and not any(r.get('display_title') == title for r in result.get('workflow_runs', [])), 'preexisting proof run refused')
        status, _ = self.request('POST', 'actions/workflows/' + workflow + '/dispatches',
                                 {'ref': ref, 'inputs': {'mode': mode, 'marker': marker}})
        require(status == 204, 'proof dispatch failed')
        return {'title': title, 'sha': sha, 'ref': ref, 'workflow': workflow,
                'runs_path': path, 'mode': mode}

    def find_run(self, proof):
        status, result = self.request('GET', proof['runs_path'])
        require(status == 200, 'proof run lookup failed')
        matches = [r for r in result.get('workflow_runs', []) if self.matches(r, proof)]
        require(len(matches) <= 1, 'ambiguous proof runs refused')
        if not matches:
            return None
        run = matches[0]
        self.owned_runs[run['id']] = proof
        return run

    @staticmethod
    def matches(run, proof):
        return (run.get('display_title') == proof['title'] and run.get('head_sha') == proof['sha']
                and isinstance(proof.get('ref'), str) and run.get('head_branch') == proof['ref']
                and run.get('event') == 'workflow_dispatch'
                and run.get('path', '').split('@')[0] == '.github/workflows/' + proof['workflow'])

    def owned_run(self, run_id):
        require(run_id in self.owned_runs, 'foreign run refused')
        status, run = self.request('GET', 'actions/runs/' + str(run_id))
        require(status == 200 and run.get('id') == run_id
                and self.matches(run, self.owned_runs[run_id]), 'owned run binding changed')
        return run

    def cancel(self, run_id, rid):
        require(rid in self.owned_runners, 'foreign runner refused')
        self.owned_run(run_id)
        require(self.owned_runs[run_id].get('mode') == 'hold', 'only owned hold runs may be cancelled')
        status, data = self.request('GET', 'actions/runs/' + str(run_id) + '/jobs')
        jobs = data.get('jobs', [])
        require(status == 200 and len(jobs) == 1 and jobs[0].get('runner_id') == rid
                and jobs[0].get('status') == 'in_progress', 'cancellation placement unproved')
        status, _ = self.request('POST', 'actions/runs/' + str(run_id) + '/cancel')
        require(status == 202, 'owned cancellation failed')


class VerifiedJobReceipt(NamedTuple):
    """Verifier-issued snapshot, never a decoded supplied qualification record.

    The trusted provider must independently verify immutable qualification,
    current guest/head/job and admission binding. claim_unused must atomically
    consume that exact binding, returning True only to its single winner. There
    is a protected local-store provider in trusted_provider; live transport
    remains unavailable pending its separate qualification.
    """

    request_sha256: str
    repository: str
    candidate_sha: str
    qualification_id: str
    admission_id: str
    verified_at: int
    expires_at: int


class QualifiedJobAPI(RepoAPI):
    """Qualified-actual API contract exercised only through injected transport.

    Caller schema-validates the complete request first. Supplied record equality
    is insufficient: verify and claim_unused are separate trusted operations.
    All failure paths retain unresolved ownership; no uncertain ID is deleted.
    """

    def __init__(self, request, request_sha256, *, external=False, transport=None,
                 verifier=None, guard=None, clock=time.time):
        require(external is False and callable(transport),
                'qualified live transport unavailable; injected transport required')
        require(callable(guard) and callable(getattr(verifier, 'verify', None))
                and callable(getattr(verifier, 'claim_unused', None)),
                'independent verifier and owned guest guard required')
        super().__init__(transport=transport, guard=guard)
        self.binding = copy.deepcopy(request)
        self.digest = hashlib.sha256(json.dumps(self.binding, sort_keys=True,
                                                separators=(',', ':'), ensure_ascii=True).encode('ascii')).hexdigest()
        require(self.digest == request_sha256 and self.binding['repository'] == REPO
                and self.binding['mode'] == 'qualified-actual', 'validated request binding required')
        self.verifier, self.clock = verifier, clock
        self.started = False
        self.admitted = False
        self.cleanup_pending = None

    def request(self, method, path, body=None):
        raise Refused('qualified API requires admitted job flow')

    def _send(self, method, path, body=None):
        require(self.admitted, 'verified admission required before transport')
        # Never fall through RepoAPI's credential/urllib path, even if mutated.
        require(callable(self.transport) and self.token is None, 'qualified live transport unavailable')
        return super().request(method, path, body)

    def _admit(self):
        self.guard()
        receipt = self.verifier.verify(copy.deepcopy(self.binding))
        require(type(receipt) is VerifiedJobReceipt, 'independent verified receipt required')
        require((receipt.request_sha256, receipt.repository, receipt.candidate_sha,
                 receipt.qualification_id, receipt.admission_id) ==
                (self.digest, REPO, self.binding['candidate_sha'],
                 self.binding['qualification_id'], self.binding['admission_id']),
                'verified receipt binding changed')
        now = self.clock()
        require(type(receipt.verified_at) is int and type(receipt.expires_at) is int
                and receipt.verified_at <= now < receipt.expires_at <= receipt.verified_at + 300,
                'verified receipt stale or malformed')
        require(self.verifier.claim_unused(receipt) is True, 'unused bound admission required')
        self.guard()
        self.admitted = True

    def _current_job(self):
        binding = self.binding
        status, run = self._send('GET', 'actions/runs/' + str(binding['run_id']))
        require(status == 200 and type(run) is dict
                and type(run.get('id')) is int and run['id'] == binding['run_id']
                and type(run.get('run_attempt')) is int and run['run_attempt'] == binding['run_attempt']
                and run.get('head_sha') == binding['candidate_sha']
                and type(run.get('repository')) is dict and run['repository'].get('full_name') == REPO,
                'current run identity changed')
        status, job = self._send('GET', 'actions/jobs/' + str(binding['job_id']))
        require(status == 200 and type(job) is dict
                and type(job.get('id')) is int and job['id'] == binding['job_id']
                and type(job.get('run_id')) is int and job['run_id'] == binding['run_id']
                and type(job.get('run_attempt')) is int and job['run_attempt'] == binding['run_attempt']
                and job.get('head_sha') == binding['candidate_sha']
                and job.get('status') == 'queued' and 'runner_id' in job
                and (job['runner_id'] is None or (type(job['runner_id']) is int and job['runner_id'] == 0)),
                'current queued job identity changed')
        require_scoped_job_labels(job.get('labels'), binding['run_id'], binding['run_attempt'])

    def _runner(self, runner, expected_id=None):
        require(type(runner) is dict and type(runner.get('id')) is int and runner['id'] > 0
                and (expected_id is None or runner['id'] == expected_id)
                and runner.get('name') == self.binding['runner_name'], 'runner identity mismatch')
        labels = runner.get('labels')
        require(type(labels) is list and all(type(label) is dict for label in labels),
                'runner label objects required')
        require_scoped_job_labels([label.get('name') for label in labels],
                                  self.binding['run_id'], self.binding['run_attempt'])
        return runner['id']

    def delete_idle(self, rid):
        require(type(rid) is int and rid in self.owned_runners, 'foreign runner deletion refused')
        status, runner = self._send('GET', 'actions/runners/' + str(rid))
        require(status == 200, 'owned cleanup identity unproved')
        self._runner(runner, rid)
        require(runner.get('busy') is False, 'busy or unknown runner deletion refused')
        status, _ = self._send('DELETE', 'actions/runners/' + str(rid))
        require(status == 204, 'owned runner deletion failed')
        del self.owned_runners[rid]

    def begin(self, group, listener):
        require(not self.started, 'one admission permits one mint attempt')
        self.started = True
        require(type(group) is int and group > 0 and callable(listener), 'job handoff parameters refused')
        self._admit()
        self._current_job()
        labels = scoped_job_labels(self.binding['run_id'], self.binding['run_attempt'])
        status, result = self._send('POST', 'actions/runners/generate-jitconfig',
                                      {'name': self.binding['runner_name'], 'runner_group_id': group,
                                       'labels': labels, 'work_folder': '_work'})
        require(status == 201 and type(result) is dict, 'repository job JIT request refused')
        # Never derive cleanup ownership from an unbound POST/GET response.
        rid = self._runner(result.get('runner'))
        self.owned_runners[rid] = self.binding['runner_name']
        try:
            payload = result.get('encoded_jit_config')
            require(type(payload) is str and bool(payload), 'invalid job JIT payload')
            status, runner = self._send('GET', 'actions/runners/' + str(rid))
            require(status == 200, 'created runner lookup refused')
            self._runner(runner, rid)
            self.guard()
            process = listener(rid, payload)
            return rid, process
        except Exception:
            # Failed handoff may have started a listener: busy/foreign/unknown
            # cleanup refuses and keeps the exact owned ID for reconciliation.
            try:
                self.delete_idle(rid)
            except (OSError, ValueError, RuntimeError):
                self.cleanup_pending = rid
            raise


class Supervisor:
    def __init__(self, guest, api):
        self.guest, self.api = guest, api

    def begin(self, group, proof_label):
        require(self.guest.outstanding is None, 'unresolved identity prevents mint')
        require(re.fullmatch(r'vi-local-proof-[a-z0-9-]{8,60}', proof_label), 'invalid proof capability')
        self.guest.publish()
        labels = ['self-hosted', 'Linux', 'X64', 'builder02', 'docker', 'jit-env-input', proof_label]
        self.guest.qualified = False
        self.guest.outstanding = 'mint-pending'
        self.guest.save('mint-pending')
        rid, jit = self.api.mint(self.guest.name, group, labels)
        self.guest.outstanding = rid
        self.guest.save('JIT-created')
        self.guest.identity()
        process = self.guest.cmd.listener(dict(self.guest.receipt), jit)
        del jit
        return rid, process

    def finish(self, rid, process, run_id, conclusion):
        require(self.guest.outstanding == rid, 'identity mismatch')
        run = self.api.owned_run(run_id)
        require(run.get('status') == 'completed' and run.get('conclusion') == conclusion, 'owned run completion unproved')
        status, data = self.api.request('GET', 'actions/runs/' + str(run_id) + '/jobs')
        jobs = data.get('jobs', [])
        require(status == 200 and len(jobs) == 1 and jobs[0].get('runner_id') == rid
                and jobs[0].get('status') == 'completed', 'one-job placement unproved')
        require(process.poll() is not None and process.returncode == 0, 'listener completion unproved')
        require(self.api.retired(rid), 'unresolved backend prevents successor')
        self.guest.outstanding = None
        self.guest.save('backend-retired')
        self.guest.reset()

    def run_one(self, group, label, workflow, ref, sha, marker, mode, timeout=1200):
        require(mode in ('normal', 'hold', 'probe'), 'invalid proof mode')
        rid, process = self.begin(group, label)
        proof = self.api.dispatch(workflow, ref, sha, marker, mode)
        deadline, run, cancelled = time.monotonic() + timeout, None, False
        while time.monotonic() < deadline:
            run = self.api.find_run(proof) if run is None else self.api.owned_run(run['id'])
            if run is not None:
                if mode == 'hold' and run.get('status') == 'in_progress' and not cancelled:
                    self.api.cancel(run['id'], rid)
                    cancelled = True
                if run.get('status') == 'completed' and process.poll() is not None:
                    self.finish(rid, process, run['id'], 'cancelled' if mode == 'hold' else 'success')
                    return {'runner_id': rid, 'run_id': run['id'], 'reset': 'clean'}
            time.sleep(3)
        raise Refused('deadline reached; identity retained; no successor/reset/deletion authorized')
