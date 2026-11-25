"""Deployment transport tests; never connects to hosting or the application DB."""

import hashlib
import hmac
import importlib.util
import io
import json
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch
import urllib.error

spec = importlib.util.spec_from_file_location('db_deploy', Path(__file__).resolve().parent.parent / 'scripts/db_deploy.py')
deploy = importlib.util.module_from_spec(spec)
spec.loader.exec_module(deploy)
release_spec = importlib.util.spec_from_file_location('release', Path(__file__).resolve().parent.parent / 'scripts/release.py')
release = importlib.util.module_from_spec(release_spec)
release_spec.loader.exec_module(release)
RELEASE = 'a' * 40
SECRET = 'b' * 64
VERSION = '001_core.sql'
MIGRATION = VERSION
COMPLETE = {'status': 'complete', 'release': RELEASE, 'applied': [VERSION], 'pending': []}


class DeploymentTests(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.root = Path(self.directory.name)
        self.environment = patch.dict(os.environ, {'DB_DEPLOY_RELEASE': RELEASE,
            'DB_DEPLOY_BASE_URL': 'https://example.test/app', 'DB_DEPLOY_SECRET': SECRET,
            'DB_DEPLOY_RETRY_FAILED': 'false', 'DB_DEPLOY_ACCESS_RECEIPT': '', 'GITHUB_OUTPUT': '',
            'DB_DEPLOY_BACKUP_WINDOW_OVERRIDE': 'false', 'DB_DEPLOY_BACKUP_WINDOW_OVERRIDE_REASON': '',
            'GITHUB_STEP_SUMMARY': ''})
        self.environment.start()
        self.package = self.root / 'upload'
        manifest = self.package / '_db-deploy/releases' / RELEASE / 'manifest.json'
        manifest.parent.mkdir(parents=True)
        manifest.write_text(json.dumps({'files': {'database/migrations/' + MIGRATION: 'test-hash'}}))
        self.patch_package = patch.object(deploy, 'PACKAGE', self.package)
        self.patch_package.start()
        self.real_protection = deploy.verify_env_protection
        self.patch_protection = patch.object(deploy, 'verify_env_protection')
        self.protection = self.patch_protection.start()
        self.protection.return_value = 403

    def tearDown(self):
        self.patch_package.stop()
        self.patch_protection.stop()
        self.environment.stop()
        self.directory.cleanup()

    def test_explicit_destination_and_credentials_are_required_before_upload(self):
        with patch.dict(os.environ, {'FTP_SERVER_DIR': '/domains/jobflow.mystuffs.co.ke/public_html/',
                                    'FTP_SERVER': 'ftp.example.test', 'FTP_USERNAME': 'fixture',
                                    'FTP_PASSWORD': 'fixture-password', 'FTP_PROTOCOL': 'ftps'}):
            deploy.configuration()
            for folder in ['', 'public_html/', '/domains/../public_html/', '/public_html']:
                with self.subTest(folder=folder), patch.dict(os.environ, {'FTP_SERVER_DIR': folder}):
                    with self.assertRaisesRegex(ValueError, 'FTP_SERVER_DIR'): deploy.configuration()
            with patch.dict(os.environ, {'FTP_PASSWORD': ''}):
                with self.assertRaisesRegex(ValueError, 'FTP_PASSWORD'): deploy.configuration()

    def test_uploads_refuse_hosting_root_and_other_site_directories(self):
        with patch.dict(os.environ, {'FTP_SERVER': 'ftp.example.test', 'FTP_USERNAME': 'fixture',
                                    'FTP_PASSWORD': 'fixture-password', 'FTP_PROTOCOL': 'ftps'}):
            for folder in ['/', '/domains/', '/public_html/', '/storage/',
                           '/domains/another.example/public_html/',
                           '/domains/jobflow.mystuffs.co.ke/public_html/nested/',
                           '/domains/jobflow.mystuffs.co.ke/public_html/../public_html/']:
                with self.subTest(folder=folder), patch.dict(os.environ, {'FTP_SERVER_DIR': folder}):
                    with self.assertRaisesRegex(ValueError, 'FTP_SERVER_DIR'):
                        deploy.configuration()

    def test_signed_post_and_redirects_are_disabled(self):
        class Response:
            headers = {}
            status = 200
            def __enter__(self): return self
            def __exit__(self, *args): pass
            def read(self, size): return json.dumps({'status': 'complete', 'release': RELEASE}).encode()
        with patch.object(deploy.urllib.request, 'build_opener') as opener:
            opener.return_value.open.return_value = Response()
            deploy.call_endpoint('https://example.test', SECRET, {'action': 'status', 'release': RELEASE})
            request = opener.return_value.open.call_args.args[0]
            timestamp = request.get_header('X-deploy-timestamp')
            self.assertEqual(request.method, 'POST')
            self.assertEqual(request.get_header('User-agent'), deploy.USER_AGENT)
            self.assertEqual(request.get_header('X-deploy-signature'),
                hmac.new(SECRET.encode(), timestamp.encode() + b'\n' + request.data, hashlib.sha256).hexdigest())
            self.assertIs(opener.call_args.args[0], deploy.NoRedirects)

    def test_pending_step_then_complete(self):
        with patch.object(deploy, 'call_endpoint', side_effect=[
            {'status': 'pending', 'pending': [VERSION], 'applied': [], 'backup_window_open': True},
            COMPLETE, COMPLETE,
        ]) as call:
            deploy.run()
            self.assertEqual([entry.args[2]['action'] for entry in call.call_args_list], ['status', 'step', 'status'])

    def test_timeout_after_step_reads_status_instead_of_blind_repeat(self):
        with patch.object(deploy, 'call_endpoint', side_effect=[
            {'status': 'pending', 'pending': [VERSION], 'applied': [], 'backup_window_open': True},
            TimeoutError(), COMPLETE,
        ]) as call:
            deploy.run()
            self.assertEqual(call.call_count, 3)

    def test_review_and_backup_window_block_before_ddl(self):
        for result in [{'status': 'needs_review'},
                       {'status': 'pending', 'backup_window_open': False}]:
            with self.subTest(result=result), patch.object(deploy, 'call_endpoint', return_value=result) as call:
                with self.assertRaises(RuntimeError): deploy.run()
                self.assertEqual(call.call_count, 1)

    def test_emergency_override_is_signed_and_requires_server_acceptance(self):
        reason = 'Owner authorized emergency deployment outside the scheduled backup window.'
        with patch.dict(os.environ, {'DB_DEPLOY_BACKUP_WINDOW_OVERRIDE': 'true', 'DB_DEPLOY_BACKUP_WINDOW_OVERRIDE_REASON': reason}), patch.object(deploy, 'call_endpoint', side_effect=[
            {'status': 'pending', 'pending': [VERSION], 'backup_window_open': False, 'backup_window_override_active': True}, COMPLETE, COMPLETE,
        ]) as call:
            deploy.run()
            self.assertEqual([entry.args[2]['action'] for entry in call.call_args_list], ['status', 'step', 'status'])
            for entry in call.call_args_list:
                self.assertIs(entry.args[2]['backup_window_override'], True)
                self.assertEqual(entry.args[2]['backup_window_override_reason'], reason)
                self.assertEqual(entry.args[2]['release'], RELEASE)

    def test_override_cannot_be_assumed_by_client_or_server_alone(self):
        for requested, accepted in [(False, True), (True, False)]:
            with self.subTest(requested=requested, accepted=accepted), patch.dict(os.environ, {
                'DB_DEPLOY_BACKUP_WINDOW_OVERRIDE': 'true' if requested else 'false',
                'DB_DEPLOY_BACKUP_WINDOW_OVERRIDE_REASON': 'Emergency deployment authorized by the owner.',
            }), patch.object(deploy, 'call_endpoint', return_value={
                'status': 'pending', 'pending': [VERSION], 'backup_window_open': False, 'backup_window_override_active': accepted,
            }) as call:
                with self.assertRaises(RuntimeError): deploy.run()
                self.assertEqual(call.call_count, 1)

    def test_override_requires_reason_and_boolean_setting_before_endpoint_calls(self):
        for enabled, reason in [('true', ''), ('true', 'short'), ('true', 'a' * 501), ('true', 'Emergency reason\nwith another line'), ('yes', 'Emergency deployment')]:
            with self.subTest(enabled=enabled, reason=reason), patch.dict(os.environ, {
                'DB_DEPLOY_BACKUP_WINDOW_OVERRIDE': enabled, 'DB_DEPLOY_BACKUP_WINDOW_OVERRIDE_REASON': reason,
            }), patch.object(deploy, 'call_endpoint') as call:
                with self.assertRaises(ValueError): deploy.run()
                call.assert_not_called()

    def test_override_does_not_approve_interrupted_migrations(self):
        with patch.dict(os.environ, {'DB_DEPLOY_BACKUP_WINDOW_OVERRIDE': 'true', 'DB_DEPLOY_BACKUP_WINDOW_OVERRIDE_REASON': 'Emergency deployment authorized by the owner.'}), patch.object(deploy, 'call_endpoint', return_value={
            'status': 'needs_review', 'backup_window_open': False, 'backup_window_override_active': True,
        }) as call:
            with self.assertRaisesRegex(RuntimeError, 'requires review'): deploy.run()
            self.assertEqual(call.call_count, 1)

    def test_normal_push_payload_has_no_override(self):
        with patch.object(deploy, 'call_endpoint', return_value=COMPLETE) as call:
            deploy.run()
            self.assertNotIn('backup_window_override', call.call_args.args[2])

    def test_failure_reports_safe_schema_details_and_private_log(self):
        body = {'error': 'Deployment failed.', 'error_log': '_db-deploy/deployment-error.log',
                'schema_missing': ['documents.processing_status', 'unique index documents(id)',
                                   'SELECT private-password FROM customers'],
                'sqlstate': 'HY000', 'database_error_code': 3780,
                'raw_error': 'private-password and customer records'}
        with patch.object(deploy.urllib.request, 'build_opener') as opener:
            opener.return_value.open.side_effect = urllib.error.HTTPError(
                'https://example.test', 500, 'Internal Server Error', {}, io.BytesIO(json.dumps(body).encode()))
            with self.assertRaises(RuntimeError) as caught:
                deploy.call_endpoint('https://example.test', SECRET, {'action': 'step', 'release': RELEASE})
        message = str(caught.exception)
        self.assertIn('documents.processing_status', message)
        self.assertIn('unique index documents(id)', message)
        self.assertIn('SQLSTATE: HY000; database error code: 3780', message)
        self.assertIn('_db-deploy/deployment-error.log', message)
        self.assertNotIn('private-password', message)
        self.assertNotIn('customer records', message)

    def test_interrupted_status_reports_schema_without_retrying_migration(self):
        result = {'status': 'needs_review', 'schema_missing': ['documents.storage_driver'],
                  'error_log': '_db-deploy/deployment-error.log'}
        with patch.object(deploy, 'call_endpoint', return_value=result) as call:
            with self.assertRaisesRegex(RuntimeError, 'documents.storage_driver'):
                deploy.run()
            self.assertEqual(call.call_count, 1)
            self.assertEqual(call.call_args.args[2]['action'], 'status')

    def test_missing_endpoint_explains_upload_path_without_exposing_hosting_body(self):
        for body in [b'<html>private-hosting-details</html>', b'{"error":"private-hosting-details"}']:
            with self.subTest(body=body), patch.object(deploy.urllib.request, 'build_opener') as opener:
                opener.return_value.open.side_effect = urllib.error.HTTPError(
                    'https://example.test/app/endpoint.php', 404, 'Not Found', {}, io.BytesIO(body))
                with self.assertRaises(RuntimeError) as caught:
                    deploy.call_endpoint('https://example.test/app/endpoint.php', SECRET,
                                         {'action': 'status', 'release': RELEASE})
                message = str(caught.exception)
                self.assertIn('HTTP 404', message)
                self.assertIn('/_db-deploy/releases/' + RELEASE + '/endpoint.php', message)
                self.assertIn('FTP_SERVER_DIR', message)
                self.assertIn('DB_DEPLOY_BASE_URL', message)
                self.assertIn('rewrite and access rules', message)
                self.assertNotIn('private-hosting-details', message)
                self.assertNotIn(SECRET, message)

    def test_missing_endpoint_stops_before_migrations_and_leaves_summary_unverified(self):
        summary = self.root / 'summary.md'
        with patch.dict(os.environ, {'GITHUB_STEP_SUMMARY': str(summary)}), patch.object(deploy.urllib.request, 'build_opener') as opener:
            opener.return_value.open.side_effect = urllib.error.HTTPError(
                'https://example.test/app/endpoint.php', 404, 'Not Found', {}, io.BytesIO(b'Not found'))
            with self.assertRaisesRegex(RuntimeError, 'HTTP 404'):
                deploy.run()
            opener.return_value.open.assert_called_once()
            request = opener.return_value.open.call_args.args[0]
            self.assertEqual(json.loads(request.data)['action'], 'status')
        self.assertIn('**NOT VERIFIED', summary.read_text())
        self.assertIn('| Pending migrations | Unknown |', summary.read_text())

    def test_malformed_diagnostics_do_not_hide_failure_or_print_arbitrary_content(self):
        for missing in [None, 'private-password', {}, [None, {}, '<script>private-password</script>']]:
            with self.subTest(missing=missing):
                self.assertEqual(deploy.deployment_details({'schema_missing': missing,
                    'sqlstate': 'private-password', 'error_log': '/private/password'}), '')

    def test_backup_failure_shows_hosting_schedule_diagnostic(self):
        message = 'Backup window closed: expected 12:44 UTC; next allowed window tomorrow.'
        with patch.object(deploy, 'call_endpoint', return_value={
            'status': 'pending', 'backup_window_open': False, 'backup_window': {'message': message},
        }) as call:
            with self.assertRaisesRegex(RuntimeError, 'expected 12:44 UTC'):
                deploy.run()
            self.assertEqual(call.call_count, 1)

    def test_requires_https_and_secret_before_network(self):
        with patch.object(deploy, 'call_endpoint') as call, patch.dict(os.environ, {'DB_DEPLOY_BASE_URL': 'http://example.test'}):
            with self.assertRaises(ValueError): deploy.run()
            call.assert_not_called()

    def test_missing_url_explains_github_setting_before_network(self):
        with patch.dict(os.environ, {'DB_DEPLOY_BASE_URL': ''}), patch.object(deploy, 'call_endpoint') as call:
            with self.assertRaisesRegex(ValueError, 'DB_DEPLOY_BASE_URL is empty.*GitHub Actions'):
                deploy.run()
            self.protection.assert_not_called()
            call.assert_not_called()

    def test_missing_secret_explains_matching_hosting_setting(self):
        with patch.dict(os.environ, {'DB_DEPLOY_SECRET': ''}):
            with self.assertRaisesRegex(ValueError, 'DB_DEPLOY_SECRET is empty.*GitHub Actions secret'):
                deploy.check()
            self.protection.assert_not_called()

    def test_invalid_urls_reject_credentials_and_bad_ports_without_echoing_values(self):
        urls = ['https://user:private-password@example.test', 'https://example.test:not-a-port',
                'https://example.test:70000', 'https://[invalid', 'https://example.test?token=private-password',
                'https://example.test#fragment', 'https://exam ple.test']
        for url in urls:
            with self.subTest(url=url), patch.dict(os.environ, {'DB_DEPLOY_BASE_URL': url}):
                with self.assertRaisesRegex(ValueError, 'Invalid DB_DEPLOY_BASE_URL') as caught:
                    deploy.settings()
                self.assertNotIn('private-password', str(caught.exception))

    def test_copied_url_trailing_slash_and_whitespace_are_normalized(self):
        with patch.dict(os.environ, {'DB_DEPLOY_BASE_URL': '  https://example.test/app/ \n'}):
            self.assertEqual(deploy.settings()[1], 'https://example.test/app')

    def test_preflight_checks_protection_without_package_or_migration_requests(self):
        with patch.object(deploy, 'call_endpoint') as call:
            deploy.check()
            self.protection.assert_called_once_with('https://example.test/app')
            call.assert_not_called()

    def test_migration_reuses_this_jobs_successful_access_check(self):
        output = self.root / 'outputs'
        with patch.dict(os.environ, {'GITHUB_OUTPUT': str(output)}):
            deploy.check()
        token = output.read_text().strip().split('=', 1)[1]
        self.assertNotIn(SECRET, token)
        self.protection.reset_mock()
        with patch.dict(os.environ, {'DB_DEPLOY_ACCESS_RECEIPT': token}), patch.object(deploy, 'call_endpoint', return_value=COMPLETE):
            deploy.run()
        self.protection.assert_not_called()

    def test_changed_expired_or_tampered_receipts_force_fresh_access_check(self):
        token = deploy.access_receipt(RELEASE, 'https://example.test/app', SECRET, 403)
        bad = [token + 'bad', 'malformed',
               deploy.access_receipt('c' * 40, 'https://example.test/app', SECRET, 403),
               deploy.access_receipt(RELEASE, 'https://other.test', SECRET, 403),
               deploy.access_receipt(RELEASE, 'https://example.test/app', 'd' * 64, 403),
               deploy.access_receipt(RELEASE, 'https://example.test/app', SECRET, 202)]
        with patch.object(deploy.time, 'time', return_value=1):
            bad.append(deploy.access_receipt(RELEASE, 'https://example.test/app', SECRET, 403))
        with patch.object(deploy.time, 'time', return_value=10**12):
            bad.append(deploy.access_receipt(RELEASE, 'https://example.test/app', SECRET, 403))
        for token in bad:
            with self.subTest(token=token), patch.dict(os.environ, {'DB_DEPLOY_ACCESS_RECEIPT': token}), patch.object(deploy, 'call_endpoint', return_value=COMPLETE):
                self.protection.reset_mock()
                deploy.run()
                self.protection.assert_called_once_with('https://example.test/app')

    def test_captcha_is_not_treated_as_environment_protection(self):
        class Response:
            status = 202
            headers = {'sg-captcha': 'challenge'}
            def __enter__(self): return self
            def __exit__(self, *args): pass
            def read(self, *args): raise AssertionError('Challenge/environment content must not be downloaded.')
        with patch.object(deploy.urllib.request, 'build_opener') as opener:
            opener.return_value.open.return_value = Response()
            with self.assertRaisesRegex(RuntimeError, 'sg-captcha: challenge.*SiteGround support'):
                self.real_protection('https://example.test')

    def test_captcha_is_not_treated_as_successful_migration(self):
        class Response:
            status = 202
            headers = {'sg-captcha': 'challenge'}
            def __enter__(self): return self
            def __exit__(self, *args): pass
            def read(self, *args): raise AssertionError('Challenge response must not be processed as migration data.')
        with patch.object(deploy.urllib.request, 'build_opener') as opener:
            opener.return_value.open.return_value = Response()
            with self.assertRaisesRegex(RuntimeError, 'Migration endpoint returned HTTP 202'):
                deploy.call_endpoint('https://example.test/endpoint.php', SECRET, {'action': 'step', 'release': RELEASE})

    def test_captcha_on_error_response_does_not_count_as_denial(self):
        with patch.object(deploy.urllib.request, 'build_opener') as opener:
            opener.return_value.open.side_effect = urllib.error.HTTPError('https://example.test/.env', 403, 'Forbidden', {'sg-captcha': 'challenge'}, None)
            with self.assertRaisesRegex(RuntimeError, 'sg-captcha: challenge'):
                self.real_protection('https://example.test')

    def test_public_environment_file_blocks_deployment_without_reading_it(self):
        class Response:
            headers = {}
            status = 200
            def __enter__(self): return self
            def __exit__(self, *args): pass
            def read(self, *args): raise AssertionError('Environment contents must never be read.')
        with patch.object(deploy.urllib.request, 'build_opener') as opener:
            opener.return_value.open.return_value = Response()
            with self.assertRaises(RuntimeError): self.real_protection('https://example.test')
            self.assertEqual(opener.return_value.open.call_args.args[0].method, 'HEAD')
            self.assertEqual(opener.return_value.open.call_args.args[0].get_header('User-agent'), deploy.USER_AGENT)
            self.assertEqual(opener.return_value.open.call_args.args[0].get_header('Cache-control'), 'no-cache')

    def test_environment_check_reports_response_status_without_reading_body(self):
        class Response:
            headers = {}
            def __init__(self, code): self.status = code
            def __enter__(self): return self
            def __exit__(self, *args): pass
            def read(self, *args): raise AssertionError('Environment contents must never be read.')
        for code, explanation in [(200, 'hosting .htaccess'), (301, 'does not follow redirects'),
                                  (302, 'does not follow redirects'), (500, 'server error')]:
            with self.subTest(code=code), patch.object(deploy.urllib.request, 'build_opener') as opener:
                opener.return_value.open.return_value = Response(code)
                with self.assertRaises(RuntimeError) as caught:
                    self.real_protection('https://example.test/app')
                self.assertIn('HEAD https://example.test/app/.env returned HTTP ' + str(code), str(caught.exception))
                self.assertIn(explanation, str(caught.exception))

    def test_redirect_error_does_not_expose_location_or_download_environment(self):
        with patch.object(deploy.urllib.request, 'build_opener') as opener:
            opener.return_value.open.side_effect = urllib.error.HTTPError('https://example.test/.env', 302, 'Redirect',
                {'Location': 'https://example.test/login?token=private-value'}, None)
            with self.assertRaises(RuntimeError) as caught:
                self.real_protection('https://example.test')
            self.assertIn('HTTP 302', str(caught.exception))
            self.assertNotIn('private-value', str(caught.exception))

    def test_denied_environment_file_allows_deployment(self):
        with patch.object(deploy.urllib.request, 'build_opener') as opener:
            opener.return_value.open.side_effect = urllib.error.HTTPError('https://example.test/.env', 403, 'Forbidden', {}, None)
            self.real_protection('https://example.test')

    def test_current_database_summary_uses_real_history_without_running_steps(self):
        summary = self.root / 'summary.md'
        with patch.dict(os.environ, {'GITHUB_STEP_SUMMARY': str(summary)}), patch.object(deploy, 'call_endpoint', return_value=COMPLETE) as call:
            deploy.run()
        self.assertEqual([entry.args[2]['action'] for entry in call.call_args_list], ['status'])
        text = summary.read_text()
        self.assertIn('**VERIFIED', text)
        self.assertIn(RELEASE, text)
        self.assertIn('| Recorded release migrations | 1 / 1 |', text)
        self.assertIn('| Pending migrations | 0 |', text)
        self.assertIn('| Required tables, columns and unique indexes | PASS |', text)
        self.assertIn(f'| `{MIGRATION}` | APPLIED |', text)
        self.assertIn('local database is checked separately', text)
        self.assertNotIn(SECRET, text)

    def test_blocked_summary_preserves_last_observed_pending_history(self):
        summary = self.root / 'summary.md'
        result = {'status': 'pending', 'applied': [], 'pending': [VERSION], 'backup_window_open': False}
        with patch.dict(os.environ, {'GITHUB_STEP_SUMMARY': str(summary)}), patch.object(deploy, 'call_endpoint', return_value=result):
            with self.assertRaises(RuntimeError):
                deploy.run()
        text = summary.read_text()
        self.assertIn('**NOT VERIFIED', text)
        self.assertIn('| Pending migrations | 1 |', text)
        self.assertIn(f'| `{MIGRATION}` | PENDING |', text)
        self.assertNotIn('| PASS |', text)

    def test_complete_without_matching_history_never_gets_a_verified_summary(self):
        summary = self.root / 'summary.md'
        results = [{'status': 'complete'}, dict(COMPLETE, applied=[]),
                   dict(COMPLETE, pending=[VERSION]), dict(COMPLETE, applied=[VERSION, VERSION]),
                   dict(COMPLETE, applied=['20261002000000']), dict(COMPLETE, applied=[{}])]
        for result in results:
            with self.subTest(result=result), patch.dict(os.environ, {'GITHUB_STEP_SUMMARY': str(summary)}), patch.object(deploy, 'call_endpoint', return_value=result) as call:
                summary.unlink(missing_ok=True)
                with self.assertRaisesRegex(RuntimeError, 'without matching'):
                    deploy.run()
                self.assertEqual(call.call_count, 1)
                self.assertIn('**NOT VERIFIED', summary.read_text())
                self.assertNotIn('| PASS |', summary.read_text())

    def test_failed_setup_summary_does_not_imply_zero_pending_or_expose_secret(self):
        summary = self.root / 'summary.md'
        with patch.dict(os.environ, {'GITHUB_STEP_SUMMARY': str(summary), 'DB_DEPLOY_SECRET': ''}), patch.object(deploy, 'call_endpoint') as call:
            with self.assertRaises(ValueError):
                deploy.run()
            call.assert_not_called()
        self.assertIn('**NOT VERIFIED', summary.read_text())
        self.assertIn('| Pending migrations | Unknown |', summary.read_text())

    def test_lost_step_response_requires_fresh_status_before_verified_summary(self):
        summary = self.root / 'summary.md'
        result = {'status': 'pending', 'applied': [], 'pending': [VERSION], 'backup_window_open': True}
        with patch.dict(os.environ, {'GITHUB_STEP_SUMMARY': str(summary)}), patch.object(deploy, 'call_endpoint', side_effect=[result, TimeoutError(), COMPLETE]) as call:
            deploy.run()
        self.assertEqual([entry.args[2]['action'] for entry in call.call_args_list], ['status', 'step', 'status'])
        self.assertIn('**VERIFIED', summary.read_text())
        self.assertIn('| Pending migrations | 0 |', summary.read_text())

    def test_unreachable_status_is_unknown_and_not_zero_applied(self):
        summary = self.root / 'summary.md'
        with patch.dict(os.environ, {'GITHUB_STEP_SUMMARY': str(summary)}), patch.object(deploy, 'call_endpoint', side_effect=TimeoutError()), patch.object(deploy.time, 'sleep'):
            with self.assertRaisesRegex(RuntimeError, 'Cannot read deployment status'):
                deploy.run()
        text = summary.read_text()
        self.assertIn('**NOT VERIFIED', text)
        self.assertIn('| Recorded release migrations | Unknown / 1 |', text)
        self.assertIn('| Pending migrations | Unknown |', text)
        self.assertIn(f'| `{MIGRATION}` | Unknown |', text)


class PackagingTests(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.root = Path(self.directory.name) / 'source'
        self.output = Path(self.directory.name) / 'release'
        self.package = Path(self.directory.name) / 'migrations'
        self.root.mkdir()
        for directory in release.DIRECTORIES:
            (self.root / directory).mkdir(parents=True, exist_ok=True)
        files = set(release.FILES + release.REQUIRED)
        files.update('scripts/' + name for name in release.SCRIPTS)
        files.update(['database/deploy.php', 'database/runtime_autoload.php', 'database/schema.php',
                      'database/connection.php', 'database/migrations/001_core.sql',
                      'App/Database/Runner.php', 'App/Core/Env.php'])
        for name in files:
            path = self.root / name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text('fixture')
        self.patches = [patch.object(release, 'ROOT', self.root), patch.object(release, 'OUTPUT', self.output),
                        patch.object(deploy, 'ROOT', self.root), patch.object(deploy, 'PACKAGE', self.package),
                        patch.dict(os.environ, {'DB_DEPLOY_RELEASE': RELEASE})]
        for item in self.patches: item.start()

    def tearDown(self):
        for item in reversed(self.patches): item.stop()
        self.directory.cleanup()

    def private_file(self, name, content='private fixture'):
        path = self.root / name
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(content)
        return path

    def test_application_release_excludes_private_local_data_and_preserves_access_rules(self):
        for name in ['.env', 'service-account.json', 'storage/private/cv.pdf', 'tests/receipt.eml',
                     'secrets/credential.json', 'Resources/css/app.css']:
            self.private_file(name)
        release.build()
        application = self.output / 'application'
        manifest = json.loads((self.output / 'manifest.json').read_text())['files']
        actual = {str(path.relative_to(application)) for path in application.rglob('*') if path.is_file()}
        self.assertEqual(set(manifest), actual)
        for name, digest in manifest.items():
            self.assertEqual(digest, hashlib.sha256((application / name).read_bytes()).hexdigest())
        self.assertFalse((application / '.env').exists())
        self.assertFalse((application / 'storage').exists())
        self.assertFalse((application / 'tests').exists())
        self.assertFalse((application / 'service-account.json').exists())
        self.assertTrue((application / 'scripts/cron.php').is_file())
        self.assertEqual((self.output / 'bootstrap/storage/private/.htaccess').read_text(), 'Require all denied\n')

    def test_migration_package_contains_only_its_runtime_and_complete_hashes(self):
        for name in ['.env', 'App/Controllers/Private.php', 'App/Database/credential.json',
                     'database/migrations/receipt.eml', 'vendor/other-package/private.php']:
            self.private_file(name)
        deploy.build()
        package = self.package / '_db-deploy/releases' / RELEASE
        manifest = json.loads((package / 'manifest.json').read_text())
        actual = {str(path.relative_to(package)) for path in package.rglob('*')
                  if path.is_file() and path.name != 'manifest.json'}
        self.assertEqual(set(manifest['files']), actual)
        for name, digest in manifest['files'].items():
            self.assertEqual(digest, hashlib.sha256((package / name).read_bytes()).hexdigest())
        self.assertEqual((package / 'vendor/autoload.php').read_text(), 'fixture')
        self.assertIn('database/migrations/001_core.sql', actual)
        self.assertFalse((package / 'App/Controllers').exists())
        self.assertFalse((package / 'App/Database/credential.json').exists())
        self.assertFalse((package / 'database/migrations/receipt.eml').exists())

    def test_unexpected_credential_in_runtime_blocks_packaging(self):
        self.private_file('Public/credential.json', json.dumps({'type': 'service_account', 'private_key': 'fixture'}))
        with self.assertRaisesRegex(ValueError, 'credential'): release.build()

    def test_ca_certificates_ship_but_private_keys_do_not(self):
        self.private_file('vendor/grpc/grpc/etc/roots.pem', '-----BEGIN CERTIFICATE-----\nfixture\n')
        self.private_file('vendor/google/cloud-core/src/Testing/keyfile-stub.json', json.dumps({'type': 'service_account'}))
        release.build()
        self.assertTrue((self.output / 'application/vendor/grpc/grpc/etc/roots.pem').is_file())
        self.assertFalse((self.output / 'application/vendor/google/cloud-core/src/Testing').exists())
        self.private_file('Public/private.pem', '-----BEGIN PRIVATE KEY-----\n' + 'A' * 64 + '\n')
        with self.assertRaisesRegex(ValueError, 'private key'): release.build()

    def test_source_symlinks_block_both_packages(self):
        target = self.private_file('outside.php')
        (self.root / 'App/Database/Linked.php').symlink_to(target)
        with self.assertRaisesRegex(ValueError, 'symlink'): release.build()
        with self.assertRaisesRegex(ValueError, 'symlink'): deploy.build()


if __name__ == '__main__':
    unittest.main()
