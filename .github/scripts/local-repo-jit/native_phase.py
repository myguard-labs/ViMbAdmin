"""Foreground V4 native baseline phase; execute only under its next reviewed grant."""
import argparse
import hashlib
import importlib.util
import json
import os
import re
import stat
import subprocess
from pathlib import Path
from typing import TYPE_CHECKING

ROOT = Path(__file__).resolve().parent
V4_SEAL = None  # No historical private-bank binding in maintained execution.
WORKSPACE = None  # Explicit owned external directory required for execution.

def sha_context_seal():
    path = WORKSPACE / 'public-capacity-context-q1/context-seal.json' if WORKSPACE else None
    if path is None:
        return None
    if not path.is_file():
        return None
    with path.open('rb') as stream:
        return hashlib.file_digest(stream, 'sha256').hexdigest()


CONTEXT_SEAL = sha_context_seal()
ARCHIVE = '04cf0be1aff4c3ec3554466c39124ca250e3effd8873bb7e8d68535aa9505d5d'
PREFIX = 'vi-local-ci-208c2d13-v4-capacity-r3-q1-'
NAME = PREFIX + 'provision-q1'
OUTPUT = ROOT / 'capacity-native-baseline-q1'
IMAGE_RECEIPT = ROOT / 'public-capacity-context-q1-build-receipts/creation-receipt.json'
PUBLIC_MANIFEST = '0d417bb6f36467483e5a78522f17777c398726bef1deff9497ae0ad5dd500f26'
PUBLIC_CONTEXT = '9d6c43cf9894e678082fa034d80ba9b4084819f72da6d2bb45fb53fd1fbaef0d'
PUBLIC_SELECTION = '25902319f1dc0716c230fbdbea7d161245f06698ac4b625bdc1e2d42a4df1bcb'
PUBLIC_REQUEST_MODEL = 'cacd08733aeeb8272991294c7790b8c6534682c4c05d9c6455c9fe05c4843c56'


class Refused(RuntimeError):
    pass


def require(value, reason):
    if not value:
        raise Refused(reason)


def sha(path):
    with path.open('rb') as stream:
        return hashlib.file_digest(stream, 'sha256').hexdigest()


def inputs():
    source = ROOT / 'vm_backend.py'
    manifest = json.loads((ROOT / 'code-inputs.json').read_text())
    for name in ('vm_backend.py', 'native_preflight.py'):
        require(sha(ROOT / name) == manifest[name]['sha256'], 'maintained executable source changed')
    selection = ROOT / 'public_recipe_selection.py'
    require(selection.is_file() and not selection.is_symlink() and sha(selection) == PUBLIC_SELECTION,
            'maintained selection helper changed')
    spec = importlib.util.spec_from_file_location('capacity_owned_backend', source)
    backend = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(backend)
    require(backend.PREFIX == 'vi-local-ci-208c2d13-vm-q2-', 'backend namespace preimage changed')
    backend.PREFIX = PREFIX
    backend.OUTER_WORK = '/work/vi-local-ci-208c2d13-v4-capacity-r3-q1'
    return backend, (ROOT / 'native_preflight.py').read_text()


backend, NATIVE = inputs()
VERSIONS = '''
import subprocess
versions={}
for name,path in [('runner','bin/Runner.Listener'),('node20','externals/node20/bin/node'),('node24','externals/node24/bin/node')]:
 versions[name]=subprocess.check_output(['runuser','-u','runner','--','/opt/actions-runner/'+path,'--version'],text=True).strip()
assert versions['runner']=='2.336.0' and versions['node20'].startswith('v20.') and versions['node24'].startswith('v24.')
print(json.dumps({'versions':versions}))
'''
NATIVE_COMMAND = NATIVE + VERSIONS


def sync_directory(path):
    descriptor = os.open(path, os.O_RDONLY | os.O_DIRECTORY)
    try:
        os.fsync(descriptor)
    finally:
        os.close(descriptor)


