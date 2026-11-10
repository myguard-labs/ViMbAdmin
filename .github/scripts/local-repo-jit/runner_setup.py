"""Prepare a separate public-input runner seed; no download or runtime action."""
import argparse
import ast
import hashlib
import json
import os
import posixpath
import re
import shlex
import shutil
import stat
import subprocess
import tarfile
from pathlib import Path, PurePosixPath

import baseline_budget as budget
import request_model

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

# Only the six internal tool links shipped in the pinned 2.336.0 payload.
ARCHIVE_LINKS = {
    'externals/node' + version + '/bin/' + name: '../lib/node_modules/' + target
    for version in ('20', '24')
    for name, target in (('npm', 'npm/bin/npm-cli.js'), ('npx', 'npm/bin/npx-cli.js'),
                         ('corepack', 'corepack/dist/corepack.js'))
}

LEGACY_RUNNER = 'vi-local-ci-208c2d13-01'
SLOT_SCHEMA = 'vimbadmin-local-jit-slot-v1'
MAX_SLOT_BYTES = request_model.MAX_RECORD_BYTES + 1024


def slot_metadata(request=None):
    """Public placement data only; matching data never grants runtime authority."""
    if request is None:
        return LEGACY_RUNNER + '\n/opt/actions-runner\n'
    digest = request_model.request_digest(request)
    record = {'schema': SLOT_SCHEMA, 'request_sha256': digest, 'request': request}
    value = request['runner_name'] + '\n/opt/actions-runner\n' + json.dumps(
        record, sort_keys=True, separators=(',', ':'), ensure_ascii=True) + '\n'
    if len(value.encode('ascii')) > MAX_SLOT_BYTES:
        raise ValueError('published slot size refused')
    return value


def validate_slot(path, *, runner_name, candidate_sha, repository, run_id, run_attempt):
    """Read one bounded immutable placement record, never authority/admission state."""
    path = Path(path)
    if not path.is_absolute() or str(path) != str(path.resolve(strict=True)):
        raise ValueError('published slot path refused')
    descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    with os.fdopen(descriptor, 'rb') as stream:
        info = os.fstat(stream.fileno())
        if (not stat.S_ISREG(info.st_mode) or info.st_uid != 0 or info.st_gid != 0
                or stat.S_IMODE(info.st_mode) != 0o444 or info.st_size > MAX_SLOT_BYTES):
            raise ValueError('published slot metadata refused')
        raw = stream.read(MAX_SLOT_BYTES + 1)
    lines = raw.split(b'\n')
    if len(lines) != 4 or lines[-1] != b'':
        raise ValueError('published slot format refused')
    record = request_model.decode_record(lines[2])
    if set(record) != {'schema', 'request_sha256', 'request'} or record['schema'] != SLOT_SCHEMA:
        raise ValueError('published slot schema refused')
    request = record['request']
    if type(request) is not dict:
        raise ValueError('published slot request object required')
    expected = slot_metadata(request)
    if raw != expected.encode('ascii') or record['request_sha256'] != request_model.request_digest(request):
        raise ValueError('published slot request mismatch')
    if (request['runner_name'] != runner_name or request['candidate_sha'] != candidate_sha
            or request['repository'] != repository or str(request['run_id']) != run_id
            or str(request['run_attempt']) != run_attempt):
        raise ValueError('published slot workflow mismatch')
    return request_model.request_digest(request)


_INSTALL = r'''import hashlib,json,os,pathlib,pwd,shutil,subprocess,tarfile
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
slot=pathlib.Path('/run/myguard-ci-slot')
if slot.parent.resolve(strict=True)!=slot.parent:raise ValueError('slot parent path refused')
metadata=__SLOT_METADATA__
descriptor=os.open(slot,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
with os.fdopen(descriptor,'w') as stream:
 stream.write(metadata);stream.flush();os.fchown(stream.fileno(),0,0);os.fchmod(stream.fileno(),0o444)
if slot.stat().st_uid!=0 or slot.stat().st_gid!=0 or slot.stat().st_mode&0o7777!=0o444:raise ValueError('slot metadata refused')
if slot.read_text()!=metadata:raise ValueError('slot content changed')
pathlib.Path('/var/lib/local-ci/runner-inputs-ready').write_text(json.dumps({'version':version,'runner_name':__RUNNER_NAME__,'registered':False,'native':native_proof})+'\n')
'''.replace('__FILE_HASHES__', repr(FILES))


