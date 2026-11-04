"""Bounded owned VM provision/lifecycle runner; no registration or host credentials."""
import argparse
import hashlib
import json
import os
import re
import subprocess
import time
from pathlib import Path

PROJECT = 'vimbadmin'
ROOT = Path(__file__).resolve().parent
PREFIX = 'vi-local-ci-208c2d13-vm-q2-'
OUTER_WORK = '/work/vi-local-ci-208c2d13-vm-q2'
ALPINE = 'sha256:b92841763c5c2140a20eeb2a44e94ebb0e80fd51d9d6b5895ea72fa999525d68'
ALPINE_SHA = '2003971c6efd8d91676f9527ee06c561fe4019350324941e8afdc5608d81ed5f'

FRESH = r'''import json,os,subprocess,urllib.request
def d(*args):
 return subprocess.check_output(['runuser','-u','runner','--','docker',*args],text=True).strip()
info=json.loads(d('info','--format','{{json .}}'))
assert info['Containers']==0 and info['Images']==0
for path in ['/var/lib/local-ci/work','/var/lib/local-ci/sessions']:
 assert os.stat(path).st_mode&0o777==0o700 and not os.listdir(path)
for path in ['/root/.docker/config.json','/root/.config/gh/hosts.yml','/etc/myguard-build-env','/var/lib/local-ci/dirty']:
 assert not os.path.exists(path)
assert not any(os.scandir('/run/credentials')) if os.path.exists('/run/credentials') else True
assert not any(any(t in k.upper() for t in ['SECRET','TOKEN','PASSWORD','CREDENTIAL']) for k in os.environ)
assert not any(t in open('/proc/self/mountinfo').read() for t in [' - 9p ',' - virtiofs '])
for endpoint in ['https://github.com','https://api.github.com']:
 assert urllib.request.urlopen(endpoint,timeout=15).status==200
print(json.dumps({'boot_id':open('/proc/sys/kernel/random/boot_id').read().strip(),'docker_server':d('version','--format','{{.Server.Version}}'),'fresh':True,'env_names':sorted(os.environ)}))
'''

LIFECYCLE = FRESH + r'''
import hashlib
archive='/opt/public-fixtures/alpine.tar'
assert hashlib.sha256(open(archive,'rb').read()).hexdigest()=='2003971c6efd8d91676f9527ee06c561fe4019350324941e8afdc5608d81ed5f'
d('load','-i',archive)
image='sha256:b92841763c5c2140a20eeb2a44e94ebb0e80fd51d9d6b5895ea72fa999525d68'
name='vi-local-ci-208c2d13-vm-q2-child-q1'
child=d('create','--name',name,'--label','myguard.run='+name,'--label','myguard.project=vimbadmin',image,'sh','-c','sleep 300')
assert len(child)==64 and all(x in '0123456789abcdef' for x in child)
def own():
 x=json.loads(d('inspect',child))[0]
 assert x['Id']==child and x['Name']=='/'+name
 assert x['Config']['Labels']['myguard.run']==name and x['Config']['Labels']['myguard.project']=='vimbadmin'
 return x
own();d('start',child)
pid=own()['State']['Pid']
own();d('stop','--time','1',child)
assert int(d('wait',child)) in (137,143)
assert not own()['State']['Running'] and not os.path.exists('/proc/'+str(pid))
own();d('rm',child)
d('image','rm',image)
assert json.loads(d('info','--format','{{json .}}'))['Containers']==0
assert json.loads(d('info','--format','{{json .}}'))['Images']==0
open('/var/lib/local-ci/dirty','w').write('owned-public-reset-marker')
print(json.dumps({'child_id':child,'child_cancelled_reaped_removed':True,'dirty_marker_written':True}))
'''


class Refused(RuntimeError):
    pass


