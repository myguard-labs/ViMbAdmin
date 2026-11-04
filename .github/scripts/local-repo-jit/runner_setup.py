"""Prepare a separate public-input runner seed; no download or runtime action."""
import argparse
import errno
import hashlib
import json
import os
import re
import shutil
import subprocess
import tarfile
from pathlib import Path

import baseline_budget as budget

ROOT = Path(__file__).resolve().parent
RUN = 'vi-local-ci-208c2d13-v4-capacity-r3-image-q1'
WORK = '/work/vi-local-ci-208c2d13-v4-capacity-r3-q1'
GIB = 1073741824
Q2 = None  # Public input bank supplied explicitly; never a repo/private-path import.
Q2_MANIFEST = 'ee81956ba51a16c083f47fcc23c502575a6fb18c673cb039e9f22267a4837284'
ARCHIVE = 'actions-runner-linux-x64-2.336.0.tar.gz'
ARCHIVE_SHA = '04cf0be1aff4c3ec3554466c39124ca250e3effd8873bb7e8d68535aa9505d5d'
URL = 'https://github.com/actions/runner/releases/download/v2.336.0/' + ARCHIVE
FILES = {'run.sh': 'b39d7e0ca921a3189f7fe4e0a2f686b46719d4ccc2647f156f14407ec4517e8f',
         'bin/Runner.Listener': 'b73c247aa9f0b4198aeb00ae924b7b137c0a717e5dc1066919a80fa4876fdda4',
         'bin/Runner.Listener.dll': 'ae306d39005112b465026b6afd9f6d2c6c59684b38c4394323a9ffa4634fa544',
         'bin/Runner.Common.dll': '4507ccfd86aefc0c2e246ed17be2e5a0be771dbc435083da53e56d8ba717bc9f'}

INSTALL = r'''import hashlib,json,os,pathlib,pwd,shutil,subprocess,tarfile
def digest(path):
 with path.open('rb') as stream:return hashlib.file_digest(stream,'sha256').hexdigest()
archive=pathlib.Path('/mnt/actions-runner-linux-x64-2.336.0.tar.gz')
assert digest(archive)=='04cf0be1aff4c3ec3554466c39124ca250e3effd8873bb7e8d68535aa9505d5d'
target=pathlib.Path('/opt/actions-runner')
assert not target.exists() and not target.is_symlink(),'preexisting runner path refused'
target.mkdir(mode=0o755)
with tarfile.open(archive) as payload:payload.extractall(target,filter='data')
expected=__FILE_HASHES__
for relative,fingerprint in expected.items():
 assert digest(target/relative)==fingerprint,'runner payload hash changed'
account=pwd.getpwnam('runner');assert account.pw_uid!=0 and account.pw_dir=='/home/runner'
for path in [target,*target.rglob('*')]:shutil.chown(path,account.pw_uid,account.pw_gid)
for path in [target/'_work',pathlib.Path('/home/runner/.local/state/vimbadmin-ci-sessions')]:
 assert not path.is_symlink()
 path.mkdir(mode=0o700,parents=True,exist_ok=True);os.chmod(path,0o700);shutil.chown(path,account.pw_uid,account.pw_gid)
 assert not any(path.iterdir())
for name in ['.runner','.credentials','.credentials_rsaparams']:
 assert not (target/name).exists(),'registration state in baseline refused'
for path in ['/etc/myguard-build-env','/etc/ci-gh-token','/root/.docker/config.json','/home/runner/.docker/config.json']:
 assert not pathlib.Path(path).exists(),'host credential path found'
# Source-proven selectors and default PAL optional-dlopen semantics, no feature disable.
import sys
policy_dir=pathlib.Path('/opt/local-ci-inputs')
assert not policy_dir.exists() and not policy_dir.is_symlink(),'preexisting native policy directory refused'
policy_dir.mkdir(mode=0o755)
for name in ['native_policy.py','native-inputs.json']:
 destination=policy_dir/name;shutil.copyfile(pathlib.Path('/mnt')/name,destination);os.chown(destination,0,0);os.chmod(destination,0o444)
sys.path.insert(0,str(policy_dir))
import native_policy
native_proof=native_policy.verify(target)
version=subprocess.check_output(['runuser','-u','runner','--',str(target/'bin/Runner.Listener'),'--version'],text=True).strip()
assert version=='2.336.0'
info=json.loads(subprocess.check_output(['runuser','-u','runner','--','docker','info','--format','{{json .}}'],text=True))
assert info['Containers']==0 and info['Images']==0
slot=pathlib.Path('/run/myguard-ci-slot');assert not slot.is_symlink()
slot.write_text('vi-local-ci-208c2d13-01\n/opt/actions-runner\n');os.chown(slot,0,0);os.chmod(slot,0o444)
assert slot.stat().st_uid==0 and slot.stat().st_gid==0 and slot.stat().st_mode&0o777==0o444
assert slot.read_text().splitlines()==['vi-local-ci-208c2d13-01','/opt/actions-runner']
pathlib.Path('/var/lib/local-ci/runner-inputs-ready').write_text(json.dumps({'version':version,'runner_name':'vi-local-ci-208c2d13-01','registered':False,'native':native_proof})+'\n')
'''.replace('__FILE_HASHES__', repr(FILES))