def installer_for(request=None):
    """Generate inert installer bytes from the same validated public slot contract."""
    metadata = slot_metadata(request)
    name = LEGACY_RUNNER if request is None else request['runner_name']
    return _INSTALL.replace('__SLOT_METADATA__', repr(metadata)).replace('__RUNNER_NAME__', repr(name))


INSTALL = installer_for()


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
    for name in ('owned_overlay.py', 'baseline_budget.py', 'public_recipe_selection.py', 'guest_capacity.py', 'failure_observation.py'):
        if sha(directory / name) != sha(ROOT / name):
            raise ValueError('maintained capacity helper drift')
    if sha(directory / 'iso/guest_capacity.py') != sha(ROOT / 'guest_capacity.py'):
        raise ValueError('guest capacity helper drift')
    plan = public_record(directory / 'source-preflight.json')
    _, _, _, actual = materialization_preflight(Path(plan['public_input_dir']), Path(plan['archive']), request=plan['request'])
    if plan != actual or any(sha(directory / name) != digest for name, digest in actual['closure'].items()):
        raise ValueError('source-bound generated context drift')


def capacity_sources(directory, *, texts=None, selection='historical'):
    """All coupled paths change together, preserving default runtime confinement."""
    def read(name):
        return (directory / name).read_text() if texts is None else texts[name]
    profile = budget.selected(selection)
    outer = read('outer.py')
    outer = replace(outer, '/work/vi-local-ci-208c2d13-vm-q2', WORK)
    start = "subprocess.run(['qemu-img', 'create', '-f', 'qcow2', '-F', 'qcow2', '-b', str(root / 'base.qcow2'), str(work / 'owned.qcow2')], check=True, stdout=subprocess.DEVNULL)"
    outer = replace(outer, start, "from owned_overlay import fresh_overlay\nfresh_overlay(root / 'base.qcow2', expected, work)")
    outer = replace(outer, "'-serial', 'null'", "'-chardev', 'ringbuf,id=ci-serial,size=65536', '-serial', 'chardev:ci-serial'")
    outer += "\nfrom owned_overlay import standalone\nstandalone(work / 'sealed.qcow2')\n"
    if selection != 'historical':
        base = next((ast.literal_eval(node.value) for node in ast.parse(outer).body
                     if isinstance(node, ast.Assign) and any(isinstance(t, ast.Name) and t.id == 'BASE_SHA256'
                                                            for t in node.targets)), None)
        if base != profile['base_sha256']:
            raise ValueError('capacity recipe/base selection mismatch')
        outer = replace(outer, 'VIRTUAL_BYTES = 6442450944', 'VIRTUAL_BYTES = ' + str(profile['virtual_bytes']))
        outer = replace(outer, "fresh_overlay(root / 'base.qcow2', expected, work)",
                        "fresh_overlay(root / 'base.qcow2', expected, work, selection=" + repr(selection) + ')')
        outer = replace(outer, "standalone(work / 'sealed.qcow2')",
                        "standalone(work / 'sealed.qcow2', selection=" + repr(selection) + ')')
    qga = replace(read('qga.py'), '/work/vi-local-ci-208c2d13-vm-q2', WORK)
    config = json.loads(read('iso/user-data').split('\n', 1)[1])
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
    provision += ''.join(' ' + line + '\n' for line in read('iso/provision.py').splitlines())
    provision += '''except Exception as error:
 boundary('provision','fail',type(error).__name__)
 raise
boundary('provision','pass')
'''
    installer = read('iso/install-runner.py')
    installer = replace(installer, 'target.mkdir(mode=0o755)',
                        'import sys\nsys.path.insert(0,"/mnt")\nimport guest_capacity\ncapacity_proof=guest_capacity.admit(archive,target)\ntarget.mkdir(mode=0o755)')
    installer = replace(installer, "with tarfile.open(archive) as payload:payload.extractall(target,filter='data')",
                        "with tarfile.open(archive) as payload:payload.extractall(target,filter='data')\nguest_capacity.verify_extracted(archive,target)")
    installer += "\npathlib.Path('/var/lib/local-ci/runner-capacity.json').write_text(json.dumps(capacity_proof)+'\\n')\n"
    dockerfile = replace(read('Dockerfile'), 'vi-local-ci-208c2d13-vm-jit-inputs-v3', RUN)
    dockerfile = replace(dockerfile, 'base.sha256 outer.py qga.py seed.iso', 'base.sha256 outer.py qga.py owned_overlay.py baseline_budget.py public_recipe_selection.py guest_capacity.py failure_observation.py seed.iso')
    return {'outer.py': outer, 'qga.py': qga, 'Dockerfile': dockerfile,
            'iso/user-data': '#cloud-config\n' + json.dumps(config) + '\n',
            'iso/provision.py': provision, 'iso/install-runner.py': installer,
            'iso/meta-data': 'instance-id: vi-local-ci-208c2d13-v4-capacity-r3-q1\nlocal-hostname: owned-local-vm\n',
            'iso/guest_capacity.py': (ROOT / 'guest_capacity.py').read_text(),
            'owned_overlay.py': (ROOT / 'owned_overlay.py').read_text(),
            'baseline_budget.py': (ROOT / 'baseline_budget.py').read_text(),
            'public_recipe_selection.py': (ROOT / 'public_recipe_selection.py').read_text(),
            'guest_capacity.py': (ROOT / 'guest_capacity.py').read_text(),
            'failure_observation.py': (ROOT / 'failure_observation.py').read_text()}

