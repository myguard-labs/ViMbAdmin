"""Materialize the explicitly selected public bank and qualify its inert inputs."""
from __future__ import annotations

import argparse
import hashlib
import io
import json
import os
import subprocess
import tarfile
import time
from pathlib import Path

import public_recipe
import public_recipe_selection
import runner_setup

SELECTION = 'public-c22-alpine-c24'
MAX_ISO_DIRECTORIES = 4096
MAX_DESCRIPTOR_SECTORS = 48
EPOCH = 1793059200  # 2026-10-27 00:00:00 UTC, fixed recipe snapshot.
# Each lexical loader/SONAME is copied to an independent regular leaf, never linked.
TOOL_MEMBERS = {
    'tool-genisoimage': {'usr/bin/genisoimage': 'genisoimage'},
    'tool-libc6': {'lib/x86_64-linux-gnu/' + name: name for name in
                   ('ld-linux-x86-64.so.2', 'libc.so.6', 'libpthread.so.0')},
    'tool-libmagic1': {'usr/lib/x86_64-linux-gnu/libmagic.so.1.0.0': 'libmagic.so.1'},
    'tool-libbz2-1.0': {'lib/x86_64-linux-gnu/libbz2.so.1.0.4': 'libbz2.so.1.0'},
    'tool-zlib1g': {'lib/x86_64-linux-gnu/libz.so.1.2.13': 'libz.so.1'},
    'tool-liblzma5': {'lib/x86_64-linux-gnu/liblzma.so.5.4.1': 'liblzma.so.5'},
}
ISO_OPTIONS = ['-quiet', '-volid', 'cidata', '-joliet', '-rock', '-uid', '0', '-gid', '0',
               '-file-mode', '0444', '-dir-mode', '0555', '-input-charset', 'iso8859-1',
               '-appid', 'vimbadmin-public-seed', '-sysid', 'LINUX', '-publisher', '', '-preparer', '',
               '-volset', '', '-copyright', '', '-abstract', '', '-biblio', '', '-output', '../seed.iso', '.']


def canonical(value):
    return (json.dumps(value, sort_keys=True, separators=(',', ':')) + '\n').encode('ascii')


def digest(data):
    return hashlib.sha256(data).hexdigest()


def regular(path):
    path = runner_setup.regular_input(Path(path))
    if path.stat().st_nlink != 1:
        raise ValueError('public bank linked input refused')
    return path


def deb_members(path, wanted):
    """Read only named regular payload leaves from a verified Debian ar archive."""
    raw = regular(path).read_bytes()
    if raw[:8] != b'!<arch>\n':
        raise ValueError('Debian ar header refused')
    offset, payloads = 8, []
    while offset < len(raw):
        header = raw[offset:offset + 60]
        if len(header) != 60 or header[58:] != b'`\n':
            raise ValueError('Debian ar member header refused')
        size = int(header[48:58])
        end = offset + 60 + size
        if size < 0 or end > len(raw):
            raise ValueError('Debian ar member size refused')
        name = header[:16].decode('ascii').strip().rstrip('/')
        if name in ('data.tar.xz', 'data.tar.gz'):
            payloads.append(raw[offset + 60:end])
        offset = end + size % 2
    if len(payloads) != 1 or offset != len(raw):
        raise ValueError('one bounded Debian data archive required')
    result = {}
    with tarfile.open(fileobj=io.BytesIO(payloads[0])) as archive:
        for member in archive:
            name = member.name.removeprefix('./')
            if name in wanted:
                if name in result or not member.isfile() or not 0 < member.size <= 8 * 1024 * 1024:
                    raise ValueError('Debian tool member type/size/duplicate refused')
                result[name] = archive.extractfile(member).read()
    if set(result) != set(wanted):
        raise ValueError('Debian tool member missing')
    return result