def durable(path, record):
    temporary = path.with_name(path.name + '.pending')
    fd = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    with os.fdopen(fd, 'w') as output:
        json.dump(record, output, indent=2)
        output.write('\n'); output.flush(); os.fsync(output.fileno())
    os.replace(temporary, path)
    directory = os.open(path.parent, os.O_RDONLY | os.O_DIRECTORY)
    try:
        os.fsync(directory)
    finally:
        os.close(directory)


def inventory_contract(selection='historical', bank=None):
    """Select one reviewed inventory; selection never grants runtime authority."""
    if selection == 'historical':
        require(bank is None, 'historical bank override refused')
        require(WORKSPACE is not None, 'owned external workspace required')
        return WORKSPACE / 'public-capacity-context-q1', CONTEXT_SEAL, 50
    require(selection == 'public-c22-alpine-c24', 'explicit reviewed recipe selection required')
    require(bank is not None, 'explicit selected public bank required')
    bank = Path(bank)
    require(bank.is_absolute() and bank == bank.resolve(strict=True), 'canonical selected public bank required')
    manifest_path = bank / 'manifest.json'
    require(manifest_path.is_file() and not manifest_path.is_symlink() and manifest_path.stat().st_nlink == 1,
            'regular selected public bank manifest required')
    require(sha(manifest_path) == PUBLIC_MANIFEST, 'selected public bank manifest changed')
    manifest = json.loads(manifest_path.read_text())
    expected = dict(manifest['config']['contracts'])
    expected['public_bank.py'] = manifest['config']['materializer_sha256']
    expected['request_model.py'] = PUBLIC_REQUEST_MODEL
    for name, fingerprint in expected.items():
        leaf = ROOT / name
        require(leaf.is_file() and not leaf.is_symlink() and sha(leaf) == fingerprint,
                'selected public bank maintained source changed')
    import public_bank
    import public_recipe_selection
    profile = public_recipe_selection.select(selection)
    require(manifest['schema'] == 'vimbadmin-public-bank-v1'
            and manifest['selection'] == selection and manifest['config']['selection'] == profile
            and manifest['config']['recipe_sha256'] == profile['recipe_sha256']
            and manifest['context_seal_sha256'] == PUBLIC_CONTEXT, 'selected public bank contract changed')
    actual = public_bank.tree_seal(bank)
    actual.pop('manifest.json')
    require(actual == manifest['files'], 'selected public bank inventory changed')
    return bank / 'context', PUBLIC_CONTEXT, 224


def admit(path=None, *, selection='historical', bank=None):
    path = IMAGE_RECEIPT if path is None else path
    context, context_seal, count = inventory_contract(selection, bank)
    require(context_seal is not None, 'materialized reviewed context required')
    require(path == IMAGE_RECEIPT and path.is_file() and not path.is_symlink(), 'completed exact build receipt required')
    require(not path.parent.is_symlink() and path.parent.stat().st_uid == os.geteuid()
            and path.parent.stat().st_mode & 0o777 == 0o700, 'private owned build receipt directory required')
    value = json.loads(path.read_text())
    require(set(value) == {'image', 'project', 'runlabel', 'context_seal_sha256', 'runner_archive_sha256', 'runner_version', 'runtime_qualification'}, 'build receipt schema changed')
    require(isinstance(value['image'], str) and re.fullmatch('sha256:[0-9a-f]{64}', value['image']), 'full created image ID required')
    require(value['project'] == 'vimbadmin' and value['runlabel'] == 'vi-local-ci-208c2d13-v4-capacity-r3-image-q1'
            and value['context_seal_sha256'] == context_seal and value['runner_archive_sha256'] == ARCHIVE
            and value['runner_version'] == '2.336.0' and value['runtime_qualification'] == 'UNQUALIFIED', 'build input binding refused')
    require(sha(context / 'context-seal.json') == context_seal, 'actual seed context seal changed')
    sealed = json.loads((context / 'context-seal.json').read_text())
    paths = list(context.rglob('*'))
    require({str(path.relative_to(context)) for path in paths if path.is_file() and path.name != 'context-seal.json'} == set(sealed)
            and not any(path.is_symlink() for path in paths), 'public seed inventory changed')
    require(len(sealed) == count and all(not (context / name).is_symlink() and sha(context / name) == digest for name, digest in sealed.items()), 'actual public seed inputs changed')
    if selection == 'historical':
        import runner_setup
        runner_setup.verify_materialized(context)
    return value


