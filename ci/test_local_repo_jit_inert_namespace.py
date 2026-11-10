"""Focused selected-inventory contracts; production import still requires Q5c3c seals."""
import argparse
import ast
import builtins
import hashlib
import importlib.util
import json
import os
import re
import stat
import subprocess
import sys
import tempfile
import types
import unittest
from pathlib import Path
from unittest import mock

ROOT = Path(__file__).resolve().parents[1] / '.github/scripts/local-repo-jit'
sys.path.insert(0, str(ROOT))
import public_bank


def phase(source=None):
    """Extract trusted maintained definitions; this fixture is not a sandbox."""
    source = ROOT / 'native_phase.py' if source is None else source
    wanted = {'Refused', 'require', 'sha', 'inventory_contract', 'admit', 'main', 'frozen', 'inputs', 'build_selected', 'durable', 'sync_directory'}
    tree = ast.parse(source.read_text(), filename=str(source))
    selected = [node for node in tree.body if (isinstance(node, (ast.FunctionDef, ast.ClassDef)) and node.name in wanted)
                or (isinstance(node, ast.Assign) and any(isinstance(t, ast.Name) and t.id in
                    {'PUBLIC_MANIFEST', 'PUBLIC_CONTEXT', 'PUBLIC_SELECTION', 'PUBLIC_REQUEST_MODEL', 'ARCHIVE', 'CODE_SEAL'} for t in node.targets))
                or (isinstance(node, ast.Import) and any(item.name == 'subprocess' for item in node.names))]
    module = types.ModuleType('selected_native_contract')
    module.__dict__.update(argparse=argparse, hashlib=hashlib, importlib=importlib, json=json, os=os, re=re, stat=stat, subprocess=subprocess, Path=Path, ROOT=ROOT,
                           WORKSPACE=None, IMAGE_RECEIPT=None, CONTEXT_SEAL=None, V4_SEAL=None, PREFIX='fixture-native-')
    # Only callers below choose native_phase.py or producer.py from this checkout.
    # Definitions retain normal builtins; selection skips their runtime bootstrap.
    # nosemgrep: semgrep.locked.python.lang.security.audit.exec-detected.exec-detected
    exec(compile(ast.Module(body=selected, type_ignores=[]), str(source), 'exec'), module.__dict__)  # noqa: S102 - trusted maintained source
    return module


class PhaseFixture(unittest.TestCase):
    def test_maintained_sources_use_fresh_namespace_without_bootstrap(self):
        read_text = Path.read_text
        for source, expected in [(None, ROOT / 'native_phase.py'), (ROOT / 'producer.py', ROOT / 'producer.py')]:
            with self.subTest(source=source), mock.patch.object(Path, 'read_text', autospec=True, side_effect=read_text) as read, \
                    mock.patch.object(importlib.util, 'spec_from_file_location') as loader, \
                    mock.patch.object(subprocess, 'run') as command:
                first = phase(source)
                second = phase(source)
                self.assertEqual(read.call_args_list, [mock.call(expected), mock.call(expected)])
                loader.assert_not_called()
                command.assert_not_called()
                self.assertIsNot(first.__dict__, second.__dict__)
                first.WORKSPACE = 'owned fixture state'
                self.assertIsNone(second.WORKSPACE)
                for name in ('backend', 'NATIVE', 'v2', 'q2', 'request_model', 'Owner', 'Listener'):
                    self.assertNotIn(name, first.__dict__)
                # Selected definitions actually execute, including their refusal path.
                self.assertIsNone(first.require(True, 'accepted'))
                with self.assertRaisesRegex(first.Refused, 'fixture refusal'):
                    first.require(False, 'fixture refusal')
                self.assertIsNot(first.Refused, second.Refused)

    def test_unselected_bootstrap_effects_are_not_executed(self):
        source = ROOT / 'native_phase.py'
        sentinel = "raise AssertionError('unselected bootstrap executed')\n"
        text = sentinel + source.read_text()
        with mock.patch.object(Path, 'read_text', return_value=text) as read:
            module = phase()
        read.assert_called_once_with()
        self.assertIsNone(module.WORKSPACE)
        self.assertEqual(module.sha(source), hashlib.sha256(source.read_bytes()).hexdigest())
        with self.assertRaisesRegex(module.Refused, 'owned external workspace required'):
            module.inventory_contract()