def prepare(raw, selected, fixture):
    """Authenticate the complete finite copy/tool/config closure before writing."""
    profile = public_recipe_selection.select(SELECTION)
    generated, plan = runner_setup.public_recipe_preflight(
        raw, selected, expected_sha256=profile['recipe_sha256'], selection=SELECTION, fixture=fixture)
    copies = {}
    for name, row in plan['dependencies'].items():
        if 'artifact' in row:
            copies[name] = (regular(selected[row['artifact']].path), row['sha256'])
    copies['iso/alpine.tar'] = (regular(fixture.path), profile['alpine_sha256'])
    # OCI FROM's exact manifest, config and layer remain available separately.
    runtime = {'runtime/blobs/sha256/' + row['sha256']:
               (regular(selected[name].path), row['sha256'])
               for name, row in plan['inputs'].items() if row['kind'] in ('runtime-image', 'runtime-blob')}
    tools, tool_records = {}, {}
    for package, wanted in TOOL_MEMBERS.items():
        path = Path(selected[package].path)
        data = deb_members(path, wanted)
        for member, target in wanted.items():
            tools[target] = data[member]
            tool_records[target] = {'package': package, 'archive_sha256': runner_setup.sha(path),
                                    'member': member, 'sha256': digest(data[member])}
    closure = {name: value[1] for name, value in copies.items()}
    if set(closure) & set(generated):
        raise ValueError('generated/public copy collision')
    closure.update({name: digest(data) for name, data in generated.items()})
    for name in closure:
        runner_setup.relative_input(name)
        if not name.isascii():
            raise ValueError('ASCII ISO path required')
        if name == 'seed.iso' or any(str(parent) in closure for parent in Path(name).parents if str(parent) != '.'):
            raise ValueError('public bank path shadowed')
    if not set(plan['docker_copy_inputs']) <= set(closure) | {'seed.iso'}:
        raise ValueError('Docker COPY closure missing')
    config = {'selection': profile, 'recipe_sha256': plan['recipe_sha256'],
              'generated': plan['generated'], 'contracts': plan['contracts'], 'templates': plan['templates'],
              'materializer_sha256': runner_setup.sha(Path(__file__)), 'tools': tool_records,
              'iso': {'epoch': EPOCH, 'options': ISO_OPTIONS, 'cwd': 'context/iso',
                      'environment': {'LC_ALL': 'C', 'TZ': 'UTC0'},
                      'timestamp_policy': 'ISO9660-descriptor-directory-and-RockRidge-TF-v1'},
              'docker_copy_inputs': plan['docker_copy_inputs'],
              'loader_alias': {'/lib64/ld-linux-x86-64.so.2': 'tools/ld-linux-x86-64.so.2'},
              'private_writes': ['HOME', 'GNUPGHOME', 'TMPDIR', 'bank/context/seed.iso', 'bank/ISO repeat output'],
              'tool_config': {'GENISOIMAGERC': 'tools/empty.config', 'sha256': digest(b'')},
              'host_refusals': ['/etc/ld.so.preload'],
              'native_admission': {'required_count': 50, 'proposed_count': len(closure) + 1,
                                   'matches': len(closure) + 1 == 50},
              'pending': ['Q5c3b-final-namespace', 'Q5c3c-production-seals', 'Q6-runtime-qualification'],
              'runtime_qualified': False, 'execution_allowed': False}
    return generated, copies, runtime, tools, closure, config


def tree_seal(root):
    if root != root.resolve(strict=True) or not root.is_dir():
        raise ValueError('canonical bank directory required')
    paths = list(root.rglob('*'))
    if any(p.is_symlink() or not (p.is_dir() or p.is_file()) for p in paths):
        raise ValueError('bank missing or shadowed input')
    return {str(p.relative_to(root)): runner_setup.sha(regular(p)) for p in sorted(paths) if p.is_file()}


def write_file(path, data, mode=0o444):
    path.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
    with path.open('xb') as stream:
        stream.write(data)
    path.chmod(mode)
    os.utime(path, (EPOCH, EPOCH))


def wrapper_state(wrapper, sources):
    return runner_setup.validate_wrapper_state(wrapper, source_roots=tuple(sources))


def iso_command(bank, private, config, output):
    tools = bank / 'tools'
    environment = dict(config['iso']['environment'])
    environment.update(private)
    environment['GENISOIMAGERC'] = str(tools / 'empty.config')
    options = list(config['iso']['options'])
    options[options.index('-output') + 1] = output
    return ([str(tools / 'ld-linux-x86-64.so.2'), '--inhibit-cache', '--library-path', str(tools),
             str(tools / 'genisoimage'), *options], environment)