def build_selected(bank, command=None):
    """Build only the fixed NEW bank; a completed image remains UNQUALIFIED."""
    context, seal, count = inventory_contract('public-c22-alpine-c24', bank)
    require(WORKSPACE is not None and WORKSPACE.is_dir() and WORKSPACE == WORKSPACE.resolve(strict=True)
            and WORKSPACE.stat().st_uid == os.geteuid() and stat.S_IMODE(WORKSPACE.stat().st_mode) == 0o700
            and not WORKSPACE.is_relative_to(context.parent) and not context.parent.is_relative_to(WORKSPACE)
            and not WORKSPACE.is_relative_to(ROOT), 'isolated owned build workspace required')
    require(IMAGE_RECEIPT == WORKSPACE / 'public-capacity-context-q1-build-receipts/creation-receipt.json',
            'selected build receipt purpose refused')
    private = IMAGE_RECEIPT.parent
    private.mkdir(mode=0o700)
    config = private / 'empty-docker-config'
    config.mkdir(mode=0o700)
    durable(private / 'build-inputs.json', {'selection': 'public-c22-alpine-c24', 'bank_manifest_sha256': PUBLIC_MANIFEST,
            'context_seal_sha256': seal, 'context_inputs': count, 'runtime_qualification': 'UNQUALIFIED'})
    iid = private / 'created-image.id'
    command = subprocess.run if command is None else command
    result = command(['docker', '--config', str(config), 'build', '--network=none', '--pull=false',
                      '--iidfile', str(iid), str(context)], check=True, env={'PATH': os.defpath})
    require(iid.is_file() and not iid.is_symlink(), 'regular created image ID required')
    identity = iid.read_text().strip()
    require(result.returncode == 0 and re.fullmatch('sha256:[0-9a-f]{64}', identity),
            'own build did not return an immutable image ID')
    value = {'image': identity, 'project': 'vimbadmin', 'runlabel': 'vi-local-ci-208c2d13-v4-capacity-r3-image-q1',
             'context_seal_sha256': seal, 'runner_archive_sha256': ARCHIVE,
             'runner_version': '2.336.0', 'runtime_qualification': 'UNQUALIFIED'}
    durable(IMAGE_RECEIPT, value)
    return value


if TYPE_CHECKING:
    from vm_backend import Owned as OwnedBase
else:
    OwnedBase = backend.Owned


class Owner(OwnedBase):
    def save(self):
        durable(self.directory / 'receipts.json', self.receipts)

    def stopped(self, receipt):
        require(receipt in self.receipts and receipt['name'] == NAME and receipt['phase'] != 'removed', 'owned literal creation receipt required')
        info = self.inspect(receipt['id'])
        backend.validate(info, receipt)
        state = info['State']
        require(state['Running'] is False and state['Pid'] == 0 and state['ExitCode'] == 0, 'clean stopped PID0 state unproved')
        return info

    def finish(self, receipt, export=False):
        destination = self.directory / 'baseline.qcow2'
        require(not export or (not destination.exists() and not destination.is_symlink()), 'export destination preexists')
        self.guest(receipt, "nohup sh -c 'sleep 1; poweroff' >/dev/null 2>&1 &")
        require(self.act(receipt, ['wait']).stdout.strip() == '0', 'outer shutdown failed')
        self.stopped(receipt)
        if export:
            self.command(['cp', receipt['id'] + ':' + backend.OUTER_WORK + '/sealed.qcow2', str(destination)])
            require(destination.is_file() and not destination.is_symlink(), 'baseline export absent')
            with destination.open('rb') as exported:
                os.fsync(exported.fileno())
            with (self.directory / 'baseline.sha256').open('x') as digest:
                digest.write(sha(destination) + '\n'); digest.flush(); os.fsync(digest.fileno())
            sync_directory(self.directory)
        self.act(receipt, ['rm'])
        receipt['phase'] = 'removed'; self.save()
        absent = self.command(['inspect', '--type', 'container', receipt['id']], check=False)
        require(absent.returncode != 0 and 'No such' in absent.stderr, 'exact container absence unproved')


