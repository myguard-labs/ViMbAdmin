"""Explicit historical/new source coupling, with no runtime or backend execution."""
import ast
import hashlib
import json
import os
import sys
import tempfile
import types
import unittest
from pathlib import Path
from unittest import mock

ROOT = Path(__file__).resolve().parents[1] / '.github/scripts/local-repo-jit'
sys.path.insert(0, str(ROOT))
import baseline_budget as budget
import owned_overlay
import public_recipe_selection as identities
import public_templates as templates
import runner_setup
import vm_backend
from test_local_repo_jit_public_templates import descriptor

NEW = 'public-c22-alpine-c24'


class Selection(unittest.TestCase):
    def test_fixed_identities_and_independent_budgets(self):
        old, new = budget.selected(), budget.selected(NEW)
        self.assertEqual(old['base_sha256'], '04e8f2461a16a44e78a370284471f29616cdd9baf1893df25807b71f985b6c94')
        self.assertEqual(old['virtual_bytes'], 5368709120)
        self.assertEqual(new['base_sha256'], '24036a8b79cbb11d809752c60f2f885b1a3b02b1d663faa0b648eb87bd7a256e')
        self.assertEqual(new['root_start_bytes'], 134217728)
        self.assertEqual(new['gpt_tail_bytes'], 16896)
        self.assertEqual(new['virtual_bytes'], 4294967296)
        self.assertGreaterEqual(new['virtual_bytes'] - new['root_start_bytes'] - new['gpt_tail_bytes'],
                                3221225472 + 268435456 + 536870912)
        self.assertEqual(new['alpine_sha256'], '8cf1273f37e9fc9c54f5f55f0935beb52c0f0afe5228d8161fbe872199f4d36e')
        self.assertEqual(new['alpine_image'], 'sha256:411bc66a7adb849165d4e733707efe59d6b5edf8b4d96506cb6a1e31ba270199')
        self.assertEqual(new['alpine_bytes'], 8089600)
        self.assertTrue(new['alpine_origin'].endswith(new['alpine_manifest']))
        new['base_sha256'] = 'changed-copy'
        self.assertNotEqual(identities.select(NEW)['base_sha256'], 'changed-copy')
        for bad in (None, '', 'public', {}, 1):
            with self.subTest(bad=bad), self.assertRaisesRegex(ValueError, 'selection required'):
                budget.selected(bad)

    def test_lifecycle_actual_script_uses_selected_archive_and_config(self):
        self.assertEqual(vm_backend.lifecycle_script(), vm_backend.LIFECYCLE)
        new = vm_backend.lifecycle_script(NEW)
        ast.parse(new)
        self.assertIn(identities.PUBLIC['alpine_sha256'], new)
        self.assertIn(identities.PUBLIC['alpine_image'], new)
        self.assertNotIn(vm_backend.ALPINE_SHA, new)
        self.assertNotIn(vm_backend.ALPINE, new)
        with self.assertRaises(ValueError):
            vm_backend.lifecycle_script('unknown')

    def test_backend_cli_forwards_only_explicit_selected_lifecycle_script(self):
        with tempfile.TemporaryDirectory() as temp:
            for index, flags in enumerate(([], ['--recipe-selection', NEW])):
                destination = Path(temp) / str(index)
                owner = mock.Mock()
                owner.create.side_effect = [{'id': 'first'}, {'id': 'second'}]
                owner.guest.side_effect = ['{"boot_id":"old"}', '{"boot_id":"new"}', '']
                owner.guest_response.return_value = {'exitcode': 1, 'err-data': 'AssertionError'}
                argv = ['vm_backend.py', 'lifecycle', '--execute-reviewed-stage', '--image', 'sha256:' + 'a' * 64,
                        '--receipt-dir', str(destination), *flags]
                with mock.patch.object(sys, 'argv', argv), mock.patch.object(vm_backend, 'Owned', return_value=owner):
                    vm_backend.main()
                expected = vm_backend.lifecycle_script(NEW if flags else 'historical')
                self.assertEqual(owner.guest.call_args_list[0].args, ({'id': 'first'}, 'python3 -', expected))
                self.assertTrue(json.loads((destination / 'lifecycle.json').read_text())['dirty_rejected'])
            with mock.patch.object(sys, 'argv', ['vm_backend.py', 'lifecycle', '--image', 'x', '--receipt-dir', str(Path(temp) / 'refused')]), \
                    mock.patch.object(vm_backend, 'Owned') as owned, self.assertRaisesRegex(vm_backend.Refused, 'stage flag'):
                vm_backend.main()
            owned.assert_not_called()

    def test_selected_overlay_consumes_budget_and_refuses_other_base(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            base = root / 'base'
            base.write_bytes(b'selected-base')
            work = root / 'work'
            work.mkdir(mode=0o700)
            digest = hashlib.sha256(base.read_bytes()).hexdigest()
            calls = []
            def command(args, **kwargs):
                calls.append(args)
                if args[1] == 'create':
                    self.assertEqual(args[-1], '4294967296')
                    os.write(kwargs['pass_fds'][0], b'owned-overlay')
                    return types.SimpleNamespace(returncode=0)
                metadata = {'format': 'qcow2', 'virtual-size': 3221225472 if args[-1] == str(base) else 4294967296}
                if args[-1] != str(base):
                    metadata['backing-filename'] = str(base)
                return types.SimpleNamespace(stdout=json.dumps(metadata))
            with mock.patch.dict(identities.PUBLIC, base_sha256=digest):
                for selection, expected in [('historical', digest), (NEW, '0' * 64), ('wrong', digest)]:
                    with self.subTest(selection=selection), self.assertRaises(ValueError):
                        owned_overlay.fresh_overlay(base, expected, work, command, selection=selection)
                self.assertEqual(calls, [])
                receipt = owned_overlay.fresh_overlay(base, digest, work, command, selection=NEW)
            self.assertEqual(receipt['recipe_selection'], NEW)
            self.assertEqual(receipt['overlay_virtual_bytes'], 4294967296)
            self.assertEqual(receipt['root_start_bytes'], 134217728)
            def good(*_args, **_kwargs):
                return types.SimpleNamespace(stdout='{"format":"qcow2","virtual-size":4294967296}')
            owned_overlay.standalone(work / 'owned.qcow2', good, selection=NEW)
            with self.assertRaises(ValueError):
                owned_overlay.standalone(work / 'owned.qcow2', good)


class Generation(unittest.TestCase):
    def setUp(self):
        temp = tempfile.TemporaryDirectory()
        self.addCleanup(temp.cleanup)
        self.root = Path(temp.name)
        self.descriptor = descriptor()
        self.source = templates.sources(ROOT)
        # Synthetic accepted identity permits real file verification without the
        # 208 public payloads; the actual retained set is checked separately.
        self.profile = dict(identities.PUBLIC)
        base, = (item for item in self.descriptor.artifacts if item.kind == 'base')
        self.profile.update(recipe_sha256=self.descriptor.sha256, base_sha256=base.sha256,
                            base_bytes=base.bytes, alpine_sha256=hashlib.sha256(b'fixture').hexdigest(), alpine_bytes=7)
        self.source['public_recipe_selection.py'] = 'PUBLIC = ' + repr(self.profile) + '\nHISTORICAL = ' + repr(identities.HISTORICAL)
        self.fixture = self.root / 'alpine.tar'
        self.fixture.write_bytes(b'fixture')
        self.choice = templates.public_recipe.RetainedFile(str(self.fixture), self.profile['alpine_origin'])
        patch = mock.patch.object(templates, 'sources', return_value=self.source)
        patch.start()
        self.addCleanup(patch.stop)
        patch = mock.patch.object(templates.public_recipe, 'verify_retained', return_value={'recipe_sha256': self.descriptor.sha256})
        self.verify = patch.start()
        self.addCleanup(patch.stop)

    def generate(self, **changes):
        args = {'expected_sha256': self.descriptor.sha256, 'selection': NEW, 'fixture': self.choice}
        args.update(changes)
        return templates.generate(self.descriptor.canonical, {}, **args)

    def test_new_selection_changes_real_provision_template_and_plan(self):
        result = self.generate()
        self.assertIn(self.profile['alpine_sha256'].encode(), result.files['iso/provision.py'])
        fixture = result.plan['dependencies']['iso/alpine.tar']
        self.assertEqual(fixture['image'], 'sha256:411bc66a7adb849165d4e733707efe59d6b5edf8b4d96506cb6a1e31ba270199')
        self.assertTrue(fixture['public_origin_accepted'])
        self.assertEqual(fixture['bytes'], 7)
        self.assertFalse(result.plan['runtime_qualified'])
        self.assertFalse(result.plan['execution_allowed'])
        self.assertEqual(result, self.generate())

    def test_missing_drift_hash_alias_and_wrong_selection_refusals(self):
        for changes in ({'selection': 'unknown'}, {'selection': []}, {'selection': 'historical'},
                        {'selection': None}, {'fixture': None},
                        {'fixture': templates.public_recipe.RetainedFile(str(self.fixture), 'https://wrong.example/')},
                        {'fixture': templates.public_recipe.RetainedFile(str(self.root / 'missing'), self.choice.origin)}):
            with self.subTest(changes=changes), self.assertRaises(ValueError):
                self.generate(**changes)
        self.verify.assert_not_called()
        for content in (b'', b'fixture!', b'corrupt'):
            self.fixture.write_bytes(content)
            with self.subTest(content=content), self.assertRaises(ValueError):
                self.generate()
        self.fixture.write_bytes(b'fixture')
        alias = self.root / 'alias'
        alias.symlink_to(self.fixture)
        with self.assertRaises(ValueError):
            self.generate(fixture=templates.public_recipe.RetainedFile(str(alias), self.choice.origin))
        self.profile['base_bytes'] += 1
        self.source['public_recipe_selection.py'] = 'PUBLIC = ' + repr(self.profile) + '\nHISTORICAL = ' + repr(identities.HISTORICAL)
        with self.assertRaisesRegex(ValueError, 'selection/base'):
            self.generate()

    def test_explicit_historical_selection_preserves_old_pins_and_unknown_origin(self):
        data = json.loads(self.descriptor.canonical)
        base = next(item for item in data['artifacts'] if item['kind'] == 'base')
        base.update(sha256=identities.HISTORICAL['base_sha256'], bytes=621284352)
        raw = json.dumps(data).encode()
        desc = templates.public_recipe.parse_descriptor(raw)
        self.verify.return_value = {'recipe_sha256': desc.sha256}
        result = templates.generate(raw, {}, expected_sha256=desc.sha256, selection='historical')
        self.assertIn(vm_backend.ALPINE_SHA.encode(), result.files['iso/provision.py'])
        self.assertFalse(result.plan['dependencies']['iso/alpine.tar']['public_origin_accepted'])
        self.assertIsNone(result.plan['dependencies']['iso/alpine.tar']['origin'])

    def test_historical_file_transform_and_maintained_helper_closure(self):
        result = templates._render(self.descriptor, self.source)
        source = self.root / 'source'
        for name, raw in result.files.items():
            target = source / name
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(raw)
        (source / 'iso/install-runner.py').write_text(runner_setup.INSTALL)
        generated = runner_setup.capacity_sources(source)
        self.assertNotIn("selection='public-c22-alpine-c24'", generated['outer.py'])
        self.assertIn('VIRTUAL_BYTES = 6442450944', generated['outer.py'])
        helpers = ('owned_overlay.py', 'baseline_budget.py', 'public_recipe_selection.py',
                   'guest_capacity.py', 'failure_observation.py')
        output = self.root / 'synthetic-context'
        for name, text in generated.items():
            target = output / name
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_text(text)
        for name in helpers:
            self.assertEqual((output / name).read_bytes(), (ROOT / name).read_bytes())
            self.assertIn(name, generated['Dockerfile'])
        closure = {name: runner_setup.sha(output / name) for name in generated}
        plan = {'public_input_dir': '/synthetic-bank', 'archive': '/synthetic-runner',
                'request': None, 'closure': closure}
        (output / 'source-preflight.json').write_text(json.dumps(plan))
        seal = dict(closure, **{'source-preflight.json': runner_setup.sha(output / 'source-preflight.json')})
        (output / 'context-seal.json').write_text(json.dumps(seal))
        with mock.patch.object(runner_setup, 'materialization_preflight', return_value=(None, None, None, plan)):
            runner_setup.verify_materialized(output)
            target = output / 'public_recipe_selection.py'
            target.write_bytes(target.read_bytes() + b'\n# synthetic drift\n')
            seal['public_recipe_selection.py'] = runner_setup.sha(target)
            (output / 'context-seal.json').write_text(json.dumps(seal))
            with self.assertRaisesRegex(ValueError, 'maintained capacity helper drift'):
                runner_setup.verify_materialized(output)

    def test_new_recipe_preflight_connects_outer_overlay_and_installer(self):
        result = self.generate()
        # Only the recipe verifier is synthetic; source transformation and all
        # generated consumer code run through their actual maintained APIs.
        with mock.patch.dict(identities.PUBLIC, self.profile):
            generated, plan = runner_setup.public_recipe_preflight(self.descriptor.canonical, {},
                expected_sha256=self.descriptor.sha256, selection=NEW, fixture=self.choice)
        outer = generated['outer.py'].decode()
        self.assertIn('VIRTUAL_BYTES = 4294967296', outer)
        self.assertEqual(outer.count("selection='public-c22-alpine-c24'"), 2)
        self.assertIn('public_recipe_selection.py', generated['Dockerfile'].decode())
        self.assertEqual(generated['base.sha256'], result.files['base.sha256'])
        self.assertIn('public_recipe_selection.py', plan['docker_copy_inputs'])
        for name, raw in generated.items():
            if name.endswith('.py'):
                ast.parse(raw, filename=name)
        self.assertEqual(plan['capacity_coupling']['budget']['root_start_bytes'], 134217728)
        self.assertEqual(plan['pending'], ['bank-materialization-and-iso', 'namespace', 'causal-runtime-qualification'])
        self.assertFalse(plan['runtime_qualified'])
        self.assertFalse(plan['execution_allowed'])
        texts = {name: raw.decode() for name, raw in result.files.items()}
        texts['iso/install-runner.py'] = runner_setup.INSTALL
        with self.assertRaisesRegex(ValueError, 'recipe/base'):
            runner_setup.capacity_sources(Path('/unused'), texts=texts, selection=NEW)
        texts['outer.py'] = texts['outer.py'].replace('BASE_SHA256 =', 'UNBOUND =')
        with self.assertRaisesRegex(ValueError, 'recipe/base'):
            runner_setup.capacity_sources(Path('/unused'), texts=texts, selection=NEW)
        with mock.patch.object(templates, 'generate', return_value=result), self.assertRaisesRegex(ValueError, 'new public'):
            runner_setup.public_recipe_preflight(b'', {}, expected_sha256='', selection='historical', fixture=None)


if __name__ == '__main__':
    unittest.main()
