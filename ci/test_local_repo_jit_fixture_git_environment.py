"""Fixture Git calls cannot inherit another repository's index or configuration."""

import hashlib
import os
import subprocess
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

import test_doveadm_native_driver as native
import test_local_repo_jit_consumer_binding as consumer


class FixtureGitEnvironment(unittest.TestCase):
    def setUp(self):
        self.directory = Path(
            self.enterContext(tempfile.TemporaryDirectory(prefix='fixture-git-env-'))
        )
        self.environment = {
            key: value
            for key, value in os.environ.items()
            if not key.startswith('GIT_')
        }
        self.environment.update(
            GIT_OPTIONAL_LOCKS='0',
            GIT_CONFIG_NOSYSTEM='1',
            GIT_CONFIG_GLOBAL=os.devnull,
        )
        self.foreign = self.directory / 'foreign'
        self.foreign.mkdir()
        self.git(self.foreign, 'init', '--quiet')
        (self.foreign / 'marker').write_text('foreign index entry\n')
        self.git(self.foreign, 'add', '--', 'marker')
        # rev-parse HEAD needs only a syntactically valid direct HEAD here.
        (self.foreign / '.git/HEAD').write_text('a' * 40 + '\n')
        self.config = self.directory / 'injected.config'
        self.ignore = self.directory / 'injected.ignore'
        self.ignore.write_text('marker\n')
        self.config.write_text('[core]\n\texcludesFile = ' + str(self.ignore) + '\n')
        self.home = self.directory / 'home'
        self.home.mkdir()
        (self.home / '.gitconfig').write_bytes(self.config.read_bytes())

    def git(self, repository, *arguments):
        return subprocess.check_output(
            ['git', '-C', str(repository), *arguments], env=self.environment, text=True
        ).strip()

    def snapshot(self, repository):
        return {
            str(path.relative_to(repository)): (
                hashlib.sha256(path.read_bytes()).hexdigest(),
                path.stat().st_mtime_ns,
            )
            for path in repository.rglob('*')
            if path.is_file()
        }

    def poison(self, mode):
        values = {
            'directory': {'GIT_DIR': str(self.foreign / '.git')},
            'worktree': {'GIT_WORK_TREE': str(self.foreign)},
            'index': {'GIT_INDEX_FILE': str(self.foreign / '.git/index')},
            'common': {'GIT_COMMON_DIR': str(self.foreign / '.git')},
            'objects': {'GIT_OBJECT_DIRECTORY': str(self.foreign / '.git/objects')},
            'count': {
                'GIT_CONFIG_COUNT': '1',
                'GIT_CONFIG_KEY_0': 'core.excludesFile',
                'GIT_CONFIG_VALUE_0': str(self.ignore),
            },
            'parameters': {
                'GIT_CONFIG_PARAMETERS': "'core.excludesFile=" + str(self.ignore) + "'"
            },
            'global': {'GIT_CONFIG_GLOBAL': str(self.config)},
            'system': {
                'GIT_CONFIG_SYSTEM': str(self.config),
                'GIT_CONFIG_NOSYSTEM': '0',
            },
            'home': {'HOME': str(self.home)},
        }
        values['combined'] = values['directory'] | values['worktree'] | values['index']
        # Preserve non-Git process settings, with all poison targets disposable.
        environment = dict(self.environment, **values[mode])
        if mode == 'home':
            environment.pop('GIT_CONFIG_GLOBAL')
        return environment

    def exercise_native(self, environment):
        repository = Path(
            self.enterContext(
                tempfile.TemporaryDirectory(prefix='fixture-', dir=self.directory)
            )
        )

        class Probe(native.NativeDriver):
            repo = repository

        before = self.snapshot(self.foreign)
        with patch.dict(os.environ, environment, clear=True):
            inherited = dict(os.environ)
            try:
                Probe.git('init', '--quiet')
                (repository / 'marker').write_text('intended fixture entry\n')
                Probe.git('add', '--', 'marker')
                tree = Probe.git('write-tree').strip()
                actual = Probe.git('show', tree + ':marker')
            except subprocess.CalledProcessError as error:
                self.fail(
                    'fixture Git inherited foreign state: ' + str(error.returncode)
                )
            self.assertTrue(
                dict(os.environ) == inherited, 'helper changed the caller environment'
            )
        self.assertEqual(
            self.snapshot(self.foreign),
            before,
            'fixture Git mutated the foreign repository',
        )
        self.assertTrue(
            (repository / '.git/index').is_file(), 'fixture index was redirected'
        )
        self.assertEqual(
            actual,
            'intended fixture entry\n',
            'fixture tree came from the foreign worktree',
        )

    def test_native_location_variables_cannot_redirect_fixture_writes(self):
        for mode in ('directory', 'worktree', 'index', 'common', 'objects', 'combined'):
            with self.subTest(mode=mode):
                self.exercise_native(self.poison(mode))

    def test_native_config_injection_cannot_redirect_fixture_writes(self):
        for mode in ('count', 'parameters', 'global', 'system', 'home'):
            with self.subTest(mode=mode):
                self.exercise_native(self.poison(mode))

    def test_checkout_identity_ignores_foreign_location_and_configuration(self):
        repository = self.directory / 'checkout'
        repository.mkdir()
        self.git(repository, 'init', '--quiet')
        (repository / '.git/HEAD').write_text('b' * 40 + '\n')
        before = self.snapshot(self.foreign)
        checkout_before = self.snapshot(repository)
        for mode in (
            'directory',
            'worktree',
            'index',
            'common',
            'objects',
            'combined',
            'count',
            'parameters',
            'global',
            'system',
            'home',
        ):
            with (
                self.subTest(mode=mode),
                patch.object(consumer, 'CHECKOUT', repository),
                patch.dict(os.environ, self.poison(mode), clear=True),
            ):
                inherited = dict(os.environ)
                try:
                    actual = consumer.checkout_head()
                except subprocess.CalledProcessError as error:
                    self.fail(
                        'checkout identity inherited foreign state: '
                        + str(error.returncode)
                    )
                self.assertEqual(
                    actual,
                    'b' * 40,
                    'checkout identity came from the foreign repository',
                )
                self.assertTrue(
                    dict(os.environ) == inherited,
                    'helper changed the caller environment',
                )
        self.assertEqual(
            self.snapshot(self.foreign),
            before,
            'identity query mutated the foreign repository',
        )
        self.assertEqual(
            self.snapshot(repository),
            checkout_before,
            'identity query mutated the checkout',
        )

    def test_helpers_scrub_git_overrides_without_changing_the_caller(self):
        environment = self.poison('combined') | {
            'GIT_CONFIG': str(self.config),
            'GIT_CONFIG_PARAMETERS': "'core.bare=true'",
            'GIT_CONFIG_COUNT': '1',
            'GIT_CONFIG_KEY_0': 'core.bare',
            'GIT_CONFIG_VALUE_0': 'true',
            'GIT_ALTERNATE_OBJECT_DIRECTORIES': str(self.foreign / '.git/objects'),
            'GIT_NAMESPACE': 'foreign',
            'GIT_SHALLOW_FILE': str(self.directory / 'shallow'),
            'GIT_CEILING_DIRECTORIES': str(self.directory),
            'GIT_FUTURE_OVERRIDE': 'foreign',
        }
        with (
            patch.dict(os.environ, environment, clear=True),
            patch.object(subprocess, 'check_output', return_value='b' * 40) as command,
        ):
            inherited = dict(os.environ)
            consumer.checkout_head()
            with patch.object(native.NativeDriver, 'repo', self.foreign, create=True):
                native.NativeDriver.git('rev-parse', 'HEAD')
            self.assertTrue(
                dict(os.environ) == inherited, 'helper changed the caller environment'
            )
        self.assertEqual(command.call_count, 2)
        for call in command.call_args_list:
            self.assertEqual(
                {
                    key: value
                    for key, value in call.kwargs['env'].items()
                    if key.startswith('GIT_')
                },
                {
                    'GIT_OPTIONAL_LOCKS': '0',
                    'GIT_CONFIG_NOSYSTEM': '1',
                    'GIT_CONFIG_GLOBAL': os.devnull,
                },
            )

    def test_cross_owner_trust_is_scoped_to_each_intended_repository(self):
        original = subprocess.check_output

        def different_owner(arguments, **kwargs):
            self.assertIn(
                'safe.directory=' + str(kwargs.get('cwd', self.foreign)), arguments
            )
            self.assertNotIn('safe.directory=*', arguments)
            self.assertEqual(kwargs['env']['GIT_OPTIONAL_LOCKS'], '0')
            kwargs['env'] = dict(kwargs['env'], GIT_TEST_ASSUME_DIFFERENT_OWNER='1')
            return original(arguments, **kwargs)

        with patch.object(subprocess, 'check_output', side_effect=different_owner):
            with patch.object(consumer, 'CHECKOUT', self.foreign):
                self.assertEqual(consumer.checkout_head(), 'a' * 40)
            self.exercise_native(self.environment)


if __name__ == '__main__':
    unittest.main()