def validate_native(raw):
    lines = raw.splitlines()
    require(len(lines) == 2, 'native metadata response shape refused')
    proof, versions = map(json.loads, lines)
    require(set(proof) == {'runtime', 'elf_hashes', 'unselected_alpine', 'optional_tracing_unavailable', 'active_runner_version', 'actual_jit_qualified'}, 'native metadata schema refused')
    require(proof['runtime'] == '8.0.28' and proof['elf_hashes'] == 21 and proof['active_runner_version'] == '2.336.0'
            and proof['actual_jit_qualified'] is False
            and proof['unselected_alpine'] == ['externals/node20_alpine/bin/node', 'externals/node24_alpine/bin/node'], 'native metadata contract refused')
    require(proof['optional_tracing_unavailable'] in ([], [{'member': 'bin/libcoreclrtraceptprovider.so', 'unavailable': ['liblttng-ust.so.0']}]), 'optional tracing response refused')
    require(set(versions) == {'versions'} and set(versions['versions']) == {'runner', 'node20', 'node24'}, 'version schema refused')
    values = versions['versions']
    require(values['runner'] == '2.336.0' and re.fullmatch(r'v20\.[0-9]+\.[0-9]+', values['node20'])
            and re.fullmatch(r'v24\.[0-9]+\.[0-9]+', values['node24']), 'active native versions refused')
    return {'native': proof, 'versions': values}


def validate_fresh(fresh):
    require(set(fresh) == {'fresh', 'boot_id', 'docker_server', 'env_names'}, 'fresh metadata schema refused')
    require(fresh['fresh'] is True and isinstance(fresh['boot_id'], str)
            and re.fullmatch(r'[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}', fresh['boot_id'])
            and fresh['docker_server'] == '29.1.3' and isinstance(fresh['env_names'], list)
            and all(isinstance(name, str) and re.fullmatch('[A-Za-z_][A-Za-z0-9_]*', name)
                    and not any(part in name.upper() for part in ('SECRET', 'TOKEN', 'PASSWORD', 'CREDENTIAL')) for name in fresh['env_names']),
            'fresh Docker/credential/work/session proof absent')
    return fresh


def observe_failure(owner):
    import failure_observation
    try:
        if len(owner.receipts) != 1 or (owner.directory / 'baseline.qcow2').exists():
            return failure_observation.unavailable()
        response = owner.act(owner.receipts[0], ['exec'], ['/usr/bin/python3', '/opt/vm/failure_observation.py'])
        value = failure_observation.validate(json.loads(response.stdout))
        require(value['status'] == 'observed' and value['raw_retained'], 'durable serial receipt absent')
        receipt = value['raw_receipt']
        directory = owner.directory / 'serial-observations'
        failure_observation.private_spool(directory)
        path = directory / receipt['file']
        require(not path.exists() and not path.is_symlink(), 'serial export path preexists')
        owner.command(['cp', owner.receipts[0]['id'] + ':' + str(failure_observation.SPOOL / receipt['file']), str(path)])
        fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
        try:
            info = os.fstat(fd)
            require(stat.S_ISREG(info.st_mode) and info.st_uid == os.geteuid()
                    and stat.S_IMODE(info.st_mode) == 0o600 and info.st_size == receipt['bytes'], 'serial export identity/size refused')
            with os.fdopen(os.dup(fd), 'rb') as raw:
                require(hashlib.file_digest(raw, 'sha256').hexdigest() == receipt['sha256'], 'serial export digest refused')
            os.fsync(fd)
        finally:
            os.close(fd)
        sync_directory(directory)
        durable(directory / (receipt['file'] + '.json'), receipt)
        return value
    except Exception:  # noqa: BLE001 - diagnostic failure cannot expose transcripts or change phase disposition
        return failure_observation.unavailable()


