"""Concrete Docker/KVM JIT adapter prototype. CLI runtime/external use disabled."""
import argparse
import hashlib
import importlib.util
import json
import os
import re
from pathlib import Path
from typing import TYPE_CHECKING

ROOT = Path(__file__).resolve().parent
CODE_SEAL = '8e12d3ccb961561a4de2f42a3c87f604a2436c2bf7777126fdaf86f520adac4c'
REPO = 'myguard-labs/ViMbAdmin'
RUNNER_NAME = 'vi-local-ci-208c2d13-01'
Q2_SEAL = 'ee81956ba51a16c083f47fcc23c502575a6fb18c673cb039e9f22267a4837284'
V2_SEAL = '2ae75af3884be1dd90b5a6167243fcc256814f031bbaee06a3fe014866cb53c3'
BRIDGE_SHA = 'a5102aa8fb5bef45031f8d2c5a22f341caf0abbc50c91e78059156034fa2f4a5'


class Refused(RuntimeError):
    pass


def require(value, reason):
    if not value:
        raise Refused(reason)


def frozen(directory, seal, filename, module_name):
    manifest = directory / 'code-inputs.json'
    require(hashlib.sha256(manifest.read_bytes()).hexdigest() == seal, 'frozen manifest changed')
    source = directory / filename
    require(hashlib.sha256(source.read_bytes()).hexdigest() == json.loads(manifest.read_text())[filename]['sha256'],
            'frozen source changed')
    spec = importlib.util.spec_from_file_location(module_name, source)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


v2 = frozen(ROOT, CODE_SEAL, 'repo_api.py', 'v2_repo_contract')
q2 = frozen(ROOT, CODE_SEAL, 'vm_backend.py', 'q2_id_backend')
require(v2.REPO == REPO, 'repository contract changed')


def validate_config(config):
    expected = {'repository': REPO, 'runner_name': RUNNER_NAME, 'runner_version': '2.336.0',
                'production_capability': v2.CAPABILITY, 'runtime_qualification': 'UNQUALIFIED',
                'external_authority': False, 'q2_manifest_sha256': Q2_SEAL, 'v2_manifest_sha256': V2_SEAL}
    require(set(config) == set(expected) | {'runtime_image', 'baseline_context_seal'}, 'configuration fields refused')
    require(all(config.get(k) == value for k, value in expected.items()), 'configuration authority/contract changed')
    require(config['runtime_image'] is None or re.fullmatch('sha256:[0-9a-f]{64}', config['runtime_image']), 'image pin refused')
    require(config['baseline_context_seal'] is None or re.fullmatch('[0-9a-f]{64}', config['baseline_context_seal']), 'baseline pin refused')
    return config


if TYPE_CHECKING:
    from repo_api import RepoAPI as RepoAPIBase
    from repo_api import Supervisor as SupervisorBase
else:
    RepoAPIBase = v2.RepoAPI
    SupervisorBase = v2.Supervisor


class RepoAPI(RepoAPIBase):
    def __init__(self, external=False, transport=None, guard=None):
        require(not external and callable(transport), 'live mode disabled pending qualified local route; fixture transport required')
        super().__init__(external=False, transport=transport, guard=guard)

    def mint(self, name, group, labels):
        require(name == RUNNER_NAME, 'exact local runner name required')
        return super().mint(name, group, labels)

    def dispatch(self, workflow, ref, sha, marker, mode):
        require(ref not in ('master', 'main'), 'default branch is not a private proof ref')
        return super().dispatch(workflow, ref, sha, marker, mode)


class Listener:
    def __init__(self, guest, pid):
        self.guest, self.pid = guest, pid
        self.outer_id = guest.receipt['id']
        self.returncode, self.reaped = None, False

    def poll(self):
        self.guest.identity()
        require(self.guest.receipt['id'] == self.outer_id, 'stale listener generation')
        if self.reaped:
            return self.returncode
        result = self.guest.bridge({'mode': 'status', 'pid': self.pid})
        if not result['exited']:
            return None
        self.returncode = result['exitcode'] if result['signal'] is None else 128 + result['signal']
        self.reaped = True
        self.guest.save('listener-reaped')
        return self.returncode