def sha(path):
    with path.open('rb') as stream:
        return hashlib.file_digest(stream, 'sha256').hexdigest()


def replace(text, old, new):
    if text.count(old) != 1:
        raise ValueError('reviewed capacity context preimage changed')
    return text.replace(old, new)


def host_ready(record, filesystem=os.statvfs, directory=ROOT):
    required = 3 * budget.VIRTUAL_BYTES + GIB + 2 * record['source_logical_bytes']
    free = filesystem(directory)
    if free.f_bavail * free.f_frsize < required:
        raise ValueError('host capacity budget unproved')
    return required


def verify_materialized(directory):
    seal = directory / 'context-seal.json'
    expected = json.loads(seal.read_text())
    paths = list(directory.rglob('*'))
    if ({str(path.relative_to(directory)) for path in paths if path.is_file() and path != seal} != set(expected)
            or any(path.is_symlink() for path in paths)
            or any(sha(directory / name) != digest for name, digest in expected.items())):
        raise ValueError('sealed build context drift')
    for name in ('owned_overlay.py', 'baseline_budget.py', 'guest_capacity.py', 'failure_observation.py'):
        if sha(directory / name) != sha(ROOT / name):
            raise ValueError('maintained capacity helper drift')
    if sha(directory / 'iso/guest_capacity.py') != sha(ROOT / 'guest_capacity.py'):
        raise ValueError('guest capacity helper drift')