def regular_input(path):
    """Reject lexical aliases and special files before opening public inputs."""
    path = Path(path)
    if not path.is_absolute() or path != path.resolve(strict=True) or not path.is_file():
        raise ValueError('public input must be a canonical regular file: ' + str(path))
    return path


def public_record(path):
    def unique(pairs):
        result = {}
        for key, value in pairs:
            if key in result:
                raise ValueError('duplicate public manifest key')
            result[key] = value
        return result
    if regular_input(path).stat().st_size > 1024 * 1024:
        raise ValueError('public manifest size refused')
    value = json.loads(path.read_text(), object_pairs_hook=unique)
    if type(value) is not dict or not value:
        raise ValueError('nonempty public manifest required')
    return value


def relative_input(name):
    if (not isinstance(name, str) or not name or name != str(PurePosixPath(name))
            or PurePosixPath(name).is_absolute() or '..' in PurePosixPath(name).parts
            or '\\' in name):
        raise ValueError('public relative path refused')
    return name


def validate_wrapper_state(wrapper, *, source_roots):
    """Structural preflight only; Q5c3b must observe the actual final namespace."""
    if set(wrapper) != {'environment', 'overlays', 'private_root'}:
        raise ValueError('explicit final wrapper state required')
    private = Path(wrapper['private_root'])
    if (private != private.resolve(strict=True) or not private.is_absolute()
            or not private.is_dir() or private.stat().st_uid != os.geteuid()
            or stat.S_IMODE(private.stat().st_mode) != 0o700
            or any(private.is_relative_to(root) or root.is_relative_to(private) for root in source_roots)):
        raise ValueError('isolated private wrapper root refused')
    overlays = wrapper['overlays']
    for overlay in overlays:
        if (set(overlay) != {'target', 'source', 'read_only'}
                or type(overlay['read_only']) is not bool
                or not Path(overlay['target']).is_absolute()
                or str(PurePosixPath(overlay['target'])) != overlay['target']
                or '..' in PurePosixPath(overlay['target']).parts):
            raise ValueError('wrapper overlay refused')
        source = Path(overlay['source'])
        if source != source.resolve(strict=True):
            raise ValueError('wrapper source alias refused')
        if not overlay['read_only'] and (not source.is_relative_to(private) or any(
                source.is_relative_to(root) or root.is_relative_to(source) for root in source_roots)):
            raise ValueError('writable public source alias refused')
    environment = wrapper['environment']
    if set(environment) != {'HOME', 'GNUPGHOME', 'TMPDIR'}:
        raise ValueError('final wrapper HOME/GNUPGHOME/TMPDIR required')
    resolved = {}
    for name, value in environment.items():
        target = Path(value)
        if (not target.is_absolute() or str(PurePosixPath(value)) != value
                or '..' in target.parts):
            raise ValueError('wrapper environment path refused')
        # Later parent mounts hide prior child mounts; replay order, not longest prefix.
        visible = None
        for overlay in reversed(overlays):
            mount = Path(overlay['target'])
            if target.is_relative_to(mount):
                visible = (overlay, Path(overlay['source']) / target.relative_to(mount))
                break
            if mount.is_relative_to(target):
                raise ValueError('wrapper private subtree shadowed')
        if visible is None or visible[0]['read_only']:
            raise ValueError('wrapper private state missing or readonly')
        leaf = visible[1]
        if (leaf != leaf.resolve(strict=True) or not leaf.is_relative_to(private)
                or not leaf.is_dir() or leaf.stat().st_uid != os.geteuid()
                or stat.S_IMODE(leaf.stat().st_mode) != 0o700):
            raise ValueError('wrapper private state shadowed or nonprivate')
        resolved[name] = str(leaf)
    if len(set(resolved.values())) != 3:
        raise ValueError('wrapper private state must be separate')
    return resolved


