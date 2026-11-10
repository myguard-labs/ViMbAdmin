"""Fake API transcripts; no credentials, networking, listener or runtime actions."""

import copy
import json
import unittest
from unittest.mock import patch

from ci.test_local_repo_jit_request import producer, records, request_fixture

api = producer.v2
ERRORS = (api.Refused, producer.Refused, producer.request_model.Refused)


class Backend:
    def __init__(self, request):
        self.binding = request
        self.events = []
        self.rid = 61
        self.run = {'id': request['run_id'], 'run_attempt': request['run_attempt'],
                    'head_sha': request['candidate_sha'], 'repository': {'full_name': api.REPO}}
        self.job = {'id': request['job_id'], 'run_id': request['run_id'],
                    'run_attempt': request['run_attempt'], 'head_sha': request['candidate_sha'],
                    'status': 'queued', 'runner_id': None,
                    'labels': api.scoped_job_labels(request['run_id'], request['run_attempt'])}
        self.runner = {'id': self.rid, 'name': request['runner_name'], 'busy': False,
                       'labels': [{'name': label} for label in self.job['labels']]}
        self.post = {'runner': copy.deepcopy(self.runner), 'encoded_jit_config': 'fixture-private'}
        self.statuses = {}
        self.failure = None
        self.receipt = api.VerifiedJobReceipt(
            producer.request_model.request_digest(request), api.REPO, request['candidate_sha'],
            request['qualification_id'], request['admission_id'], 1000, 1300)
        self.unused = True

    def guard(self):
        self.events.append('guard')

    def verify(self, request):
        self.events.append('verify')
        if request != self.binding:
            raise api.Refused('trusted current identity changed')
        return self.receipt

    def claim_unused(self, receipt):
        self.events.append('claim-unused')
        if receipt != self.receipt or not self.unused:
            return False
        self.unused = False
        return True

    def transport(self, method, path, body):
        self.events.append((method, path, copy.deepcopy(body)))
        if self.failure == (method, path):
            raise api.Refused('fixture transport failure')
        if path == 'actions/runs/' + str(self.binding['run_id']):
            result, status = self.run, 200
        elif path == 'actions/jobs/' + str(self.binding['job_id']):
            result, status = self.job, 200
        elif method == 'POST':
            result, status = self.post, 201
        elif method == 'DELETE':
            result, status = {}, 204
        else:
            result, status = self.runner, 200
        return self.statuses.get((method, path), status), copy.deepcopy(result)

    def listener(self, rid, payload):
        # Payload is checked but never retained in the transcript.
        if rid != self.rid or payload != 'fixture-private':
            raise AssertionError('unbound handoff')
        self.events.append(('handoff', rid))
        return 'fixture-process'

    def calls(self, method=None):
        return [event for event in self.events
                if isinstance(event, tuple) and len(event) == 3
                and (method is None or event[0] == method)]