class SelectedInventory(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.p = phase()
        self.p.WORKSPACE = self.root
        self.bank = self.root / 'bank'
        self.context = self.bank / 'context'
        self.context.mkdir(parents=True)
        # Small fixture leaves exercise cardinality; these are never runtime evidence.
        self.sealed = {}
        for number in range(224):
            name = str(number)
            (self.context / name).write_text(name)
            self.sealed[name] = self.p.sha(self.context / name)
        self.seal = self.context / 'context-seal.json'
        self.seal.write_text(json.dumps(self.sealed))
        self.p.PUBLIC_CONTEXT = self.p.sha(self.seal)
        self.p.CONTEXT_SEAL = self.p.PUBLIC_CONTEXT
        self.receipt = self.root / 'creation-receipt.json'
        self.p.IMAGE_RECEIPT = self.receipt
        self.value = {'image': 'sha256:' + 'a' * 64, 'project': 'vimbadmin',
                      'runlabel': 'vi-local-ci-208c2d13-v4-capacity-r3-image-q1',
                      'context_seal_sha256': self.p.PUBLIC_CONTEXT, 'runner_archive_sha256': self.p.ARCHIVE,
                      'runner_version': '2.336.0', 'runtime_qualification': 'UNQUALIFIED'}
        self.receipt.write_text(json.dumps(self.value))
        profile = public_bank.public_recipe_selection.select('public-c22-alpine-c24')
        self.manifest = {'schema': 'vimbadmin-public-bank-v1', 'selection': profile['name'],
                         'config': {'selection': profile, 'recipe_sha256': profile['recipe_sha256'],
                                    'contracts': {name: self.p.sha(ROOT / name) for name in
                                        ('native_policy.py', 'public_recipe.py', 'public_recipe_selection.py',
                                         'runner_setup.py', 'baseline_budget.py', 'guest_capacity.py')},
                                    'materializer_sha256': self.p.sha(ROOT / 'public_bank.py')},
                         'context_seal_sha256': self.p.PUBLIC_CONTEXT, 'files': public_bank.tree_seal(self.bank)}
        self.anchor()

    def anchor(self):
        path = self.bank / 'manifest.json'
        path.write_text(json.dumps(self.manifest))
        self.p.PUBLIC_MANIFEST = self.p.sha(path)

    def admit(self):
        return self.p.admit(selection='public-c22-alpine-c24', bank=self.bank)

    def test_public_selected_contract_and_admission(self):
        self.assertEqual(self.p.inventory_contract('public-c22-alpine-c24', self.bank),
                         (self.context, self.p.PUBLIC_CONTEXT, 224))
        with mock.patch.object(public_bank.runner_setup, 'verify_materialized') as historical:
            self.assertEqual(self.admit(), self.value)
            historical.assert_not_called()

    def test_unknown_or_implicit_selection_and_bank_alias_refused(self):
        for selection, bank in [('unknown', self.bank), ('historical', self.bank), ('public-c22-alpine-c24', None)]:
            with self.subTest(selection=selection), self.assertRaises((self.p.Refused, ValueError)):
                self.p.inventory_contract(selection, bank)
        alias = self.root / 'alias'
        alias.symlink_to(self.bank, target_is_directory=True)
        for bank in (alias, Path('relative')):
            with self.subTest(bank=bank), self.assertRaises(self.p.Refused):
                self.p.inventory_contract('public-c22-alpine-c24', bank)

    def test_manifest_drift_or_link_refused(self):
        path = self.bank / 'manifest.json'
        path.write_text(path.read_text() + ' ')
        with self.assertRaisesRegex(self.p.Refused, 'manifest changed'):
            self.admit()
        path.unlink()
        path.symlink_to(self.receipt)
        with self.assertRaises(self.p.Refused):
            self.admit()

    def test_contract_identity_components_fail_closed(self):
        changes = [('schema', 'other'), ('selection', 'historical'), ('context_seal_sha256', '0' * 64)]
        for key, bad in changes:
            old = self.manifest[key]
            self.manifest[key] = bad
            self.anchor()
            with self.subTest(key=key), self.assertRaisesRegex(self.p.Refused, 'contract changed'):
                self.admit()
            self.manifest[key] = old
        for key in ('selection', 'recipe_sha256'):
            old = self.manifest['config'][key]
            self.manifest['config'][key] = {} if key == 'selection' else '0' * 64
            self.anchor()
            with self.subTest(key=key), self.assertRaisesRegex(self.p.Refused, 'contract changed'):
                self.admit()
            self.manifest['config'][key] = old

    def test_bank_missing_extra_symlink_and_source_drift_refused(self):
        path = self.context / '0'
        path.unlink()
        with self.assertRaisesRegex(self.p.Refused, 'inventory changed'):
            self.admit()
        path.write_text('0')
        extra = self.bank / 'extra'
        extra.write_text('extra')
        with self.assertRaisesRegex(self.p.Refused, 'inventory changed'):
            self.admit()
        extra.unlink()
        path.unlink()
        path.symlink_to(self.receipt)
        with self.assertRaises(ValueError):
            self.admit()
        path.unlink()
        path.write_text('0')
        self.manifest['config']['contracts']['native_policy.py'] = '0' * 64
        self.anchor()
        with self.assertRaisesRegex(self.p.Refused, 'maintained source changed'):
            self.admit()

    def test_public_helpers_rejected_before_any_helper_import(self):
        sources = self.root / 'sources'
        sources.mkdir()
        names = {'public_bank.py', 'request_model.py'} | set(self.manifest['config']['contracts'])
        for name in names:
            (sources / name).write_bytes((ROOT / name).read_bytes())
        with mock.patch.object(self.p, 'ROOT', sources):
            for name in names:
                path = sources / name
                original = path.read_bytes()
                for kind in ('missing', 'drift', 'symlink'):
                    path.unlink()
                    if kind == 'drift':
                        path.write_bytes(original + b'\n# owned fixture byte drift\n')
                    elif kind == 'symlink':
                        path.symlink_to(ROOT / name)
                    with self.subTest(name=name, kind=kind), mock.patch('builtins.__import__', wraps=builtins.__import__) as imported:
                        with self.assertRaisesRegex(self.p.Refused, 'maintained source changed'):
                            self.admit()
                        self.assertFalse(any(call.args[0] in {'public_bank', 'public_recipe_selection'} for call in imported.call_args_list))
                    if path.is_symlink() or path.exists():
                        path.unlink()
                    path.write_bytes(original)

    def test_selection_guard_precedes_backend_execution(self):
        sources = self.root / 'inputs'
        sources.mkdir()
        backend = sources / 'vm_backend.py'
        backend.write_text("raise AssertionError('backend imported before helper guard')\n")
        (sources / 'native_preflight.py').write_text('pass\n')
        (sources / 'code-inputs.json').write_text(json.dumps({name: {'sha256': self.p.sha(sources / name)}
                                                           for name in ('vm_backend.py', 'native_preflight.py')}))
        helper = sources / 'public_recipe_selection.py'
        for kind in ('missing', 'drift', 'symlink'):
            if kind == 'drift':
                helper.write_text('raise AssertionError("unsafe helper")\n')
            elif kind == 'symlink':
                helper.symlink_to(ROOT / helper.name)
            with self.subTest(kind=kind), mock.patch.object(self.p, 'ROOT', sources), \
                    self.assertRaisesRegex(self.p.Refused, 'selection helper changed'):
                self.p.inputs()
            if helper.exists() or helper.is_symlink():
                helper.unlink()
        helper.write_bytes((ROOT / helper.name).read_bytes())
        backend.write_text("PREFIX = 'vi-local-ci-208c2d13-vm-q2-'\n")
        entries = json.loads((sources / 'code-inputs.json').read_text())
        entries['vm_backend.py']['sha256'] = self.p.sha(backend)
        (sources / 'code-inputs.json').write_text(json.dumps(entries))
        with mock.patch.object(self.p, 'ROOT', sources):
            loaded, native = self.p.inputs()
        self.assertEqual(loaded.PREFIX, self.p.PREFIX)
        self.assertEqual(native, 'pass\n')

    def test_producer_authenticates_declared_helpers_before_entry_effects(self):
        p = phase(ROOT / 'producer.py')
        sources = self.root / 'frozen'
        sources.mkdir()
        marker = self.root / 'effect'
        entry = sources / 'entry.py'
        entry.write_text('from pathlib import Path\nPath(' + repr(str(marker)) + ").write_text('effect')\nVALUE = 7\n")
        helper = sources / 'helper.py'
        helper.write_text('VALUE = 8\n')
        original = helper.read_bytes()
        manifest = sources / 'code-inputs.json'
        entries = {name: {'sha256': self.p.sha(sources / name)} for name in ('entry.py', 'helper.py')}
        manifest.write_text(json.dumps(entries))
        seal = self.p.sha(manifest)
        self.assertEqual(p.frozen(sources, seal, 'entry.py', 'frozen_fixture').VALUE, 7)
        marker.unlink()
        for kind in ('missing', 'drift', 'symlink'):
            helper.unlink()
            if kind == 'drift':
                helper.write_bytes(original + b'\n# drift\n')
            elif kind == 'symlink':
                helper.symlink_to(entry)
            with self.subTest(kind=kind), self.assertRaisesRegex(p.Refused, 'source closure changed'):
                p.frozen(sources, seal, 'entry.py', 'frozen_fixture')
            self.assertFalse(marker.exists())
            if helper.exists() or helper.is_symlink():
                helper.unlink()
            helper.write_bytes(original)
        for bad in ({}, {'../entry.py': entries['entry.py']}, {'entry.py': {'sha256': 'opaque'}},
                    {'entry.py': {'sha256': entries['entry.py']['sha256'], 'extra': True}}):
            manifest.write_text(json.dumps(bad))
            with self.subTest(bad=bad), self.assertRaisesRegex(p.Refused, 'closure schema'):
                p.frozen(sources, self.p.sha(manifest), 'entry.py', 'frozen_fixture')
        manifest.write_text(json.dumps(entries))
        with self.assertRaisesRegex(p.Refused, 'manifest changed'):
            p.frozen(sources, '0' * 64, 'entry.py', 'frozen_fixture')
        manifest.unlink()
        manifest.symlink_to(entry)
        with self.assertRaisesRegex(p.Refused, 'regular frozen manifest'):
            p.frozen(sources, seal, 'entry.py', 'frozen_fixture')
        self.assertFalse(marker.exists())

    def test_historical_count_fifty_unchanged(self):
        historical = self.root / 'public-capacity-context-q1'
        self.context.rename(historical)
        self.assertEqual(self.p.inventory_contract(), (historical, self.p.CONTEXT_SEAL, 50))
        with mock.patch.object(public_bank.runner_setup, 'verify_materialized') as verify:
            with self.assertRaisesRegex(self.p.Refused, 'actual public seed inputs changed'):
                self.p.admit()
            for number in range(50, 224):
                (historical / str(number)).unlink()
            (historical / 'context-seal.json').write_text(json.dumps({str(n): self.sealed[str(n)] for n in range(50)}))
            self.p.CONTEXT_SEAL = self.p.sha(historical / 'context-seal.json')
            self.value['context_seal_sha256'] = self.p.CONTEXT_SEAL
            self.receipt.write_text(json.dumps(self.value))
            self.assertEqual(self.p.admit(), self.value)
            verify.assert_called_once_with(historical)
        self.p.WORKSPACE = None
        with self.assertRaisesRegex(self.p.Refused, 'workspace required'):
            self.p.inventory_contract()

    def test_public_count_cannot_be_generic_bypass(self):
        with mock.patch.object(self.p, 'inventory_contract', return_value=(self.context, self.p.PUBLIC_CONTEXT, 224)):
            self.sealed.pop('223')
            (self.context / '223').unlink()
            self.seal.write_text(json.dumps(self.sealed))
            self.p.PUBLIC_CONTEXT = self.p.sha(self.seal)
        with mock.patch.object(self.p, 'inventory_contract', return_value=(self.context, self.p.PUBLIC_CONTEXT, 224)):
            self.value['context_seal_sha256'] = self.p.PUBLIC_CONTEXT
            self.receipt.write_text(json.dumps(self.value))
            with self.assertRaisesRegex(self.p.Refused, 'actual public seed inputs changed'):
                self.admit()

    def test_receipt_context_and_payload_errors(self):
        contract = (self.context, self.p.PUBLIC_CONTEXT, 224)
        with mock.patch.object(self.p, 'inventory_contract', return_value=contract):
            for key, bad in [('image', 'opaque'), ('project', 'other'), ('context_seal_sha256', '0' * 64),
                             ('runtime_qualification', 'QUALIFIED'), ('extra', True)]:
                changed = dict(self.value, **{key: bad})
                self.receipt.write_text(json.dumps(changed))
                with self.subTest(key=key), self.assertRaises(self.p.Refused):
                    self.admit()
            self.receipt.write_text(json.dumps(self.value))
            (self.context / '0').write_text('drift')
            with self.assertRaisesRegex(self.p.Refused, 'actual public seed inputs changed'):
                self.admit()
            (self.context / '0').write_text('0')
            (self.context / 'extra').write_text('x')
            with self.assertRaisesRegex(self.p.Refused, 'inventory changed'):
                self.admit()
            (self.context / 'extra').unlink()
            self.seal.write_text('{}')
            with self.assertRaisesRegex(self.p.Refused, 'context seal changed'):
                self.admit()
            self.receipt.unlink()
            with self.assertRaisesRegex(self.p.Refused, 'exact build receipt'):
                self.admit()
        with mock.patch.object(self.p, 'inventory_contract', return_value=(self.context, None, 224)), \
                self.assertRaisesRegex(self.p.Refused, 'materialized reviewed context'):
            self.admit()

    def test_receipt_private_directory_and_symlink(self):
        self.root.chmod(0o755)
        with self.assertRaisesRegex(self.p.Refused, 'private owned'):
            self.admit()
        self.root.chmod(0o700)
        self.receipt.unlink()
        self.receipt.symlink_to(self.seal)
        with self.assertRaisesRegex(self.p.Refused, 'exact build receipt'):
            self.admit()

    def test_selected_builder_fixture_receipt_is_consumed_by_admission(self):
        workspace = self.root / 'build'
        workspace.mkdir(mode=0o700)
        receipt = workspace / 'public-capacity-context-q1-build-receipts/creation-receipt.json'
        def transport(args, **kwargs):
            self.assertEqual(kwargs, {'check': True, 'env': {'PATH': os.defpath}})
            self.assertEqual(args[-1], str(self.context))
            self.assertIn('--network=none', args)
            self.assertIn('--pull=false', args)
            self.assertEqual(Path(args[2]).stat().st_mode & 0o777, 0o700)
            Path(args[args.index('--iidfile') + 1]).write_text(self.value['image'] + '\n')
            return subprocess.CompletedProcess(args, 0)
        with mock.patch.object(self.p, 'WORKSPACE', workspace), mock.patch.object(self.p, 'IMAGE_RECEIPT', receipt):
            value = self.p.build_selected(self.bank, transport)
            self.assertEqual(value, self.value)
            self.assertEqual(self.admit(), value)
            proof = json.loads((receipt.parent / 'build-inputs.json').read_text())
            self.assertEqual(proof['bank_manifest_sha256'], self.p.PUBLIC_MANIFEST)
            self.assertEqual(proof['context_inputs'], 224)
            with self.assertRaises(FileExistsError):
                self.p.build_selected(self.bank, transport)

    def test_selected_builder_refuses_inputs_workspace_and_receipt_aliases_before_transport(self):
        command = mock.Mock()
        with self.assertRaisesRegex(self.p.Refused, 'isolated owned build workspace'):
            self.p.build_selected(self.bank, command)
        workspace = self.root / 'build'
        workspace.mkdir(mode=0o700)
        receipt = workspace / 'public-capacity-context-q1-build-receipts/creation-receipt.json'
        with mock.patch.object(self.p, 'WORKSPACE', workspace), mock.patch.object(self.p, 'IMAGE_RECEIPT', receipt):
            workspace.chmod(0o755)
            with self.assertRaisesRegex(self.p.Refused, 'isolated owned build workspace'):
                self.p.build_selected(self.bank, command)
            workspace.chmod(0o700)
            (self.context / '0').unlink()
            with self.assertRaisesRegex(self.p.Refused, 'inventory changed'):
                self.p.build_selected(self.bank, command)
            (self.context / '0').write_text('0')
            with mock.patch.object(self.p, 'IMAGE_RECEIPT', self.receipt), self.assertRaisesRegex(self.p.Refused, 'receipt purpose'):
                self.p.build_selected(self.bank, command)
        command.assert_not_called()

    def test_selected_builder_incomplete_transport_never_publishes_receipt(self):
        for kind in ('missing', 'symlink', 'opaque', 'nonzero', 'exception'):
            workspace = self.root / ('build-' + kind)
            workspace.mkdir(mode=0o700)
            receipt = workspace / 'public-capacity-context-q1-build-receipts/creation-receipt.json'
            def transport(args, kind=kind, **kwargs):
                iid = Path(args[args.index('--iidfile') + 1])
                if kind == 'exception':
                    raise subprocess.CalledProcessError(1, args)
                if kind == 'symlink':
                    iid.symlink_to(self.receipt)
                elif kind != 'missing':
                    iid.write_text('opaque' if kind == 'opaque' else self.value['image'])
                return subprocess.CompletedProcess(args, 1 if kind == 'nonzero' else 0)
            with self.subTest(kind=kind), mock.patch.object(self.p, 'WORKSPACE', workspace), \
                    mock.patch.object(self.p, 'IMAGE_RECEIPT', receipt), mock.patch.object(subprocess, 'run', side_effect=transport), \
                    self.assertRaises((self.p.Refused, subprocess.CalledProcessError)):
                self.p.build_selected(self.bank)
            self.assertFalse(receipt.exists())
            self.assertTrue((receipt.parent / 'build-inputs.json').is_file())

    def test_cli_selected_builder_is_explicit_and_wired(self):
        self.p.sha_context_seal = lambda: None
        argv = ['native_phase.py', '--workspace', str(self.root), '--build-selected-public-bank',
                '--public-bank', str(self.bank)]
        with mock.patch.object(sys, 'argv', argv), mock.patch.object(self.p, 'build_selected') as build:
            with self.assertRaisesRegex(self.p.Refused, 'explicit NEW build selection'):
                self.p.main()
            build.assert_not_called()
        with mock.patch.object(sys, 'argv', argv + ['--recipe-selection', 'public-c22-alpine-c24']), \
                mock.patch.object(self.p, 'build_selected') as build:
            self.p.main()
            build.assert_called_once_with(self.bank)
        self.p.execute = mock.Mock()
        with mock.patch.object(sys, 'argv', ['native_phase.py', '--workspace', str(self.root), '--execute-reviewed-phase']), \
                mock.patch.object(self.p, 'admit', return_value=self.value) as admit:
            self.p.main()
        admit.assert_called_once_with(selection='historical', bank=None)
        self.assertIsNone(self.p.V4_SEAL)

    def test_cli_forwards_explicit_contract_and_preserves_authority_gate(self):
        self.p.sha_context_seal = lambda: None
        for flag, operation in [('--execute-reviewed-phase', 'execute'), ('--cleanup-reviewed-failed', 'recover')]:
            action = mock.Mock()
            setattr(self.p, operation, action)
            argv = ['native_phase.py', '--workspace', str(self.root), flag,
                    '--recipe-selection', 'public-c22-alpine-c24', '--public-bank', str(self.bank)]
            with mock.patch.object(sys, 'argv', argv), mock.patch.object(self.p, 'admit', return_value=self.value) as admit:
                self.p.main()
            admit.assert_called_once_with(selection='public-c22-alpine-c24', bank=self.bank)
            action.assert_called_once_with(self.value)
        with mock.patch.object(sys, 'argv', ['native_phase.py', '--workspace', str(self.root)]), \
                self.assertRaisesRegex(self.p.Refused, 'phase-specific FIFO grant'):
            self.p.main()


if __name__ == '__main__':
    unittest.main()