def archive_members(payload):
    """Validate the pinned archive tree without extraction or following links."""
    members = {}
    root_seen = False
    for member in payload:
        if member.name in ('.', './'):
            if root_seen or not member.isdir() or member.size != 0:
                raise ValueError('runner archive root entry refused')
            root_seen = True
            continue
        name = relative_input(member.name.removeprefix('./').rstrip('/'))
        allowed_link = (member.issym() and member.size == 0
                        and ARCHIVE_LINKS.get(name) == member.linkname)
        if name in members or not (member.isfile() or member.isdir() or allowed_link):
            raise ValueError('runner archive alias or special member refused')
        members[name] = member
    for name, member in members.items():
        if any(str(parent) in members and not members[str(parent)].isdir()
               for parent in PurePosixPath(name).parents if str(parent) != '.'):
            raise ValueError('runner archive parent shadowed')
        if member.issym():
            target = posixpath.normpath(posixpath.join(posixpath.dirname(name), member.linkname))
            if target not in members or not members[target].isfile():
                raise ValueError('runner archive link target refused')
    return members


def public_recipe_preflight(raw, selected, *, expected_sha256, selection, fixture, request=None):
    """Prepare the connected new source proposal for a later bank/ISO unit."""
    import public_templates
    result = public_templates.generate(raw, selected, expected_sha256=expected_sha256,
                                       selection=selection, fixture=fixture)
    if selection != 'public-c22-alpine-c24':
        raise ValueError('new public recipe selection required')
    texts = {name: data.decode('utf-8') for name, data in result.files.items()}
    texts['iso/install-runner.py'] = installer_for(request)
    coupled = capacity_sources(Path('/unused'), texts=texts, selection=selection)
    generated = dict(result.files)
    generated.update({name: text.encode('utf-8') for name, text in coupled.items()})
    for name in ('native_policy.py', 'native-inputs.json'):
        generated['iso/' + name] = (ROOT / name).read_bytes()
    plan = dict(result.plan)
    plan['generated'] = {name: hashlib.sha256(data).hexdigest() for name, data in sorted(generated.items())}
    available = list(plan['docker_copy_inputs'])
    available.extend(name for name in coupled if '/' not in name and name.endswith('.py') and name not in available)
    plan['docker_copy_inputs'] = public_templates.copy_inputs(generated['Dockerfile'].decode('utf-8'), available)
    plan['capacity_coupling'] = {'api': 'runner_setup.public_recipe_preflight',
                               'selection': selection, 'budget': budget.selected(selection),
                               'helpers': sorted(name for name in coupled if name.endswith('.py'))}
    plan['pending'] = ['bank-materialization-and-iso', 'namespace', 'causal-runtime-qualification']
    return generated, plan