def materialize(bank, raw, selected, fixture, wrapper):
    generated, copies, runtime, tools, _closure, _config = prepare(raw, selected, fixture)
    bank = Path(bank)
    sources = [Path(choice.path) for choice in selected.values()] + [Path(fixture.path)]
    if (not bank.is_absolute() or bank != bank.resolve() or bank.exists()
            or bank.is_relative_to(runner_setup.ROOT.parents[2])
            or any(p.is_relative_to(bank) or bank.is_relative_to(p) for p in sources)):
        raise ValueError('exclusive isolated public bank output required')
    wrapper_state(wrapper, [*sources, bank])
    bank.mkdir(mode=0o700)
    # The finite closure above is complete before the first independent copy.
    for name, (source, fingerprint) in sorted({**{'context/' + k: v for k, v in copies.items()}, **runtime}.items()):
        target = bank / name
        target.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
        runner_setup.copy_public(source, target, fingerprint)
        target.chmod(0o444)
        os.utime(target, (EPOCH, EPOCH))
    for name, data in sorted(generated.items()):
        write_file(bank / 'context' / name, data)
    for name, data in sorted(tools.items()):
        write_file(bank / 'tools' / name, data, 0o555)
    write_file(bank / 'tools/empty.config', b'')
    return finish_staging(bank, raw, selected, fixture, wrapper)


def apply_timestamps(stream, fields):
    previous = 0
    for offset, value in sorted(fields.items()):
        if offset < previous:
            raise ValueError('ISO timestamp fields overlap')
        previous = offset + len(value)
    for offset, value in sorted(fields.items()):
        stream.seek(offset)
        stream.write(value)