def execute(value, directory=None, factory=Owner):
    directory = OUTPUT if directory is None else directory
    require(directory == OUTPUT, 'unreviewed receipt/output purpose')
    directory.mkdir(mode=0o700)
    sync_directory(directory.parent)
    owner = factory(directory)
    receipt = None
    try:
        durable(directory / 'admission.json', {'name': NAME, 'runlabel': NAME, 'project': 'vimbadmin',
                'image': value['image'], 'context_seal_sha256': CONTEXT_SEAL, 'v4_manifest_sha256': V4_SEAL,
                'purpose': 'runner-native-baseline-q1', 'created_id': None})
        # Image ID from the completed maintained creation receipt only.
        image = json.loads(owner.command(['image', 'inspect', value['image']]).stdout)[0]
        require(image['Id'] == value['image'] and image['Config']['Labels'].get('myguard.run') == value['runlabel'], 'actual image receipt identity refused')
        receipt = owner.create(value['image'], 'provision', 'provision-q1')
        owner.ready(receipt)
        owner.guest(receipt, 'cloud-init status --wait >/dev/null && test -f /var/lib/local-ci/provisioned')
        boot = observe_failure(owner)
        require(boot['status'] == 'observed'
                and any(m['boundary'] == 'root_ready' and m['event'] == 'pass' for m in boot['marker_events'])
                and any(m['boundary'] == 'provision' and m['event'] == 'pass' for m in boot['marker_events']), 'baseline serial boundary proof absent')
        durable(directory / 'boot-observation.json', boot)
        capacity = json.loads(owner.guest(receipt, 'cat /var/lib/local-ci/runner-capacity.json'))
        from guest_capacity import (
            ENTRIES,
            PAYLOAD_BYTES,
            RESERVE_BYTES,
            RESERVE_INODES,
            ROOT_INODES,
        )
        require(capacity.get('payload_bytes') == PAYLOAD_BYTES and capacity.get('entries') == ENTRIES
                and capacity.get('reserve_bytes') == RESERVE_BYTES and capacity.get('reserve_inodes') == RESERVE_INODES
                and capacity.get('available_bytes', 0) >= PAYLOAD_BYTES + RESERVE_BYTES
                and capacity.get('available_inodes', 0) >= ENTRIES + ROOT_INODES + RESERVE_INODES, 'guest capacity pre-extraction proof absent')
        durable(directory / 'capacity.json', capacity)
        metadata = validate_native(owner.guest(receipt, 'python3 -', NATIVE_COMMAND))
        fresh = validate_fresh(json.loads(owner.guest(receipt, 'python3 -', backend.FRESH)))
        durable(directory / 'native-proof.json', metadata)
        durable(directory / 'fresh.json', fresh)
        owner.finish(receipt, export=True)
        result = {'phase': 'v4-runner-native-baseline', 'status': 'PASS', 'created': dict(receipt),
                  'baseline_sha256': sha(directory / 'baseline.qcow2'), 'fresh': fresh, 'native': metadata,
                  'cleanup': 'exact stopped creation ID nonforce removed; absence proved', 'jit_qualified': False}
        durable(directory / 'result.json', result)
        return result
    except Exception:  # noqa: BLE001 - fixed redaction and durable identity on every startup failure
        # Even a create() post-creation failure has its durable creation receipt.
        durable(directory / 'failure-observation.json', observe_failure(owner))
        durable(directory / 'failure.json', {'phase': 'v4-runner-native-baseline', 'status': 'UNQUALIFIED',
                 'admitted_name': NAME, 'expected_image': value['image'], 'created': owner.receipts,
                 'cleanup': 'not attempted; retain exact unknown/running/drifted resource',
                 'reason': 'phase failed; bounded serial evidence retained in private receipts when available'})
        raise Refused('phase failed; inspect private metadata receipt; no automatic cleanup') from None


def cleanup(owner, receipt):
    require(receipt in owner.receipts and receipt['name'] == NAME and receipt['phase'] == 'created', 'exact active creation receipt required')
    info = owner.inspect(receipt['id']); backend.validate(info, receipt)
    running = info['State']['Running']
    require(type(running) is bool, 'known running state required')
    if running:
        owner.act(receipt, ['stop', '--time', '10'])
        require(owner.act(receipt, ['wait']).stdout.strip() in ('0', '137', '143'), 'owned stop unresolved')
    info = owner.inspect(receipt['id']); backend.validate(info, receipt)
    require(info['State']['Running'] is False and info['State']['Pid'] == 0
            and type(info['State']['ExitCode']) is int and 0 <= info['State']['ExitCode'] <= 255
            and (not running or info['State']['ExitCode'] in (0, 137, 143)), 'stopped exact ID cleanup unproved')
    owner.act(receipt, ['rm'])
    receipt['phase'] = 'removed'; owner.save()
    absent = owner.command(['inspect', '--type', 'container', receipt['id']], check=False)
    require(absent.returncode != 0 and 'No such' in absent.stderr, 'cleanup absence unresolved')