def materialization_preflight(public_input_dir, archive, *, request=None, wrapper=None):
    """Resolve and hash the finite source plan without staging or running any tool."""
    if public_input_dir is None:
        raise ValueError('explicit public input directory required')
    bank = Path(public_input_dir)
    manifest_path = regular_input(bank / 'manifest.json')
    archive = regular_input(archive)
    manifest_digest, archive_digest = sha(manifest_path), sha(archive)
    if manifest_digest != Q2_MANIFEST or archive_digest != ARCHIVE_SHA:
        raise ValueError('frozen q2 or pinned runner archive changed')
    manifest = public_record(manifest_path)
    source = bank / 'build-provision-q1'
    seal_path = regular_input(source / 'context-seal.json')
    if sha(seal_path) != manifest['build-provision-q1/context-seal.json']['sha256']:
        raise ValueError('frozen context seal changed')
    seal = public_record(seal_path)
    if 'source-preflight.json' in seal:
        raise ValueError('reserved generated receipt path refused')
    for name, digest in seal.items():
        relative_input(name)
        if not isinstance(digest, str) or re.fullmatch('[0-9a-f]{64}', digest) is None:
            raise ValueError('public context fingerprint refused')
        if sha(regular_input(source / name)) != digest:
            raise ValueError('frozen q2 context changed')
    paths = list(source.rglob('*'))
    if (any(path.is_symlink() or not (path.is_file() or path.is_dir()) for path in paths)
            or {str(path.relative_to(source)) for path in paths if path.is_file()}
            != set(seal) | {'context-seal.json'}):
        raise ValueError('public context missing or shadowed input')
    # The pinned archive remains unextracted; validate aliases, types and measured leaves.
    with tarfile.open(archive) as payload:
        members = archive_members(payload)
        for relative, digest in FILES.items():
            member = members.get(relative)
            if member is None or not member.isfile():
                raise ValueError('runner payload member missing')
            stream = payload.extractfile(member)
            with stream:
                if hashlib.file_digest(stream, 'sha256').hexdigest() != digest:
                    raise ValueError('runner payload fingerprint changed')
    names = ('outer.py', 'qga.py', 'Dockerfile', 'iso/provision.py', 'iso/user-data', 'iso/meta-data')
    texts = {name: (source / name).read_text() for name in names}
    marker = "pathlib.Path('/var/lib/local-ci/provisioned').touch()"
    if texts['iso/provision.py'].count(marker) != 1:
        raise ValueError('q2 provisioning prerequisite marker changed')
    texts['iso/provision.py'] = replace(texts['iso/provision.py'], marker,
        "subprocess.run(['python3','/mnt/install-runner.py'],check=True)\n" + marker)
    texts['Dockerfile'] = replace(texts['Dockerfile'], 'vi-local-ci-208c2d13-vm-image-q2',
                                'vi-local-ci-208c2d13-vm-jit-inputs-v3')
    texts['iso/install-runner.py'] = installer_for(request)
    generated = {name: text.encode() for name, text in capacity_sources(source, texts=texts).items()}
    for name in ('native_policy.py', 'native-inputs.json'):
        generated['iso/' + name] = regular_input(ROOT / name).read_bytes()
    closure = {name: digest for name, digest in seal.items() if name != 'seed.iso'}
    closure.update({name: hashlib.sha256(data).hexdigest() for name, data in generated.items()})
    closure['iso/' + ARCHIVE] = ARCHIVE_SHA
    # Reject parent/leaf conflicts before a generated file could overwrite a source alias.
    for name in closure:
        if any(str(parent) in closure for parent in PurePosixPath(name).parents if str(parent) != '.'):
            raise ValueError('generated source path shadowed')
    imports = {}
    for name, data in generated.items():
        if name.endswith('.py'):
            tree = ast.parse(data, filename=name)
            imports[name] = sorted({node.module or '' for node in ast.walk(tree) if isinstance(node, ast.ImportFrom)}
                                   | {alias.name for node in ast.walk(tree) if isinstance(node, ast.Import) for alias in node.names})
    copies = []
    for line in generated['Dockerfile'].decode().splitlines():
        parts = shlex.split(line, comments=True)
        if not parts or parts[0].upper() not in ('COPY', 'ADD'):
            continue
        if parts[0].upper() == 'ADD' or len(parts) < 3:
            raise ValueError('unresolved Dockerfile input')
        for name in parts[1:-1]:
            relative_input(name)
            if name not in closure and name != 'seed.iso':
                raise ValueError('Dockerfile source missing: ' + name)
            copies.append(name)
    resolved = None if wrapper is None else validate_wrapper_state(wrapper, source_roots=(bank, archive))
    receipt = {'schema': 'vimbadmin-materialization-preflight-v1', 'public_input_dir': str(bank),
               'manifest_sha256': manifest_digest, 'archive': str(archive), 'archive_sha256': archive_digest,
               'context_seal_sha256': sha(seal_path), 'candidate_setup_sha256': sha(Path(__file__)), 'request': request,
               'closure': dict(sorted(closure.items())), 'generated': sorted(generated),
               'docker_copy_inputs': copies, 'python_imports': imports,
               'source_logical_bytes': sum((source / name).stat().st_size for name in seal) + archive.stat().st_size,
               'wrapper_state': resolved, 'materialization_ready': resolved is not None, 'wrapper_required': ['HOME', 'GNUPGHOME', 'TMPDIR'],
               'runtime_qualified': False, 'execution_allowed': False}
    return source, seal, generated, receipt


