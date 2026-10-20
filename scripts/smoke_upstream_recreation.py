#!/usr/bin/env python3
"""CI-only disruptive regression: app changes IP while the Nginx container stays running."""
import json
import os
import subprocess
import time
import urllib.error
import urllib.request
import uuid


def command(*args):
    return subprocess.check_output(args, text=True).strip()


def inspect(container):
    return json.loads(command('docker', 'inspect', container))[0]


def main():
    if os.environ.get('CI') != 'true':
        raise SystemExit('This disruptive smoke test requires a disposable CI instance (CI=true).')
    app_id = command('docker', 'compose', 'ps', '-q', 'app')
    nginx_id = command('docker', 'compose', 'ps', '-q', 'nginx')
    app = inspect(app_id)
    nginx = inspect(nginx_id)
    networks = set(app['NetworkSettings']['Networks']) & set(nginx['NetworkSettings']['Networks'])
    if len(networks) != 1:
        raise SystemExit('Expected exactly one shared app/Nginx network.')
    network = networks.pop()
    old_ip = app['NetworkSettings']['Networks'][network]['IPAddress']
    holder = 'mockdeck-ci-ip-holder-' + uuid.uuid4().hex[:10]
    try:
        # Reserve the previous address so Docker cannot hide the bug by reusing it.
        command('docker', 'network', 'disconnect', network, app_id)
        command('docker', 'run', '-d', '--name', holder, '--network', network, '--ip', old_ip,
                '--entrypoint', 'sh', app['Config']['Image'], '-c', 'sleep 180')
        command('docker', 'compose', 'up', '-d', '--no-deps', '--force-recreate', '--wait', 'app')
        replacement = inspect(command('docker', 'compose', 'ps', '-q', 'app'))
        new_ip = replacement['NetworkSettings']['Networks'][network]['IPAddress']
        assert new_ip != old_ip, 'The test must exercise a changed upstream IP.'
        assert command('docker', 'compose', 'ps', '-q', 'nginx') == nginx_id, 'Nginx was unexpectedly recreated.'
        origin = os.environ.get('APP_URL', 'http://localhost:18473').rstrip('/')
        last_error = None
        for _ in range(30):
            try:
                with urllib.request.urlopen(origin + '/up', timeout=3) as response:
                    assert response.status == 200
                assert inspect(nginx_id)['State']['StartedAt'] == nginx['State']['StartedAt'], 'Nginx restarted.'
                print(f'Nginx recovered after app IP changed {old_ip} -> {new_ip}, without restarting Nginx.')
                return
            except (urllib.error.URLError, TimeoutError) as error:
                last_error = error
                time.sleep(1)
        raise AssertionError(f'Nginx did not recover from the upstream IP change: {last_error}')
    finally:
        subprocess.run(['docker', 'rm', '-f', holder], check=False, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)


if __name__ == '__main__':
    main()
