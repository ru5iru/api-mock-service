import importlib.util
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
        self.directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)
        self.root = Path(self.directory.name)
        (self.root / '.env.example').write_text('APP_KEY=base64:replace-with-32-random-bytes-in-base64\nDB_PASSWORD=replace-this-too\nMOCK_DASHBOARD_PASSWORD=change-this-too\nAPP_PORT=19483\nAPP_URL=http://localhost:19483\n')

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