def validate(info, receipt):
    h, c = info['HostConfig'], info['Config']
    checks = [info['Id'] == receipt['id'], info['Name'] == '/' + receipt['name'],
              receipt['name'].startswith(PREFIX), receipt['runlabel'] == receipt['name'], receipt['project'] == PROJECT,
              c.get('Labels', {}).get('myguard.run') == receipt['runlabel'],
              c.get('Labels', {}).get('myguard.project') == PROJECT,
              info['Image'] == receipt['image'], c['Image'] == receipt['image'],
              not info.get('Mounts'), not h.get('Binds'), not h.get('VolumesFrom'),
              not h.get('Privileged'), not h.get('CapAdd'), h.get('CapDrop') == ['ALL'],
              h.get('SecurityOpt') == ['no-new-privileges:true'], h.get('NetworkMode') == 'bridge',
              h.get('Memory') == 3221225472, h.get('NanoCpus') == 2000000000, h.get('PidsLimit') == 256,
              h.get('Devices') == [{'PathOnHost': '/dev/kvm', 'PathInContainer': '/dev/kvm', 'CgroupPermissions': 'rw'}],
              not h.get('DeviceRequests'), not h.get('DeviceCgroupRules'), not h.get('PortBindings'),
              h.get('IpcMode') == 'private', not h.get('PidMode'), not h.get('UTSMode'), not h.get('UsernsMode'),
              not h.get('Sysctls'), not c.get('Volumes'),
              all(x.split('=', 1)[0] == 'PATH' for x in c.get('Env', []))]
    if not all(checks):
        raise Refused('immutable ID/ownership/security envelope mismatch')