def recover(value):
    require(OUTPUT.is_dir() and not OUTPUT.is_symlink(), 'exact private failed output required')
    require(OUTPUT.stat().st_uid == os.geteuid() and OUTPUT.stat().st_mode & 0o777 == 0o700, 'private owned failed output required')
    config = OUTPUT / 'empty-docker-config'
    require(config.is_dir() and not config.is_symlink() and not list(config.iterdir()), 'private empty Docker config required')
    require(config.stat().st_uid == os.geteuid() and config.stat().st_mode & 0o777 == 0o700, 'private Docker configuration ownership required')
    owner = Owner.__new__(Owner)
    owner.directory, owner.config = OUTPUT, config
    path = OUTPUT / 'receipts.json'
    require(path.is_file() and not path.is_symlink(), 'durable creation receipt required')
    owner.receipts = json.loads(path.read_text())
    require(len(owner.receipts) == 1, 'exactly one creation identity required')
    receipt = owner.receipts[0]
    require(set(receipt) == {'id', 'name', 'runlabel', 'project', 'image', 'phase'}
            and re.fullmatch('[0-9a-f]{64}', receipt['id']) and receipt['image'] == value['image']
            and receipt['name'] == NAME and receipt['runlabel'] == NAME and receipt['project'] == 'vimbadmin', 'creation receipt identity refused')
    cleanup(owner, receipt)


def main():
    global WORKSPACE, OUTPUT, IMAGE_RECEIPT, CONTEXT_SEAL, V4_SEAL
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--workspace', type=Path, required=True)
    parser.add_argument('--recipe-selection', choices=('historical', 'public-c22-alpine-c24'), default='historical')
    parser.add_argument('--public-bank', type=Path)
    modes = parser.add_mutually_exclusive_group()
    modes.add_argument('--execute-reviewed-phase', action='store_true')
    modes.add_argument('--cleanup-reviewed-failed', action='store_true')
    modes.add_argument('--build-selected-public-bank', action='store_true')
    args = parser.parse_args()
    WORKSPACE = args.workspace.resolve()
    require(WORKSPACE.is_dir() and not args.workspace.is_symlink()
            and not WORKSPACE.is_relative_to(ROOT.parents[2])
            and WORKSPACE.stat().st_uid == os.geteuid()
            and WORKSPACE.stat().st_mode & 0o777 == 0o700, 'owned external workspace required')
    OUTPUT = WORKSPACE / 'capacity-native-baseline-q1'
    IMAGE_RECEIPT = WORKSPACE / 'public-capacity-context-q1-build-receipts/creation-receipt.json'
    CONTEXT_SEAL = sha_context_seal()
    if args.build_selected_public_bank:
        require(args.recipe_selection == 'public-c22-alpine-c24', 'explicit NEW build selection required')
        build_selected(args.public_bank)
        return
    require(args.execute_reviewed_phase or args.cleanup_reviewed_failed, 'fresh phase-specific FIFO grant required')
    value = admit(selection=args.recipe_selection, bank=args.public_bank)
    if args.recipe_selection == 'public-c22-alpine-c24':
        CONTEXT_SEAL, V4_SEAL = value['context_seal_sha256'], PUBLIC_MANIFEST
    if args.cleanup_reviewed_failed:
        recover(value)
    else:
        execute(value)


if __name__ == '__main__':
    try:
        main()
    except Exception:  # noqa: BLE001 - never print arbitrary native/daemon startup transcripts
        print('V4 native phase refused/failed; metadata only on stdout; inspect private receipts.')
        raise SystemExit(1) from None
