import importlib.util
import contextlib
import io
import os
import shutil
import subprocess
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('setup_helper', Path(__file__).resolve().parents[2] / 'scripts/setup.py')
helper = importlib.util.module_from_spec(spec)
spec.loader.exec_module(helper)


class SetupTest(unittest.TestCase):
    def setUp(self):
        environment = patch.dict(os.environ)
        environment.start()
        self.addCleanup(environment.stop)
        for key in ['APP_URL', 'COMPOSE_FILE']:
            os.environ.pop(key, None)
        self.directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)
        self.root = Path(self.directory.name)
        (self.root / '.env.example').write_text('APP_KEY=base64:replace-with-32-random-bytes-in-base64\nDB_PASSWORD=replace-this-too\nMOCK_DASHBOARD_PASSWORD=change-this-too\nAPP_PORT=19483\nAPP_URL=http://localhost:19483\n')

    def certificates(self):
        folder = self.root / 'docker/nginx/certs'
        folder.mkdir(parents=True)
        for name in ['localhost.pem', 'localhost-key.pem']:
            (folder / name).write_text('certificate fixture')

    def test_new_environment_selects_http_without_a_complete_certificate_pair(self):
        example = self.root / '.env.example'
        example.write_text(example.read_text().replace('http://', 'https://') + 'COMPOSE_FILE=docker-compose.yml:docker-compose.https.yml\n')
        with contextlib.redirect_stdout(io.StringIO()) as output:
            helper.run('setup', self.root)
        settings = helper.values((self.root / '.env').read_text())
        self.assertEqual('docker-compose.yml', settings['COMPOSE_FILE'])
        self.assertEqual('http://localhost:19483', settings['APP_URL'])
        self.assertIn('Protocol: HTTP only.', output.getvalue())
        self.assertFalse((self.root / 'docker/nginx/certs').exists())
        for key in ['APP_KEY', 'DB_PASSWORD', 'MOCK_DASHBOARD_PASSWORD']:
            self.assertNotIn(settings[key], output.getvalue())

    def test_new_environment_with_certificates_preserves_template_selection(self):
        example = self.root / '.env.example'
        example.write_text(example.read_text() + 'COMPOSE_FILE=docker-compose.yml:docker-compose.https.yml\n')
        self.certificates()
        with contextlib.redirect_stdout(io.StringIO()) as output:
            helper.run('setup', self.root)
        self.assertEqual('docker-compose.yml:docker-compose.https.yml', helper.values((self.root / '.env').read_text())['COMPOSE_FILE'])
        self.assertIn('Protocol: HTTP + HTTPS.', output.getvalue())
        self.assertIn('APP_URL scheme: http.', output.getvalue())
        self.assertIn('Next: make up, then make health.', output.getvalue())

    def test_existing_protocol_selections_and_all_configured_values_are_unchanged(self):
        for selection in ['', 'docker-compose.yml', 'docker-compose.yml:docker-compose.https.yml', None]:
            with self.subTest(selection=selection):
                original = '# keep this\nAPP_KEY=base64:private-fixture-key\nDB_PASSWORD=private-db-value\nMOCK_DASHBOARD_PASSWORD=private-dashboard-value\nAPP_PORT=19483\nAPP_URL=https://localhost:19483\nCUSTOM_VALUE=preserve-me\n'
                if selection is not None:
                    original += 'COMPOSE_FILE=' + selection + '\n'
                (self.root / '.env').write_text(original)
                with contextlib.redirect_stdout(io.StringIO()) as output:
                    helper.run('setup', self.root)
                self.assertEqual(original, (self.root / '.env').read_text())
                for secret in ['base64:private-fixture-key', 'private-db-value', 'private-dashboard-value']:
                    self.assertNotIn(secret, output.getvalue())

    def test_protocol_outputs_include_files_scheme_next_step_and_no_secrets(self):
        helper.run('setup', self.root)
        self.certificates()
        for action, scheme, selection in [('http-enable', 'http', 'docker-compose.yml'), ('https-enable', 'https', 'docker-compose.yml:docker-compose.https.yml')]:
            with contextlib.redirect_stdout(io.StringIO()) as output:
                helper.run(action, self.root)
            text = output.getvalue()
            self.assertIn('Compose files: ' + selection + '.', text)
            self.assertIn('APP_URL scheme: ' + scheme + '.', text)
            self.assertIn('Next: make up, then make health.', text)
            settings = helper.values((self.root / '.env').read_text())
            for key in ['APP_KEY', 'DB_PASSWORD', 'MOCK_DASHBOARD_PASSWORD']:
                self.assertNotIn(settings[key], text)

    def test_summary_reports_environment_overrides_without_exposing_url_credentials(self):
        with patch.dict(os.environ, {'COMPOSE_FILE': 'docker-compose.yml', 'APP_URL': 'https://private-user:private-pass@example.test:19483'}), contextlib.redirect_stdout(io.StringIO()) as output:
            helper.run('summary', self.root)
        self.assertIn('Compose files: docker-compose.yml.', output.getvalue())
        self.assertIn('APP_URL scheme: https.', output.getvalue())
        self.assertNotIn('private-user', output.getvalue())
        self.assertNotIn('private-pass', output.getvalue())

    def test_setup_is_repeatable_and_preserves_configured_secrets(self):
        helper.run('setup', self.root)
        first = (self.root / '.env').read_text()
        self.assertNotIn('replace-this-too', first)
        self.assertNotIn('change-this-too', first)
        self.assertEqual(len(helper.values(first)['APP_KEY'].removeprefix('base64:')), 44)
        helper.run('setup', self.root)
        self.assertEqual(first, (self.root / '.env').read_text())

    def test_existing_custom_settings_and_comments_survive(self):
        original = '# keep this\nAPP_KEY=base64:custom\nDB_PASSWORD=existing\nMOCK_DASHBOARD_PASSWORD=existing\nAPP_PORT=19483\nAPP_URL=http://localhost:19483\n'
        (self.root / '.env').write_text(original)
        helper.run('setup', self.root)
        self.assertEqual(original, (self.root / '.env').read_text())

    def test_https_requires_pair_and_keeps_credentials_and_custom_port(self):
        helper.run('setup', self.root)
        original = (self.root / '.env').read_text()
        with self.assertRaises(ValueError):
            helper.run('https-enable', self.root)
        self.assertEqual(original, (self.root / '.env').read_text())
        folder = self.root / 'docker/nginx/certs'
        folder.mkdir(parents=True)
        for name in ['localhost.pem', 'localhost-key.pem']:
            (folder / name).write_text('certificate fixture')
        helper.run('https-enable', self.root)
        enabled = helper.values((self.root / '.env').read_text())
        self.assertEqual(enabled['APP_URL'], 'https://localhost:19483')
        self.assertEqual(enabled['COMPOSE_FILE'], 'docker-compose.yml:docker-compose.https.yml')
        self.assertEqual(enabled['APP_KEY'], helper.values(original)['APP_KEY'])
        helper.run('http-enable', self.root)
        disabled = helper.values((self.root / '.env').read_text())
        self.assertEqual(disabled['APP_URL'], 'http://localhost:19483')
        self.assertEqual(disabled['COMPOSE_FILE'], 'docker-compose.yml')

    def test_make_health_reads_custom_origin_and_accepts_command_line_override(self):
        repo = Path(__file__).resolve().parents[2]
        shutil.copy(repo / 'Makefile', self.root / 'Makefile')
        (self.root / 'scripts').mkdir()
        shutil.copy(repo / 'scripts/setup.py', self.root / 'scripts/setup.py')
        (self.root / '.env').write_text('APP_URL=https://localhost:19483\n')
        environment = dict(os.environ)
        environment.pop('APP_URL', None)
        output = subprocess.check_output(['make', '-n', 'health'], cwd=self.root, env=environment, text=True)
        self.assertIn('https://localhost:19483/up', output)
        override = subprocess.check_output(['make', '-n', 'health', 'APP_URL=http://localhost:20483'], cwd=self.root, env=environment, text=True)
        self.assertIn('http://localhost:20483/up', override)

    def test_health_origin_honors_environment_override(self):
        with patch.dict('os.environ', {'APP_URL': 'https://example.test:19483'}), patch('builtins.print') as output:
            helper.run('url', self.root)
            output.assert_called_once_with('https://example.test:19483')


if __name__ == '__main__':
    unittest.main()
