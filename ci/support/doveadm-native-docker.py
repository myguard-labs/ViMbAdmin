#!/usr/bin/env python3
"""Strict local Docker CLI fake for the canonical native driver's contract tests."""
import json
import os
import signal
import sys
from pathlib import Path


def main():
    path = Path(os.environ['NATIVE_DOCKER_STATE'])
    state = json.loads(path.read_text())
    args = sys.argv[1:]
    state['calls'].append(args)
    operation = 'image-inspect' if args[:2] == ['image', 'inspect'] else args[0]
    state.setdefault('events', []).append(['docker', operation])
    scenario = state['scenario']
    cid, iid = 'b' * 64, 'sha256:' + 'c' * 64
    foreign_cid, foreign_iid = 'd' * 64, 'sha256:' + 'e' * 64
    code, output = 0, ''

    def option(flag):
        return args[args.index(flag) + 1]

    def labels():
        return dict(args[i + 1].split('=', 1) for i, arg in enumerate(args) if arg == '--label')

    def finish():
        path.write_text(json.dumps(state))
        if output:
            print(output)
        return code

    if args[:2] in (['container', 'ls'], ['image', 'ls']):
        kind = args[0]
        if '--no-trunc' in args:
            expected = {f'label={key}={value}' for key, value in state.get('labels', {}).items()}
            actual = {args[i + 1] for i, arg in enumerate(args) if arg == '--filter'}
            assert actual == expected and len(actual) == 3, 'recovery must use all ownership labels'
            output = (cid if kind == 'container' else iid) if state.get(kind) else ''
            if scenario == 'recovery-query-failure':
                code = 57
            if scenario == 'ambiguous-recovery' and output:
                output += '\n' + output
        elif scenario == 'preexisting-' + kind:
            output = foreign_cid if kind == 'container' else foreign_iid
    elif args[0] == 'build':
        state['labels'] = labels()
        state['image'] = iid
        state['image_receipt'] = option('--iidfile')
        state['context'] = args[-1]
        assert Path(args[-1], '.doveadm-source-manifest.json').is_file()
        if scenario not in ('build-no-receipt', 'build-term-no-receipt', 'recovery-query-failure', 'ambiguous-recovery'):
            Path(state['image_receipt']).write_text('malformed' if scenario == 'malformed-image' else iid)
        if scenario.startswith('build-') or scenario in ('recovery-query-failure', 'ambiguous-recovery'):
            code = 43
        if scenario == 'build-fails-before-resource':
            state['image'] = ''
    elif args[0] == 'create':
        if not state.get('image') or args[-4] == 'malformed':
            code = 125
            return finish()
        state['container'] = cid
        state['name'] = option('--name')
        state['container_labels'] = labels()
        state['container_receipt'] = option('--cidfile') if '--cidfile' in args else str(Path(state['context'], 'container.id'))
        assert args[-4] in (iid, 'vimbadmin-doveadm-native:' + os.environ['DOVEADM_PHP_MINOR'].replace('.', ''))
        assert args[-3:] == ['php', 'ci/test-refactor-doveadm-contract.php', '--native-curl-init-failure']
        for flag in ('--network', '--read-only', '--cap-drop', '--security-opt', '--tmpfs', '--pids-limit'):
            assert flag in args, f'missing native isolation flag: {flag}'
        assert option('--network') == 'none'
        assert option('--cap-drop') == 'ALL'
        assert option('--security-opt') == 'no-new-privileges'
        assert option('--pids-limit') == '128'
        if '--cidfile' in args and scenario not in ('create-no-receipt', 'create-term-no-receipt', 'create-int-no-receipt', 'create-missing'):
            Path(state['container_receipt']).write_text('malformed' if scenario == 'malformed-container' else cid)
        if scenario.startswith('create-'):
            code = 47
        if scenario == 'create-missing':
            state['container'] = ''
        output = cid
    elif args[0] == 'inspect' or args[:2] == ['image', 'inspect']:
        kind = 'container' if args[0] == 'inspect' else 'image'
        identity = cid if kind == 'container' else iid
        assert args[-1] in (identity, state.get('name') if kind == 'container' else
                            'vimbadmin-doveadm-native:' + os.environ['DOVEADM_PHP_MINOR'].replace('.', ''))
        state[kind + '_inspects'] = state.get(kind + '_inspects', 0) + 1
        count = state[kind + '_inspects']
        fields = [identity]
        if kind == 'container':
            fields.append('/' + state['name'])
        values = state['container_labels'] if kind == 'container' else state['labels']
        fields.extend(values[key] for key in ('myguard.run', 'myguard.nonce', 'myguard.source-tree'))
        if scenario == 'foreign-' + kind or (scenario == 'cleanup-foreign-' + kind and count == 2):
            fields[-1] = 'foreign-tree'
        if scenario == 'absent-' + kind:
            state[kind] = ''
        if not state.get(kind):
            code = 1
        else:
            output = ' '.join(fields)
        # The admitted original is replaced immediately AFTER its identity check.
        if scenario == 'replace' and (kind == 'image' or 'started' in state):
            state['foreign_' + kind] = foreign_cid if kind == 'container' else foreign_iid
    elif args[:2] == ['start', '--attach']:
        if not state.get('container') or args[2] != cid:
            code = 125
            return finish()
        state['started'] = cid
        if scenario == 'native-failure':
            code = 37
        if scenario == 'receipt-tamper':
            Path(state['container_receipt']).write_text(foreign_cid)
            Path(state['image_receipt']).write_text(foreign_iid)
    elif args[:2] == ['rm', '--force'] or args[:2] == ['image', 'rm']:
        kind = 'container' if args[0] == 'rm' else 'image'
        identity = cid if kind == 'container' else iid
        if args[-1] != identity:
            assert args[-1] == (state.get('name') if kind == 'container' else
                                'vimbadmin-doveadm-native:' + os.environ['DOVEADM_PHP_MINOR'].replace('.', ''))
            identity = state.get('foreign_' + kind, identity)
        if scenario == 'cleanup-failure-' + kind:
            code = 59
        elif kind == 'image' and identity == iid and state.get('container'):
            code = 1  # Docker refuses an unforced removal referenced by a container.
        else:
            if identity == state.get('foreign_' + kind):
                del state['foreign_' + kind]
            else:
                state[kind] = ''
            state.setdefault('removed', []).append(identity)
    else:
        raise AssertionError(f'unsupported Docker command: {args!r}')
    result = finish()
    if scenario.startswith(args[0] + '-term-'):
        os.kill(os.getppid(), signal.SIGTERM)
    if args[0] == 'create' and '-int-' in scenario:
        os.kill(os.getppid(), signal.SIGINT)
    return result


if __name__ == '__main__':
    sys.exit(main())