def copy_public(source, destination, digest):
    """Exclusive independent copy; source inputs never acquire writable aliases."""
    with source.open('rb') as stream, destination.open('xb') as output:
        shutil.copyfileobj(stream, output)
    shutil.copymode(source, destination)
    if sha(destination) != digest:
        raise ValueError('copied public input fingerprint changed')


def stage(archive, directory, *, request=None):
    source, seal, generated, receipt = materialization_preflight(Q2, archive, request=request)
    if (not directory.is_absolute() or directory != directory.resolve()
            or directory.is_relative_to(source.parent)
            or directory.is_relative_to(ROOT.parents[2])):
        raise ValueError('isolated materialization output required')
    host_ready(receipt, directory=directory.parent)
    directory.mkdir(mode=0o700)
    for name, digest in seal.items():
        if name in ('seed.iso', 'iso/' + ARCHIVE) or name in generated:
            continue
        destination = directory / name
        destination.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
        copy_public(source / name, destination, digest)
    for name, data in generated.items():
        target = directory / name
        target.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
        with target.open('xb') as output:
            output.write(data)
    copy_public(archive, directory / 'iso' / ARCHIVE, ARCHIVE_SHA)
    if any(sha(directory / name) != digest for name, digest in receipt['closure'].items()):
        raise ValueError('generated materialization drift')
    subprocess.run(['genisoimage', '-quiet', '-output', str(directory / 'seed.iso'), '-volid', 'cidata',
                    '-joliet', '-rock', str(directory / 'iso')], check=True, timeout=90, env={'PATH': os.defpath})
    if any(sha(source / name) != digest for name, digest in seal.items()):
        raise ValueError('verified public input changed during staging')
    if receipt['candidate_setup_sha256'] != sha(Path(__file__)):
        raise ValueError('candidate setup changed during staging')
    (directory / 'source-preflight.json').write_text(json.dumps(receipt, sort_keys=True) + '\n')
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
    parser.add_argument('--preflight-public-seed', action='store_true')
    parser.add_argument('--wrapper-state', type=Path)
    parser.add_argument('--stage-verified-public-seed', action='store_true')
    parser.add_argument('--build-verified-public-seed', action='store_true')
    parser.add_argument('--archive', type=Path)
    parser.add_argument('--public-input-dir', type=Path)
    parser.add_argument('--output-dir', type=Path)
    args = parser.parse_args()
    if args.output_dir is not None and args.output_dir.resolve().is_relative_to(ROOT.parents[2]):
        raise ValueError('public build inputs must stay outside the repository')
    if args.preflight_public_seed:
        if (args.archive is None or args.public_input_dir is None or args.output_dir is not None
                or args.stage_verified_public_seed or args.build_verified_public_seed):
            raise ValueError('preflight requires explicit bank/archive and no staging')
        wrapper = None if args.wrapper_state is None else public_record(args.wrapper_state)
        print(json.dumps(materialization_preflight(args.public_input_dir, args.archive, wrapper=wrapper)[3], sort_keys=True))
        return
    if args.wrapper_state is not None:
        raise ValueError('wrapper state accepts preflight only')
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