def capacity_sources(directory):
    """All coupled paths change together, preserving default runtime confinement."""
    outer = (directory / 'outer.py').read_text()
    outer = replace(outer, '/work/vi-local-ci-208c2d13-vm-q2', WORK)
    start = "subprocess.run(['qemu-img', 'create', '-f', 'qcow2', '-F', 'qcow2', '-b', str(root / 'base.qcow2'), str(work / 'owned.qcow2')], check=True, stdout=subprocess.DEVNULL)"
    outer = replace(outer, start, "from owned_overlay import fresh_overlay\nfresh_overlay(root / 'base.qcow2', expected, work)")
    outer = replace(outer, "'-serial', 'null'", "'-chardev', 'ringbuf,id=ci-serial,size=65536', '-serial', 'chardev:ci-serial'")
    outer += "\nfrom owned_overlay import standalone\nstandalone(work / 'sealed.qcow2')\n"
    qga = replace((directory / 'qga.py').read_text(), '/work/vi-local-ci-208c2d13-vm-q2', WORK)
    config = json.loads((directory / 'iso/user-data').read_text().split('\n', 1)[1])
    config['growpart'] = {'mode': 'auto', 'devices': ['/'], 'ignore_growroot_disabled': False}
    config['resize_rootfs'] = True
    provision = '''import os,sys
sys.path.insert(0,"/mnt")
import guest_capacity
def boundary(name,event,exception='none'):
 text='MYGUARD_CI_BOUNDARY:'+name+':'+event+':'+exception+'\\n'
 print(text,end='',flush=True)
 try:
  descriptor=os.open('/dev/ttyS0',os.O_WRONLY|os.O_NOCTTY|os.O_NONBLOCK)
  try:return os.write(descriptor,text.encode())==len(text)
  finally:os.close(descriptor)
 except OSError:return False
boundary('root_ready','start')
try:
 guest_capacity.root_ready()
except Exception as error:
 boundary('root_ready','fail',type(error).__name__)
 raise
boundary('root_ready','pass')
boundary('provision','start')
try:
'''
    provision += ''.join(' ' + line + '\n' for line in (directory / 'iso/provision.py').read_text().splitlines())
    provision += '''except Exception as error:
 boundary('provision','fail',type(error).__name__)
 raise
boundary('provision','pass')
'''
    installer = (directory / 'iso/install-runner.py').read_text()
    installer = replace(installer, 'target.mkdir(mode=0o755)',
                        'import sys\nsys.path.insert(0,"/mnt")\nimport guest_capacity\ncapacity_proof=guest_capacity.admit(archive,target)\ntarget.mkdir(mode=0o755)')
    installer = replace(installer, "with tarfile.open(archive) as payload:payload.extractall(target,filter='data')",
                        "with tarfile.open(archive) as payload:payload.extractall(target,filter='data')\nguest_capacity.verify_extracted(archive,target)")
    installer += "\npathlib.Path('/var/lib/local-ci/runner-capacity.json').write_text(json.dumps(capacity_proof)+'\\n')\n"
    dockerfile = replace((directory / 'Dockerfile').read_text(), 'vi-local-ci-208c2d13-vm-jit-inputs-v3', RUN)
    dockerfile = replace(dockerfile, 'base.sha256 outer.py qga.py seed.iso', 'base.sha256 outer.py qga.py owned_overlay.py baseline_budget.py guest_capacity.py failure_observation.py seed.iso')
    return {'outer.py': outer, 'qga.py': qga, 'Dockerfile': dockerfile,
            'iso/user-data': '#cloud-config\n' + json.dumps(config) + '\n',
            'iso/provision.py': provision, 'iso/install-runner.py': installer,
            'iso/meta-data': 'instance-id: vi-local-ci-208c2d13-v4-capacity-r3-q1\nlocal-hostname: owned-local-vm\n',
            'iso/guest_capacity.py': (ROOT / 'guest_capacity.py').read_text(),
            'owned_overlay.py': (ROOT / 'owned_overlay.py').read_text(),
            'baseline_budget.py': (ROOT / 'baseline_budget.py').read_text(),
            'guest_capacity.py': (ROOT / 'guest_capacity.py').read_text(),
            'failure_observation.py': (ROOT / 'failure_observation.py').read_text()}

def stage(archive, directory):
    if Q2 is None:
        raise ValueError('explicit public input directory required')
    if sha(Q2 / 'manifest.json') != Q2_MANIFEST or sha(archive) != ARCHIVE_SHA:
        raise ValueError('frozen q2 or pinned runner archive changed')
    # Verify known measured payload hashes without extracting to the host.
    with tarfile.open(archive) as payload:
        members = {member.name.removeprefix('./'): member for member in payload.getmembers()}
        for relative, digest in FILES.items():
            stream = payload.extractfile(members[relative])
            if stream is None or hashlib.file_digest(stream, 'sha256').hexdigest() != digest:
                raise ValueError('runner payload fingerprint changed')
    source = Q2 / 'build-provision-q1'
    manifest = json.loads((Q2 / 'manifest.json').read_text())
    if sha(source / 'context-seal.json') != manifest['build-provision-q1/context-seal.json']['sha256']:
        raise ValueError('frozen context seal changed')
    seal = json.loads((source / 'context-seal.json').read_text())
    if any(sha(source / name) != digest for name, digest in seal.items()):
        raise ValueError('frozen q2 context changed')
    host_ready({'source_logical_bytes': sum((source / name).stat().st_size for name in seal) + archive.stat().st_size}, directory=directory.parent)
    directory.mkdir(mode=0o700)
    for name in seal:
        if name == 'seed.iso':
            continue
        destination = directory / name
        destination.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
        if name in ('iso/provision.py', 'Dockerfile', 'outer.py', 'qga.py', 'iso/user-data', 'iso/meta-data'):
            shutil.copyfile(source / name, destination)
        else:
            try:
                os.link(source / name, destination)
            except OSError as error:
                if error.errno != errno.EXDEV:
                    raise
                with (source / name).open('rb') as stream, destination.open('xb') as output:
                    shutil.copyfileobj(stream, output)
                shutil.copymode(source / name, destination)
                if sha(destination) != seal[name]:
                    raise ValueError('copied public input fingerprint changed')
    shutil.copyfile(archive, directory / 'iso' / ARCHIVE)
    (directory / 'iso/install-runner.py').write_text(INSTALL)
    for name in ('native_policy.py', 'native-inputs.json'):
        shutil.copyfile(ROOT / name, directory / 'iso' / name)
    provision = directory / 'iso/provision.py'
    original = provision.read_text()
    marker = "pathlib.Path('/var/lib/local-ci/provisioned').touch()"
    if original.count(marker) != 1:
        raise ValueError('q2 provisioning prerequisite marker changed')
    provision.write_text(original.replace(marker, "subprocess.run(['python3','/mnt/install-runner.py'],check=True)\n" + marker))
    dockerfile = directory / 'Dockerfile'
    dockerfile.write_text(dockerfile.read_text().replace('vi-local-ci-208c2d13-vm-image-q2', 'vi-local-ci-208c2d13-vm-jit-inputs-v3'))
    for name, text in capacity_sources(directory).items():
        target = directory / name
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(text)
    subprocess.run(['genisoimage', '-quiet', '-output', str(directory / 'seed.iso'), '-volid', 'cidata',
                    '-joliet', '-rock', str(directory / 'iso')], check=True, timeout=90, env={'PATH': os.defpath})
    if any(sha(source / name) != digest for name, digest in seal.items()):
        raise ValueError('verified public input changed during staging')
    files = sorted(x for x in directory.rglob('*') if x.is_file())
    (directory / 'context-seal.json').write_text(json.dumps({str(x.relative_to(directory)): sha(x) for x in files}, indent=2) + '\n')


