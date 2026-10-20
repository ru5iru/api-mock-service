#!/usr/bin/env python3
"""CI-only disruptive regression: app changes IP while the Nginx container stays running."""
import json
import os
import subprocess
import time
import urllib.error
import urllib.request


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
    origin = os.environ.get('APP_URL', 'http://localhost:18473').rstrip('/')
    # Warm Nginx's original upstream before introducing the second address.
    with urllib.request.urlopen(origin + '/up', timeout=3) as response:
        assert response.status == 200, 'The original instance must be healthy.'
    # Both containers exist concurrently, so Docker must allocate a different IP.
    # Automatic allocation works on ordinary Compose networks without custom IPAM.
    command('docker', 'compose', 'up', '-d', '--no-deps', '--no-recreate',
            '--scale', 'app=2', '--wait', 'app')
    app_ids = command('docker', 'compose', 'ps', '-q', 'app').splitlines()
    replacements = [container for container in app_ids if container != app_id]
    assert app_id in app_ids and len(replacements) == 1, 'Expected the original app and exactly one healthy replacement.'
    replacement_id = replacements[0]
    replacement = inspect(replacement_id)
    new_ip = replacement['NetworkSettings']['Networks'][network]['IPAddress']
    assert new_ip and new_ip != old_ip, 'The test must exercise a changed upstream IP.'
    # Remove only after Compose has confirmed that the replacement is healthy.
    command('docker', 'rm', '-f', app_id)
    assert command('docker', 'compose', 'ps', '-q', 'app') == replacement_id, 'Expected only the replacement app.'
    assert command('docker', 'compose', 'ps', '-q', 'nginx') == nginx_id, 'Nginx was unexpectedly recreated.'
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


if __name__ == '__main__':
    main()
