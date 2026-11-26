"""Collector environment updates never use hosting or live credentials in tests."""

import importlib.util
import os
from pathlib import Path
import sys
import unittest
from unittest.mock import MagicMock, patch

sys.path.insert(0, str(Path(__file__).resolve().parent.parent / 'scripts'))
spec = importlib.util.spec_from_file_location('configure_collector', Path(__file__).resolve().parent.parent / 'scripts/configure_collector.py')
collector = importlib.util.module_from_spec(spec)
spec.loader.exec_module(collector)


class CollectorConfigurationTests(unittest.TestCase):
    def setUp(self):
        self.environment = patch.dict(os.environ, {
            'COLLECTOR_PROJECT': 'fixture-project', 'COLLECTOR_REGION': 'us-central1',
            'COLLECTOR_JOB': 'synapse-job-collector', 'COLLECTOR_TOKEN': 'a' * 64,
            'FTP_PROTOCOL': 'ftps', 'FTP_SERVER': 'ftp.example.test', 'FTP_PORT': '21',
            'FTP_USERNAME': 'fixture', 'FTP_PASSWORD': 'fixture-password',
            'FTP_SERVER_DIR': '/domains/jobflow.mystuffs.co.ke/public_html/',
        })
        self.environment.start()

    def tearDown(self):
        self.environment.stop()

    def test_preserves_database_keys_and_comments_and_replaces_duplicate_collector_keys(self):
        original = b'# Private hosting environment\nDB_PASSWORD="keep-this-exactly"\nAPP_KEY=unchanged\nCOLLECTOR_ENABLED=false\nCOLLECTOR_TOKEN=obsolete\nCOLLECTOR_TOKEN=duplicate\n'
        updated = collector.patch_environment(original, collector.collector_values())
        self.assertIn(b'DB_PASSWORD="keep-this-exactly"\nAPP_KEY=unchanged\n', updated)
        self.assertIn(b'# Private hosting environment', updated)
        self.assertEqual(updated.count(b'COLLECTOR_TOKEN='), 1)
        self.assertIn(b'COLLECTOR_ENABLED=true\n', updated)
        self.assertNotIn(b'obsolete', updated)

    def test_rejects_missing_or_multiline_credentials_before_connecting(self):
        for key, value in [('COLLECTOR_TOKEN', ''), ('COLLECTOR_TOKEN', 'a' * 64 + '\n'), ('COLLECTOR_PROJECT', 'wrong,project')]:
            with self.subTest(key=key, value=value), patch.dict(os.environ, {key: value}):
                with self.assertRaises(ValueError): collector.collector_values()

    def test_refuses_to_replace_missing_or_oversized_hosting_environment(self):
        for contents in [b'', b'x' * 1048577]:
            with self.assertRaises(ValueError): collector.patch_environment(contents, collector.collector_values())

    def test_stages_protected_file_sets_private_permissions_then_renames(self):
        with patch.object(collector.ftplib, 'FTP_TLS') as connection:
            client = connection.return_value.__enter__.return_value
            client.retrbinary.side_effect = lambda command, callback: callback(b'APP_KEY=preserved\n')
            collector.configure_host(collector.collector_values())
            client.prot_p.assert_called_once()
            client.sendcmd.assert_called_once_with('SITE CHMOD 600 .env.collector')
            client.rename.assert_called_once_with('.env.collector', '.env')
            self.assertEqual(client.storbinary.call_args.args[0], 'STOR .env.collector')

    def test_rename_failure_cleans_staging_file_and_preserves_original(self):
        with patch.object(collector.ftplib, 'FTP_TLS') as connection:
            client = connection.return_value.__enter__.return_value
            client.retrbinary.side_effect = lambda command, callback: callback(b'APP_KEY=preserved\n')
            client.rename.side_effect = collector.ftplib.error_perm('Rename refused')
            with self.assertRaises(collector.ftplib.error_perm): collector.configure_host(collector.collector_values())
            client.delete.assert_called_once_with('.env.collector')
            self.assertNotIn(unittest.mock.call('.env'), client.delete.call_args_list)

    def test_plaintext_ftp_is_rejected_before_a_connection(self):
        with patch.dict(os.environ, {'FTP_PROTOCOL': 'ftp'}), patch.object(collector.ftplib, 'FTP_TLS') as connection:
            with self.assertRaises(ValueError): collector.configure_host(collector.collector_values())
            connection.assert_not_called()

    def test_separate_collector_deployment_preserves_host_configuration(self):
        with patch.dict(os.environ, {'COLLECTOR_TOKEN': ''}), patch.object(collector, 'configure_host') as configure:
            collector.run()
            configure.assert_not_called()



if __name__ == '__main__':
    unittest.main()