def build(directory, command):
    """Future granted build, injected transport in tests; never reads host auth."""
    seal = directory / 'context-seal.json'
    expected = json.loads(seal.read_text())
    paths = list(directory.rglob('*'))
    if ({str(path.relative_to(directory)) for path in paths if path.is_file() and path != seal} != set(expected)
            or any(path.is_symlink() for path in paths)
            or any(sha(directory / name) != digest for name, digest in expected.items())):
        raise ValueError('sealed build context drift')
    verify_materialized(directory)
    private = directory.parent / (directory.name + '-build-receipts')
    private.mkdir(mode=0o700)
    config = private / 'empty-docker-config'
    config.mkdir(mode=0o700)
    iid = private / 'created-image.id'
    result = command(['docker', '--config', str(config), 'build', '--network=none', '--pull=false',
                      '--iidfile', str(iid), str(directory)], check=True, env={'PATH': os.defpath})
    identity = iid.read_text().strip()
    if result.returncode != 0 or not re.fullmatch('sha256:[0-9a-f]{64}', identity):
        raise ValueError('own build did not return an immutable image ID')
    (private / 'creation-receipt.json').write_text(json.dumps({'image': identity, 'project': 'vimbadmin',
        'runlabel': RUN, 'context_seal_sha256': sha(seal),
        'runner_archive_sha256': ARCHIVE_SHA, 'runner_version': '2.336.0', 'runtime_qualification': 'UNQUALIFIED'}, indent=2) + '\n')


def main():
    global Q2
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--describe-inputs', action='store_true')
    parser.add_argument('--stage-verified-public-seed', action='store_true')
    parser.add_argument('--build-verified-public-seed', action='store_true')
    parser.add_argument('--archive', type=Path)
    parser.add_argument('--public-input-dir', type=Path)
    parser.add_argument('--output-dir', type=Path)
    args = parser.parse_args()
    if args.output_dir is not None and args.output_dir.resolve().is_relative_to(ROOT.parents[2]):
        raise ValueError('public build inputs must stay outside the repository')
    if args.describe_inputs:
        print(json.dumps({'archive': ARCHIVE, 'url': URL, 'sha256': ARCHIVE_SHA,
                          'version': '2.336.0', 'local_archive_available': False, 'runtime': 'UNQUALIFIED'}))
        return
    if args.build_verified_public_seed:
        if args.stage_verified_public_seed or args.archive is not None or args.output_dir is None:
            raise ValueError('build stage accepts no alternate input')
        build(args.output_dir, subprocess.run)
        return
    if not args.stage_verified_public_seed or args.archive is None:
        raise ValueError('separate reviewed public-input stage required')
    if args.public_input_dir is None or args.output_dir is None:
        raise ValueError('explicit public input directory required')
    Q2 = args.public_input_dir
    stage(args.archive, args.output_dir)


if __name__ == '__main__':
    main()