class DockerGuest:
    def __init__(self, directory, config, owner_factory, *, fixture=False):
        validate_config(config)
        require(fixture is True, 'actual Docker/KVM runtime remains unqualified')
        self.directory = Path(directory)
        self.directory.mkdir(mode=0o700)
        backend = self.directory / 'backend'
        backend.mkdir(mode=0o700)
        self.owner = owner_factory(backend)
        self.config, self.fixture = dict(config), True
        self.name, self.cmd = RUNNER_NAME, self
        self.receipt, self.process, self.boot = None, None, None
        self.generation, self.baseline = 0, False
        self.outstanding, self.qualified = None, False

    def save(self, phase):
        record = {'phase': phase, 'fixture': True, 'runtime_qualification': 'UNQUALIFIED',
                  'repository': REPO, 'runner_name': RUNNER_NAME, 'outer': self.receipt,
                  'baseline_image': self.config['runtime_image'], 'context_seal': self.config['baseline_context_seal'],
                  'outstanding_runner_id': self.outstanding, 'boot_id': self.boot,
                  'listener': {'pid': self.process.pid, 'reaped': self.process.reaped} if self.process else None}
        fd = os.open(self.directory / 'supervisor-receipt.json', os.O_CREAT | os.O_TRUNC | os.O_WRONLY | os.O_NOFOLLOW, 0o600)
        with os.fdopen(fd, 'w') as output:
            json.dump(record, output)
            output.write('\n')

    def identity(self):
        require(self.receipt is not None and self.receipt['phase'] != 'removed', 'no active outer creation receipt')
        info = self.owner.inspect(self.receipt['id'])
        q2.validate(info, self.receipt)
        require(info['State']['Running'] is True, 'owned VM is not running')
        return info

    def execute(self, script, stdin='', capture=True):
        self.identity()
        return self.owner.guest(self.receipt, script, stdin, capture=capture)

    def clean(self):
        measured = json.loads(self.execute('python3 -', q2.FRESH))
        require(measured.get('fresh') is True and isinstance(measured.get('boot_id'), str), 'fresh guest proof absent')
        require(self.boot is None or self.boot == measured['boot_id'], 'unexpected guest reboot')
        self.boot = measured['boot_id']
        require(self.execute('runuser -u runner -- /opt/actions-runner/bin/Runner.Listener --version').strip() == '2.336.0', 'runner payload version changed')
        self.execute(v2.CLEAN)

    def create(self):
        require(self.outstanding is None and (self.receipt is None or self.receipt['phase'] == 'removed'), 'active identity prevents creation')
        require(isinstance(self.config['runtime_image'], str) and self.config['baseline_context_seal'], 'sealed runner baseline is still absent')
        self.qualified = False
        suffix = 'life-q1' if self.generation % 2 == 0 else 'life-q2'
        self.receipt = self.owner.create(self.config['runtime_image'], 'lifecycle', suffix)
        self.generation += 1
        self.boot = None
        self.save('outer-created')
        self.owner.ready(self.receipt)
        self.clean()

    def prepare_baseline(self):
        require(not self.baseline and self.outstanding is None, 'baseline already active/unresolved')
        self.clean()
        self.baseline, self.qualified = True, True
        self.save('fixture-sealed-baseline-ready')

    def publish(self):
        require(self.baseline and self.qualified and self.outstanding is None, 'unqualified publication refused')
        self.clean()
        # This is the new local runner name, never a foreign fleet slot alias.
        script = ('set -eu; test -d /run; test ! -L /run; test ! -L /run/myguard-ci-slot; '
                  'test -d /opt/actions-runner; test ! -L /opt/actions-runner; '
                  't=$(mktemp /run/.myguard-ci-slot.XXXXXXXX); trap \'rm -f "$t"\' EXIT; '
                  'printf "%s\\n%s\\n" ' + RUNNER_NAME + ' /opt/actions-runner >"$t"; '
                  'chown 0:0 "$t"; chmod 0444 "$t"; mv -fT "$t" /run/myguard-ci-slot')
        self.execute(script, capture=False)
        self.save('fixture-slot-metadata-published')

    def bridge(self, request):
        self.identity()
        source = (ROOT / 'listener_bridge.py').read_text()
        digest = hashlib.sha256(source.encode()).hexdigest()
        require(digest == BRIDGE_SHA, 'listener helper source changed')
        # Public helper code is integrity-bound; private payload remains stdin.
        bootstrap = ("import hashlib,json,sys\ntry:\n d=json.loads(sys.stdin.buffer.read(1100000));"
                     "s=d['helper'];assert hashlib.sha256(s.encode()).hexdigest()=='" + digest + "';"
                     "n={};exec(compile(s,'<private-bridge>','exec'),n);print(json.dumps(n['main'](d['request'])))\n"
                     "except Exception:\n print('{\"error\":\"listener bridge unresolved\"}');sys.exit(1)\n")
        envelope = json.dumps({'helper': source, 'request': request})
        result = self.owner.act(self.receipt, ['exec', '-i'], ['/usr/bin/python3', '-c', bootstrap], data=envelope)
        reply = json.loads(result.stdout)
        require('error' not in reply, 'listener bridge unresolved')
        return reply

    def listener(self, receipt, payload):
        require(receipt == self.receipt and type(self.outstanding) is int,
                'listener creation/runner identity mismatch')
        require(self.process is None or self.process.reaped, 'listener already active')
        result = self.bridge({'mode': 'start', 'payload': payload})
        self.process = Listener(self, result['pid'])
        self.save('fixture-listener-started')
        return self.process

    def reset(self):
        require(self.baseline and self.outstanding is None, 'unresolved backend blocks reset')
        require(self.process is None or self.process.reaped, 'unreaped listener blocks reset')
        previous_id, previous_boot = self.receipt['id'], self.boot
        self.qualified = False
        self.owner.finish(self.receipt)
        self.process = None
        self.create()
        require(self.receipt['id'] != previous_id and self.boot != previous_boot, 'pristine identity did not change')
        self.qualified = True
        self.save('fixture-pristine-replacement-ready')

    def remove(self):
        require(self.outstanding is None and (self.process is None or self.process.reaped), 'unresolved identity prevents removal')
        self.owner.finish(self.receipt)
        self.qualified = False
        self.save('outer-removed')