class Owned:
    def __init__(self, directory):
        self.directory = directory
        self.config = directory / 'empty-docker-config'
        self.config.mkdir(mode=0o700)
        self.receipts = []

    def command(self, args, data=None, check=True):
        result = subprocess.run(['docker', '--config', str(self.config), *args], input=data,
                                capture_output=True, text=True, timeout=210,
                                env={'PATH': os.defpath}, check=False)
        if check and result.returncode:
            raise Refused('owned Docker ' + args[0] + ' failed; request/output not logged')
        return result

    def save(self):
        (self.directory / 'receipts.json').write_text(json.dumps(self.receipts, indent=2) + '\n')

    def inspect(self, identity):
        rows = json.loads(self.command(['inspect', '--type', 'container', identity]).stdout)
        if len(rows) != 1:
            raise Refused('ambiguous immutable ID')
        return rows[0]

    def create(self, image, phase, suffix):
        if not re.fullmatch('sha256:[0-9a-f]{64}', image) or phase not in ('provision', 'lifecycle') or suffix not in ('provision-q1', 'life-q1', 'life-q2'):
            raise Refused('unreviewed image/phase/name')
        image_info = json.loads(self.command(['image', 'inspect', image]).stdout)[0]
        c = image_info['Config']
        if (image_info['Id'] != image or c.get('Volumes') or c.get('Labels', {}).get('myguard.project') != PROJECT
                or any(e.split('=', 1)[0] != 'PATH' for e in c.get('Env', []))):
            raise Refused('unqualified image envelope')
        name = PREFIX + suffix
        if name in self.command(['container', 'ls', '-a', '--format', '{{.Names}}']).stdout.splitlines():
            raise Refused('literal name preexists')
        identity = self.command(['create', '--pull=never', '--name', name, '--label', 'myguard.run=' + name,
                                 '--label', 'myguard.project=' + PROJECT, '--device', '/dev/kvm:/dev/kvm:rw',
                                 '--cap-drop', 'ALL', '--security-opt', 'no-new-privileges:true',
                                 '--cpus', '2', '--memory', '3g', '--pids-limit', '256', '--network', 'bridge',
                                 '--ipc', 'private', '--entrypoint', '/usr/bin/python3', image,
                                 '/opt/vm/outer.py', phase]).stdout.strip()
        if not re.fullmatch('[a-f0-9]{64}', identity):
            raise Refused('missing full creation ID; no opaque cleanup')
        receipt = {'id': identity, 'name': name, 'runlabel': name, 'project': PROJECT, 'image': image, 'phase': 'created'}
        self.receipts.append(receipt)
        self.save()
        validate(self.inspect(identity), receipt)
        self.act(receipt, ['start'])
        return receipt

    def act(self, receipt, before, after=(), data=None):
        if receipt not in self.receipts or receipt['phase'] == 'removed':
            raise Refused('unowned/retired receipt')
        validate(self.inspect(receipt['id']), receipt)
        return self.command([*before, receipt['id'], *after], data=data)

    def guest_response(self, receipt, script, stdin='', capture=True):
        request = json.dumps({'script': script, 'stdin': stdin, 'capture': capture})
        response = self.act(receipt, ['exec', '-i'], ['/usr/bin/python3', '/opt/vm/qga.py'], request)
        return json.loads(response.stdout)

    def guest(self, receipt, script, stdin='', capture=True):
        result = self.guest_response(receipt, script, stdin, capture)
        if result.get('exitcode') != 0 or result.get('signal'):
            raise Refused('guest request failed/unresolved; no secret transcript retained')
        return result.get('out-data', '') if capture else ''

    def ready(self, receipt):
        deadline = time.monotonic() + 180
        while time.monotonic() < deadline:
            try:
                self.guest(receipt, 'true')
                return
            except Refused:
                if not self.inspect(receipt['id'])['State']['Running']:
                    raise Refused('owned VM stopped during readiness') from None
                time.sleep(1)
        raise Refused('agent readiness unresolved; retain exact owned resource')

    def finish(self, receipt, export=False):
        self.guest(receipt, "nohup sh -c 'sleep 1; poweroff' >/dev/null 2>&1 &")
        if self.act(receipt, ['wait']).stdout.strip() != '0':
            raise Refused('outer/QEMU did not exit cleanly')
        info = self.inspect(receipt['id'])
        if info['State']['Running'] or info['State']['ExitCode'] != 0:
            raise Refused('outer still running/failed')
        if export:
            validate(info, receipt)
            destination = self.directory / 'baseline.qcow2'
            if destination.exists():
                raise Refused('export destination preexists')
            # docker cp syntax requires the immutable ID in its source operand.
            self.command(['cp', receipt['id'] + ':' + OUTER_WORK + '/sealed.qcow2', str(destination)])
            (self.directory / 'baseline.sha256').write_text(hashlib.sha256(destination.read_bytes()).hexdigest() + '\n')
        self.act(receipt, ['rm'])
        receipt['phase'] = 'removed'
        self.save()
        missing = self.command(['inspect', '--type', 'container', receipt['id']], check=False)
        if missing.returncode == 0 or 'No such' not in missing.stderr:
            raise Refused('cleanup absence not established')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('stage', choices=('provision', 'lifecycle'))
    parser.add_argument('--execute-reviewed-stage', action='store_true')
    image_group = parser.add_mutually_exclusive_group(required=True)
    image_group.add_argument('--image')
    image_group.add_argument('--built-image', choices=('provision', 'runtime'))
    parser.add_argument('--receipt-dir', type=Path, required=True)
    args = parser.parse_args()
    if not args.execute_reviewed_stage:
        raise Refused('reviewed stage flag required')
    if args.built_image:
        context = ROOT / ('build-' + args.built_image + '-q1')
        image_receipt = json.loads((ROOT / (context.name + '-image.json')).read_text())
        if (image_receipt['project'] != PROJECT
                or image_receipt['context_seal_sha256'] != hashlib.sha256((context / 'context-seal.json').read_bytes()).hexdigest()):
            raise Refused('own build receipt/context seal mismatch')
        args.image = image_receipt['image']
    args.receipt_dir.mkdir(mode=0o700)
    owner = Owned(args.receipt_dir)
    if args.stage == 'provision':
        receipt = owner.create(args.image, 'provision', 'provision-q1')
        owner.ready(receipt)
        owner.guest(receipt, 'cloud-init status --wait >/dev/null; test -f /var/lib/local-ci/provisioned')
        fresh = owner.guest(receipt, 'python3 -', FRESH)
        (args.receipt_dir / 'fresh.json').write_text(fresh)
        owner.finish(receipt, export=True)
    else:
        first = owner.create(args.image, 'lifecycle', 'life-q1')
        owner.ready(first)
        first_proof = owner.guest(first, 'python3 -', LIFECYCLE)
        # Dirty-state refusal must be observed before replacing the guest.
        dirty = owner.guest_response(first, 'python3 -', FRESH)
        if dirty.get('exitcode') != 1 or dirty.get('signal') or 'AssertionError' not in dirty.get('err-data', ''):
            raise Refused('dirty guest falsely accepted')
        owner.finish(first)
        second = owner.create(args.image, 'lifecycle', 'life-q2')
        owner.ready(second)
        second_proof = owner.guest(second, 'python3 -', FRESH)
        before = json.loads(first_proof.splitlines()[0])
        after = json.loads(second_proof)
        if first['id'] == second['id'] or before['boot_id'] == after['boot_id']:
            raise Refused('guest/container identity not fresh')
        # Capture-disabled synthetic input uses private anonymous stdin only.
        owner.guest(second, "read value; test \"$value\" = 'owned-public-stdin-control'", 'owned-public-stdin-control\n', capture=False)
        owner.finish(second)
        (args.receipt_dir / 'lifecycle.json').write_text(json.dumps({'first': first_proof, 'fresh': after, 'dirty_rejected': True, 'stdin_capture_disabled': True, 'cleanup': 'both removed'}, indent=2) + '\n')


if __name__ == '__main__':
    main()
