"""Maintained repository API/lifecycle contracts extracted without Incus controller."""

import json
import re
import subprocess
import time
import urllib.error
import urllib.request

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
            with urllib.request.urlopen(req, timeout=30) as response:
                raw = response.read()
                return response.status, json.loads(raw) if raw else {}
        except urllib.error.HTTPError as error:
            return error.code, {}  # Never retain arbitrary error bodies or headers.
        except (OSError, ValueError):
            raise Refused('GitHub request failed') from None

    def mint(self, name, group, labels):
        require(type(group) is int and group > 0, 'invalid group id')
        require(re.fullmatch(r'vi-local-ci-[a-z0-9-]{6,50}', name), 'runner name refused')
        require(CAPABILITY not in labels, 'production capability not authorized by proof tool')
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