class Supervisor(SupervisorBase):
    def __init__(self, guest, api):
        require(isinstance(api, RepoAPI) and api.guard == guest.identity, 'repository API guard is not this owned guest')
        super().__init__(guest, api)

    def begin(self, group, proof_label):
        require(self.guest.outstanding is None, 'unresolved identity prevents mint')
        require(re.fullmatch(r'vi-local-proof-[a-z0-9-]{8,60}', proof_label), 'invalid proof capability')
        self.guest.publish()
        # Own local runner never claims a foreign physical builder slot.
        labels = ['self-hosted', 'Linux', 'X64', 'vimbadmin-owned-local-v1', 'docker', 'jit-env-input', proof_label]
        self.guest.qualified = False
        self.guest.outstanding = 'mint-pending'
        self.guest.save('mint-pending')
        rid, payload = self.api.mint(self.guest.name, group, labels)
        self.guest.outstanding = rid
        self.guest.save('JIT-created')
        self.guest.identity()
        process = self.guest.listener(dict(self.guest.receipt), payload)
        del payload
        return rid, process


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--validate-config', action='store_true')
    parser.add_argument('--run', action='store_true')
    args = parser.parse_args()
    require(not args.run, 'actual backend unqualified; live enablement requires qualified local route')
    require(args.validate_config, 'only configuration validation is enabled')
    validate_config(json.loads((ROOT / 'config.json').read_text()))
    print(json.dumps({'repository': REPO, 'runner_name': RUNNER_NAME, 'runtime': 'UNQUALIFIED', 'external_enabled': False}))


if __name__ == '__main__':
    main()
