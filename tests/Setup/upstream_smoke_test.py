import importlib.util
import io
from pathlib import Path
import unittest
from unittest.mock import MagicMock, patch
import urllib.error

spec = importlib.util.spec_from_file_location('upstream_smoke', Path(__file__).resolve().parents[2] / 'scripts/smoke_upstream_recreation.py')
smoke = importlib.util.module_from_spec(spec)
spec.loader.exec_module(smoke)


def container(address, started='unchanged'):
    return {'NetworkSettings': {'Networks': {'compose_default': {'IPAddress': address}}},
            'State': {'StartedAt': started}}


class UpstreamSmokeTest(unittest.TestCase):
    def test_refuses_to_disrupt_non_ci_instances(self):
        with patch.dict(smoke.os.environ, {'CI': 'false'}), patch.object(smoke, 'command') as command:
            with self.assertRaises(SystemExit):
                smoke.main()
            command.assert_not_called()

    def exercise(self, changed_nginx=False, retry=False):
        events = []
        commands = []
        app_queries = iter(['old', 'old\nnew', 'new'])

        def command(*args):
            commands.append(args)
            events.append(args)
            if args == ('docker', 'compose', 'ps', '-q', 'app'):
                return next(app_queries)
            if args == ('docker', 'compose', 'ps', '-q', 'nginx'):
                return 'nginx'
            return ''

        nginx_reads = 0

        def inspect(identifier):
            nonlocal nginx_reads
            if identifier == 'nginx':
                nginx_reads += 1
                return container('172.18.0.2', 'restarted' if changed_nginx and nginx_reads > 1 else 'unchanged')
            return container('172.18.0.3' if identifier == 'old' else '172.18.0.5')

        http_calls = 0

        def urlopen(*args, **kwargs):
            nonlocal http_calls
            http_calls += 1
            events.append('http')
            if retry and http_calls == 2:
                raise urllib.error.URLError('old DNS entry')
            result = MagicMock()
            result.__enter__.return_value.status = 200
            return result

        with patch.dict(smoke.os.environ, {'CI': 'true'}), patch.object(smoke, 'command', side_effect=command), \
                patch.object(smoke, 'inspect', side_effect=inspect), \
                patch.object(smoke.urllib.request, 'urlopen', side_effect=urlopen), \
                patch.object(smoke.time, 'sleep') as sleep, patch('sys.stdout', new_callable=io.StringIO):
            if changed_nginx:
                with self.assertRaisesRegex(AssertionError, 'Nginx restarted'):
                    smoke.main()
            else:
                smoke.main()
            scale = ('docker', 'compose', 'up', '-d', '--no-deps', '--no-recreate', '--scale', 'app=2', '--wait', 'app')
            remove = ('docker', 'rm', '-f', 'old')
            self.assertLess(events.index('http'), events.index(scale))
            self.assertLess(events.index(scale), events.index(remove))
            self.assertFalse(any('--ip' in call or 'disconnect' in call for call in commands))
            self.assertFalse(any('nginx' in call for call in commands if 'up' in call or 'rm' in call))
            self.assertEqual(1 if retry else 0, sleep.call_count)

    def test_default_network_uses_auto_allocation_and_preserves_nginx(self):
        self.exercise()

    def test_retries_until_stale_dns_entry_expires(self):
        self.exercise(retry=True)

    def test_nginx_restart_cannot_hide_the_regression(self):
        self.exercise(changed_nginx=True)