class Binding(unittest.TestCase):
    def setUp(self):
        self.request = request_fixture()
        self.qualification, self.admission = records(self.request)
        self.backend = Backend(self.request)
        self.network = patch('urllib.request.urlopen', side_effect=AssertionError('network forbidden'))
        self.process = patch('subprocess.run', side_effect=AssertionError('subprocess forbidden'))
        self.network.start()
        self.process.start()
        self.addCleanup(self.network.stop)
        self.addCleanup(self.process.stop)

    def client(self, **overrides):
        kwargs = {'qualification': self.qualification, 'admission': self.admission,
                  'transport': self.backend.transport, 'verifier': self.backend,
                  'guard': self.backend.guard, 'clock': lambda: 1001}
        kwargs.update(overrides)
        return producer.QualifiedJobAPI(self.request, **kwargs)

    def begin(self, client=None):
        return (client or self.client()).begin(1, self.backend.listener)

    def test_exact_normal_transcript_and_owned_cleanup(self):
        client = self.client()
        self.assertEqual(self.begin(client), (61, 'fixture-process'))
        labels = api.scoped_job_labels(312, 2)
        self.assertEqual(self.backend.events, [
            'guard', 'verify', 'claim-unused', 'guard',
            ('GET', 'actions/runs/312', None), ('GET', 'actions/jobs/17', None), 'guard',
            ('POST', 'actions/runners/generate-jitconfig',
             {'name': self.request['runner_name'], 'runner_group_id': 1,
              'labels': labels, 'work_folder': '_work'}),
            ('GET', 'actions/runners/61', None), 'guard', ('handoff', 61)])
        self.assertFalse(self.backend.unused)
        self.assertEqual(client.owned_runners, {61: self.request['runner_name']})
        client.delete_idle(61)
        self.assertEqual(client.owned_runners, {})
        self.assertEqual(self.backend.calls('DELETE'), [('DELETE', 'actions/runners/61', None)])
        with self.assertRaisesRegex(api.Refused, 'one admission'):
            self.begin(client)
        with self.assertRaisesRegex(api.Refused, 'foreign'):
            client.delete_idle(999)

    def test_order_independent_exact_labels_and_zero_unassigned_runner(self):
        self.backend.job['runner_id'] = 0
        self.backend.post['runner']['labels'].reverse()
        self.backend.runner['labels'].reverse()
        self.begin()
        self.assertEqual(self.backend.events[-1], ('handoff', 61))

    def test_no_provider_or_transport_can_enable_live_path(self):
        for kwargs in ({'external': True}, {'external': 1}, {'transport': None},
                       {'transport': 0}, {'verifier': None}, {'verifier': {}}, {'guard': None}):
            with self.subTest(kwargs=kwargs), self.assertRaises(ERRORS):
                self.client(**kwargs)
        self.assertEqual(self.backend.events, [])
        client = self.client()
        for method in ('GET', 'POST', 'DELETE'):
            with self.subTest(method=method), self.assertRaises(api.Refused):
                client.request(method, 'actions/runners/generate-jitconfig')
        with self.assertRaisesRegex(api.Refused, 'before transport'):
            client._send('GET', 'actions/runs/312')
        for field, value in (('transport', None), ('token', 'fixture-not-a-token')):
            self.backend.unused = True
            client = self.client()
            setattr(client, field, value)
            with self.subTest(field=field), self.assertRaisesRegex(api.Refused, 'live transport'):
                self.begin(client)
        self.assertEqual(self.backend.calls(), [])

    def test_supplied_records_missing_malformed_consumed_or_foreign_never_verify(self):
        for target in ('qualification', 'admission'):
            baseline = getattr(self, target)
            values = [None, {}, [], dict(baseline, state='consumed'),
                      dict(baseline, candidate_sha='0' * 40), dict(baseline, repository='foreign/repo'),
                      dict(baseline, request_sha256='0' * 64)]
            for value in values:
                with self.subTest(target=target, value=value), self.assertRaises(ERRORS):
                    self.client(**{target: value})
        for field, value in (('repository', 'foreign/repo'), ('runner_name', 'foreign'),
                             ('candidate_sha', None), ('mode', 'fixture-proof')):
            request = dict(self.request, **{field: value})
            with self.subTest(field=field), self.assertRaises(ERRORS):
                producer.QualifiedJobAPI(request, qualification=self.qualification,
                                         admission=self.admission, transport=self.backend.transport,
                                         verifier=self.backend, guard=self.backend.guard)
        self.assertEqual(self.backend.events, [])

    def test_fixture_request_refused_and_input_copy_bound(self):
        fixture = {'schema': producer.request_model.SCHEMA, 'mode': 'fixture-proof',
                   'config': json.loads((producer.ROOT / 'config.json').read_text())}
        with self.assertRaisesRegex(producer.Refused, 'qualified actual'):
            producer.QualifiedJobAPI(fixture)
        client = self.client()
        self.request['guest']['id'] = '0' * 64
        with self.assertRaisesRegex(api.Refused, 'trusted current identity'):
            self.begin(client)
        self.assertEqual(self.backend.calls(), [])

    def test_independent_receipt_guard_before_transport(self):
        original = self.backend.receipt
        receipts = [None, {}, self.qualification, original._asdict()]
        for field in ('request_sha256', 'repository', 'candidate_sha', 'qualification_id', 'admission_id'):
            receipts.append(original._replace(**{field: 'foreign'}))
        for issued, expires in ((999, 1001), (1002, 1300), (1000, 1301), (True, 1300),
                                (1000, True), (1000, '1300'), ('1000', 1300)):
            receipts.append(original._replace(verified_at=issued, expires_at=expires))
        for receipt in receipts:
            self.backend.events.clear()
            self.backend.receipt = receipt
            with self.subTest(receipt=receipt), self.assertRaisesRegex(api.Refused, 'receipt'):
                self.begin()
            self.assertEqual(self.backend.calls(), [], 'unverified authority reached transport')
            self.assertNotIn('claim-unused', self.backend.events)

    def test_receipt_time_boundaries_and_admission_single_winner(self):
        for now in (1000, 1299.999):
            self.backend.unused = True
            self.begin(self.client(clock=lambda now=now: now))
        for now in (999.999, 1300):
            with self.subTest(now=now), self.assertRaisesRegex(api.Refused, 'stale'):
                self.begin(self.client(clock=lambda now=now: now))
        self.backend.events.clear()
        with self.assertRaisesRegex(api.Refused, 'unused bound admission'):
            self.begin()
        self.assertEqual(self.backend.events, ['guard', 'verify', 'claim-unused'])
        self.assertEqual(self.backend.calls(), [])
        self.backend.unused = True
        with (patch.object(self.backend, 'claim_unused', return_value=1),
              self.assertRaisesRegex(api.Refused, 'unused bound admission')):
            self.begin()
        with (patch.object(self.backend, 'verify', side_effect=api.Refused('provider unavailable')),
              self.assertRaisesRegex(api.Refused, 'provider unavailable')):
            self.begin()
        with (patch.object(self.backend, 'guard', side_effect=api.Refused('guest stale')),
              self.assertRaisesRegex(api.Refused, 'guest stale')):
            self.begin()
        self.assertEqual(self.backend.calls(), [])

    def test_transport_failure_never_invents_cleanup_identity_or_retries_admission(self):
        for method, path in (('GET', 'actions/runs/312'), ('GET', 'actions/jobs/17'),
                             ('POST', 'actions/runners/generate-jitconfig')):
            self.backend.unused = True
            self.backend.failure = (method, path)
            client = self.client()
            with self.subTest(path=path), self.assertRaisesRegex(api.Refused, 'fixture transport failure'):
                self.begin(client)
            self.assertEqual(client.owned_runners, {})
            self.assertFalse(self.backend.unused)
            with self.assertRaisesRegex(api.Refused, 'unused bound admission'):
                self.begin()
        self.assertEqual(self.backend.calls('DELETE'), [])

    def test_changed_guest_after_atomic_claim_refused_before_transport(self):
        count = 0
        def guard():
            nonlocal count
            count += 1
            if count == 2:
                raise api.Refused('guest changed after claim')
        with self.assertRaisesRegex(api.Refused, 'guest changed after claim'):
            self.begin(self.client(guard=guard))
        self.assertFalse(self.backend.unused)
        self.assertEqual(self.backend.calls(), [])

    def test_invalid_parameters_and_validated_digest_denied(self):
        for group in (True, 0, -1, '1'):
            with self.subTest(group=group), self.assertRaises(api.Refused):
                self.client().begin(group, self.backend.listener)
        with self.assertRaises(api.Refused):
            self.client().begin(1, None)
        with self.assertRaisesRegex(api.Refused, 'validated request'):
            api.QualifiedJobAPI(self.request, '0' * 64, transport=self.backend.transport,
                                verifier=self.backend, guard=self.backend.guard)
        self.assertEqual(self.backend.events, [])

    def test_current_run_job_and_scoped_labels_refuse_before_post(self):
        for target, fields in (
            ('run', [('id', True), ('id', 313), ('run_attempt', 1), ('head_sha', '0' * 40),
                     ('repository', None), ('repository', {'full_name': 'foreign/repo'})]),
            ('job', [('id', 18), ('id', True), ('run_id', 313), ('run_attempt', 1),
                     ('head_sha', '0' * 40), ('status', 'in_progress'), ('runner_id', 61),
                     ('runner_id', False), ('labels', []),
                     ('labels', api.scoped_job_labels(313, 2))])):
            baseline = copy.deepcopy(getattr(self.backend, target))
            for field, value in fields:
                setattr(self.backend, target, dict(baseline, **{field: value}))
                self.backend.unused = True
                with self.subTest(target=target, field=field, value=value), self.assertRaises(api.Refused):
                    self.begin()
                self.assertEqual(self.backend.calls('POST'), [])
            setattr(self.backend, target, baseline)
        for target in ('run', 'job'):
            original = getattr(self.backend, target)
            setattr(self.backend, target, None)
            self.backend.unused = True
            with self.subTest(target=target), self.assertRaises(api.Refused):
                self.begin()
            setattr(self.backend, target, original)
        for path in ('actions/runs/312', 'actions/jobs/17'):
            self.backend.statuses = {('GET', path): 404}
            self.backend.unused = True
            with self.subTest(path=path), self.assertRaises(api.Refused):
                self.begin()
        self.assertEqual(self.backend.calls('POST'), [])

    def test_unbound_post_identity_never_becomes_cleanup_authority(self):
        original = copy.deepcopy(self.backend.post)
        malformed = [None, {}, dict(original, runner=None)]
        for field, value in (('id', True), ('id', 0), ('id', '61'), ('name', 'foreign'),
                             ('labels', None), ('labels', ['foreign']), ('labels', []),
                             ('labels', [{'name': 'foreign'}]), ('labels', [{'id': 1}])):
            runner = dict(original['runner'], **{field: value})
            malformed.append(dict(original, runner=runner))
        for result in malformed:
            self.backend.post = result
            self.backend.unused = True
            client = self.client()
            with self.subTest(result=result), self.assertRaises(api.Refused):
                self.begin(client)
            self.assertEqual(client.owned_runners, {})
        self.assertEqual(self.backend.calls('DELETE'), [])
        self.assertFalse(any(event[0] == 'handoff' for event in self.backend.events if isinstance(event, tuple)))
        self.backend.post = original
        self.backend.statuses = {('POST', 'actions/runners/generate-jitconfig'): 403}
        self.backend.unused = True
        with self.assertRaises(api.Refused):
            self.begin()
        self.assertEqual(self.backend.calls('DELETE'), [])

    def test_get_mismatch_never_hands_off_or_deletes_foreign_identity(self):
        baseline = copy.deepcopy(self.backend.runner)
        for field, value in (('id', 999), ('name', 'foreign'), ('labels', []),
                             ('labels', [{'name': 'foreign'}])):
            self.backend.runner = dict(baseline, **{field: value})
            self.backend.unused = True
            client = self.client()
            with self.subTest(field=field), self.assertRaises(api.Refused):
                self.begin(client)
            self.assertEqual(client.owned_runners, {61: self.request['runner_name']})
            self.assertEqual(self.backend.calls('DELETE'), [])
        self.assertFalse(any(event[0] == 'handoff' for event in self.backend.events if isinstance(event, tuple)))

    def test_invalid_payload_and_listener_failure_cleanup_only_bound_idle(self):
        for payload in ('', None, 123):
            self.backend.post['encoded_jit_config'] = payload
            self.backend.unused = True
            client = self.client()
            with self.subTest(payload=payload), self.assertRaisesRegex(api.Refused, 'payload'):
                self.begin(client)
            self.assertEqual(client.owned_runners, {})
            self.assertEqual(self.backend.calls('DELETE')[-1], ('DELETE', 'actions/runners/61', None))
        self.backend.post['encoded_jit_config'] = 'fixture-private'
        self.backend.unused = True
        client = self.client()
        with self.assertRaisesRegex(RuntimeError, 'handoff failed'):
            client.begin(1, lambda *_: (_ for _ in ()).throw(RuntimeError('handoff failed')))
        self.assertEqual(client.owned_runners, {})

    def test_failure_cleanup_retains_unknown_busy_or_unavailable_runner(self):
        for scenario in ('busy', 'unknown', 'missing', 'delete-error', 'get-error', 'guard-error'):
            self.backend.unused = True
            self.backend.runner['busy'] = scenario != 'unknown'
            self.backend.statuses = {}
            self.backend.failure = None
            if scenario == 'unknown':
                self.backend.runner.pop('busy', None)
            elif scenario == 'missing':
                self.backend.statuses[('GET', 'actions/runners/61')] = 404
            elif scenario == 'delete-error':
                self.backend.runner['busy'] = False
                self.backend.statuses[('DELETE', 'actions/runners/61')] = 500
            elif scenario == 'get-error':
                self.backend.failure = ('GET', 'actions/runners/61')
            client = self.client()
            def failed_listener(*_, scenario=scenario, client=client):
                if scenario == 'guard-error':
                    client.guard = lambda: (_ for _ in ()).throw(api.Refused('guest changed'))
                    self.backend.runner['busy'] = False
                raise RuntimeError('handoff failed')
            with self.subTest(scenario=scenario), self.assertRaises((api.Refused, RuntimeError)):
                client.begin(1, failed_listener)
            self.assertEqual(client.owned_runners, {61: self.request['runner_name']})
            self.assertEqual(client.cleanup_pending, 61)
        self.assertEqual(self.backend.calls('DELETE'), [('DELETE', 'actions/runners/61', None)])

    def test_lookup_failure_then_recovered_owned_cleanup(self):
        count = 0
        transport = self.backend.transport
        def first_lookup_fails(method, path, body):
            nonlocal count
            if path == 'actions/runners/61':
                count += 1
                if count == 1:
                    self.backend.events.append((method, path, body))
                    return 503, {}
            return transport(method, path, body)
        client = self.client(transport=first_lookup_fails)
        with self.assertRaisesRegex(api.Refused, 'lookup refused'):
            self.begin(client)
        self.assertEqual(client.owned_runners, {})
        self.assertEqual(self.backend.calls('DELETE'), [('DELETE', 'actions/runners/61', None)])