def normalize_iso(path, source, *, apply=True):
    """Canonicalize only defined ISO/Joliet/RR times; verify every Joliet file."""
    sector = 2048
    short = bytes((126, 10, 27, 0, 0, 0, 0))
    long = b'2026102700000000\0'
    raw_sha = runner_setup.sha(path)
    size = path.stat().st_size
    fields, directories, members = {}, set(), {}
    with path.open('r+b' if apply else 'rb') as stream:
        def read(offset, length):
            if offset < 0 or length < 0 or offset + length > size:
                raise ValueError('ISO extent outside image')
            stream.seek(offset)
            value = stream.read(length)
            if len(value) != length:
                raise ValueError('ISO short read')
            return value

        def both(value):
            if len(value) != 8 or int.from_bytes(value[:4], 'little') != int.from_bytes(value[4:], 'big'):
                raise ValueError('ISO duplicated integer disagreement')
            return int.from_bytes(value[:4], 'little')

        def timestamp(offset, length):
            value = read(offset, length)
            # Empty descriptor expiration/effective dates retain their semantics.
            if length == 17 and value == b'0' * 16 + b'\0':
                return
            if length == 17:
                if not value[:16].isdigit():
                    raise ValueError('ISO long timestamp malformed')
            elif not (1 <= value[1] <= 12 and 1 <= value[2] <= 31):
                raise ValueError('ISO short timestamp malformed')
            fields[offset] = long if length == 17 else short

        def susp(offset, length, ancestors=()):
            if (offset, length) in ancestors or len(ancestors) >= 8:
                raise ValueError('ISO continuation cycle refused')
            end = offset + length
            while offset + 4 <= end:
                head = read(offset, 4)
                if head == b'\0' * 4:
                    break
                count = head[2]
                if count < 4 or offset + count > end or head[3] != 1:
                    raise ValueError('ISO system-use record malformed')
                data = read(offset, count)
                if head[:2] == b'TF':
                    if count < 5:
                        raise ValueError('ISO Rock Ridge time flags missing')
                    width = 17 if data[4] & 128 else 7
                    number = (data[4] & 127).bit_count()
                    if count != 5 + width * number:
                        raise ValueError('ISO Rock Ridge time length mismatch')
                    for index in range(number):
                        timestamp(offset + 5 + index * width, width)
                elif head[:2] == b'CE':
                    if count != 28:
                        raise ValueError('ISO continuation length malformed')
                    block, within, total = (both(data[i:i + 8]) for i in (4, 12, 20))
                    susp(block * sector + within, total, (*ancestors, (offset, length)))
                offset += count

        def directory(block, length, prefix, joliet):
            identity = (block, length, joliet)
            if identity in directories:
                return
            if len(directories) >= MAX_ISO_DIRECTORIES:
                raise ValueError('ISO directory bound exceeded')
            directories.add(identity)
            cursor, end = block * sector, block * sector + length
            while cursor < end:
                count = read(cursor, 1)[0]
                if count == 0:
                    cursor = (cursor // sector + 1) * sector
                    continue
                record = read(cursor, count)
                if count < 34 or cursor + count > end or count < 33 + record[32] or record[25] & 128:
                    raise ValueError('ISO directory record malformed')
                extent, total = both(record[2:10]), both(record[10:18])
                timestamp(cursor + 18, 7)
                name = record[33:33 + record[32]]
                start = 33 + len(name) + (len(name) % 2 == 0)
                susp(cursor + start, count - start)
                if name not in (b'\0', b'\1'):
                    text = name.decode('utf-16-be' if joliet else 'ascii').removesuffix(';1')
                    runner_setup.relative_input(text)
                    full = prefix + text
                    if record[25] & 2:
                        directory(extent, total, full + '/', joliet)
                    elif joliet:
                        if full in members:
                            raise ValueError('ISO duplicate member')
                        value = hashlib.sha256()
                        for offset in range(0, total, 1024 * 1024):
                            value.update(read(extent * sector + offset, min(1024 * 1024, total - offset)))
                        members[full] = value.hexdigest()
                cursor += count

        descriptor_types = []
        for index in range(16, MAX_DESCRIPTOR_SECTORS):
            descriptor = read(index * sector, sector)
            kind = descriptor[0]
            if descriptor[1:7] != b'CD001\1' or kind not in (1, 2, 255):
                raise ValueError('ISO volume descriptor refused')
            descriptor_types.append(kind)
            if kind == 255:
                break
            if kind == 2 and descriptor[88:91] != b'%/E':
                raise ValueError('ISO Joliet level changed')
            timestamp(index * sector + 174, 7)
            for offset in (813, 830, 847, 864):
                timestamp(index * sector + offset, 17)
            directory(both(descriptor[158:166]), both(descriptor[166:174]), '', kind == 2)
        if descriptor_types != [1, 2, 255] or members != tree_seal(source):
            raise ValueError('exact ISO members/content or descriptor set changed')
        if apply:
            apply_timestamps(stream, fields)
        elif any(read(offset, len(value)) != value for offset, value in fields.items()):
            raise ValueError('ISO canonical timestamp drift')
    return {'raw_sha256': raw_sha, 'normalized_sha256': runner_setup.sha(path),
            'timestamp_fields': len(fields), 'field_map_sha256': digest(canonical({str(k): v.hex() for k, v in fields.items()})),
            'member_count': len(members), 'member_seal_sha256': digest(canonical(members))}


def finish_staging(bank, raw, selected, fixture, wrapper):
    """Reuse exact independently copied leaves; qualify only the ISO/seal delta."""
    _generated, _copies, runtime, tools, closure, config = prepare(raw, selected, fixture)
    expected = {'context/' + name: fingerprint for name, fingerprint in closure.items()}
    expected.update({name: row[1] for name, row in runtime.items()})
    expected.update({'tools/' + name: digest(data) for name, data in tools.items()})
    expected['tools/empty.config'] = digest(b'')
    if tree_seal(bank) != expected:
        raise ValueError('exclusive staged closure differs before ISO generation')
    private = wrapper_state(wrapper, [bank, *(Path(c.path) for c in selected.values()), Path(fixture.path)])
    if any(os.path.lexists(path) for path in config['host_refusals']):
        raise ValueError('unselected host loader configuration refused')
    iso = bank / 'context/iso'
    for path in sorted(iso.rglob('*'), reverse=True) + [iso]:
        if path.is_dir():
            os.utime(path, (EPOCH, EPOCH))
    logs, runs = [], []
    for output in ('../seed.iso', '../../repeat.iso'):
        command, environment = iso_command(bank, private, config, output)
        result = subprocess.run(command, cwd=iso, env=environment, stdout=subprocess.PIPE,
                                stderr=subprocess.STDOUT, check=True, timeout=90)
        logs.append(result.stdout.decode('utf-8', errors='replace'))
        runs.append(normalize_iso(iso / output, iso))
        if len(runs) == 1:
            time.sleep(1.1)  # Distinct actual generator clocks, then normalize defined fields.
    first, repeat = bank / 'context/seed.iso', bank / 'repeat.iso'
    if runner_setup.sha(first) != runner_setup.sha(repeat):
        raise ValueError('actual repeated ISO generation differs')
    repeat.unlink()
    first.chmod(0o444)
    os.utime(first, (EPOCH, EPOCH))
    if tree_seal(bank / 'context') != {**closure, 'seed.iso': runner_setup.sha(first)}:
        raise ValueError('actual context differs from finite closure')
    write_file(bank / 'context/context-seal.json', canonical(tree_seal(bank / 'context')))
    if prepare(raw, selected, fixture)[-1] != config:
        raise ValueError('public source/config/tool changed while staging')
    manifest = {'schema': 'vimbadmin-public-bank-v1', 'selection': SELECTION, 'config': config,
                'context_seal_sha256': runner_setup.sha(bank / 'context/context-seal.json'),
                'files': tree_seal(bank), 'iso_repeat': {'count': 2, 'sha256': runner_setup.sha(first),
                                                       'logs': logs, 'runs': runs}}
    write_file(bank / 'manifest.json', canonical(manifest))
    return {'manifest_sha256': runner_setup.sha(bank / 'manifest.json'),
            'context_seal_sha256': manifest['context_seal_sha256'], 'iso_sha256': runner_setup.sha(first),
            'context_inputs': len(closure) + 1, 'config': config}


def preflight(bank, *, expected_manifest_sha256, raw, selected, fixture, wrapper):
    """Bind a separately selected proposed identity to the actual final inert bank."""
    bank = Path(bank)
    manifest_path = regular(bank / 'manifest.json')
    if runner_setup.sha(manifest_path) != expected_manifest_sha256:
        raise ValueError('independently selected public bank manifest changed')
    manifest = runner_setup.public_record(manifest_path)
    _generated, _copies, runtime, tools, closure, config = prepare(raw, selected, fixture)
    if (manifest['schema'] != 'vimbadmin-public-bank-v1' or manifest['selection'] != SELECTION
            or manifest['config'] != config):
        raise ValueError('public bank selected source/config/tool drift')
    actual = tree_seal(bank)
    actual.pop('manifest.json')
    expected = {'context/' + name: fingerprint for name, fingerprint in closure.items()}
    expected.update({name: row[1] for name, row in runtime.items()})
    expected.update({'tools/' + name: digest(data) for name, data in tools.items()})
    expected.update({'tools/empty.config': digest(b''),
                     'context/context-seal.json': manifest['context_seal_sha256'],
                     'context/seed.iso': manifest['iso_repeat']['sha256']})
    if actual != manifest['files'] or actual != expected:
        raise ValueError('bank missing or shadowed input drift')
    seal_path = regular(bank / 'context/context-seal.json')
    seal = runner_setup.public_record(seal_path)
    if (runner_setup.sha(seal_path) != manifest['context_seal_sha256']
            or seal != {**closure, 'seed.iso': manifest['iso_repeat']['sha256']}
            or manifest['iso_repeat']['count'] != 2):
        raise ValueError('actual context/ISO seal changed')
    normalize_iso(bank / 'context/seed.iso', bank / 'context/iso', apply=False)
    private = wrapper_state(wrapper, [bank, *(Path(c.path) for c in selected.values()), Path(fixture.path)])
    return {'schema': 'vimbadmin-public-bank-preflight-v1', 'selection': SELECTION,
            'manifest_sha256': expected_manifest_sha256, 'context_seal_sha256': runner_setup.sha(seal_path),
            'iso_sha256': manifest['iso_repeat']['sha256'], 'context_inputs': len(seal),
            'docker_copy_inputs': config['docker_copy_inputs'], 'native_admission': config['native_admission'],
            'private_state': private, 'materialization_ready': True,
            'runtime_qualified': False, 'execution_allowed': False}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('action', choices=('materialize', 'preflight'))
    parser.add_argument('--descriptor', required=True, type=Path)
    parser.add_argument('--retained', required=True, type=Path)
    parser.add_argument('--fixture', required=True, type=Path)
    parser.add_argument('--wrapper-state', required=True, type=Path)
    parser.add_argument('--bank', required=True, type=Path)
    parser.add_argument('--manifest-sha256')
    args = parser.parse_args()
    selected = {name: public_recipe.RetainedFile(**value)
                for name, value in runner_setup.public_record(args.retained).items()}
    fixture = public_recipe.RetainedFile(str(args.fixture), public_recipe_selection.select(SELECTION)['alpine_origin'])
    parameters = {'raw': regular(args.descriptor).read_bytes(), 'selected': selected, 'fixture': fixture,
                  'wrapper': runner_setup.public_record(args.wrapper_state)}
    if args.action == 'materialize':
        if args.manifest_sha256 is not None:
            raise ValueError('materialization creates a NEW proposed identity')
        result = materialize(args.bank, **parameters)
    else:
        if args.manifest_sha256 is None:
            raise ValueError('independently selected manifest SHA256 required')
        result = preflight(args.bank, expected_manifest_sha256=args.manifest_sha256, **parameters)
    print(json.dumps(result, sort_keys=True))


if __name__ == '__main__':
    main()
