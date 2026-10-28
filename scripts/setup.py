#!/usr/bin/env python3
"""Local setup helpers. Never print secrets or replace configured credentials."""
import argparse
import base64
import os
from pathlib import Path
import re
import secrets
import sys
from urllib.parse import urlsplit, urlunsplit

ROOT = Path(__file__).resolve().parents[1]


def values(text):
    return {m[1]: m[2].strip().strip('\"\'') for m in re.finditer(r'^([A-Z_]+)=(.*)$', text, re.M)}


def write_values(path, text, updates):
    for key, value in updates.items():
        pattern = rf'^{key}=.*$'
        if re.search(pattern, text, re.M):
            text = re.sub(pattern, lambda _: f'{key}={value}', text, flags=re.M)
        else:
            text = text.rstrip() + f'\n{key}={value}\n'
    # Preserve existing file permissions (including Windows/WSL mount semantics).
    if not path.exists():
        fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
        with os.fdopen(fd, 'w') as output:
            output.write(text)
    else:
        path.write_text(text)


def certificate_pair(root):
    return all((root / 'docker/nginx/certs' / name).is_file()
               and (root / 'docker/nginx/certs' / name).stat().st_size
               for name in ['localhost.pem', 'localhost-key.pem'])


def protocol_summary(settings, root):
    selected = os.environ.get('COMPOSE_FILE', settings.get('COMPOSE_FILE', ''))
    scheme = urlsplit(os.environ.get('APP_URL') or settings.get('APP_URL') or 'http://localhost:18473').scheme
    dual = 'docker-compose.https.yml' in selected
    protocol = 'HTTP + HTTPS' if dual else ('HTTP only' if selected in ['', 'docker-compose.yml'] else 'custom Compose selection')
    print(f'Protocol: {protocol}. Compose files: {selected or "docker-compose.yml (default)"}. APP_URL scheme: {scheme}.')
    print('Next: make https-setup (or make http-enable for HTTP only), then make up.'
          if dual and not certificate_pair(root) else 'Next: make up, then make health.')


def run(action, root=ROOT):
    path = root / '.env'
    existed = path.exists()
    text = path.read_text() if path.exists() else (root / '.env.example').read_text()
    settings = values(text)
    initial_protocol = {}
    if not existed and not certificate_pair(root) and action in ['setup', 'summary']:
        origin = urlsplit(settings.get('APP_URL', 'http://localhost:18473'))
        initial_protocol = {'COMPOSE_FILE': 'docker-compose.yml',
                            'APP_URL': urlunsplit(('http', origin.netloc, origin.path, origin.query, origin.fragment))}
    if action == 'setup':
        placeholders = {
            'APP_KEY': {'', 'base64:replace-with-32-random-bytes-in-base64', 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='},
            'DB_PASSWORD': {'', 'replace-this-too', 'mock_api'},
            'MOCK_DASHBOARD_PASSWORD': {'', 'change-this-too'},
        }
        updates = dict(initial_protocol)
        for key, defaults in placeholders.items():
            if settings.get(key, '') in defaults:
                updates[key] = 'base64:' + base64.b64encode(secrets.token_bytes(32)).decode() if key == 'APP_KEY' else secrets.token_hex(24)
        if updates or not existed:
            write_values(path, text, updates)
        print('Environment ready. Configured secrets were preserved. Read dashboard credentials in .env; they are not printed here.')
        protocol_summary(values(path.read_text()), root)
        return
    if action == 'url':
        print(os.environ.get('APP_URL') or settings.get('APP_URL') or 'http://localhost:18473')
        return
    if action == 'summary':
        protocol_summary({**settings, **initial_protocol}, root)
        return
    if not path.exists():
        raise ValueError('Run make setup first.')
    if action == 'https-enable':
        for name in ['localhost.pem', 'localhost-key.pem']:
            cert = root / 'docker/nginx/certs' / name
            if not cert.is_file() or not cert.stat().st_size:
                raise ValueError(f'Missing {cert.relative_to(root)}. Run make https-setup or generate certificates first.')
    scheme = 'https' if action == 'https-enable' else 'http'
    origin = urlsplit(settings.get('APP_URL', 'http://localhost:18473'))
    port = settings.get('APP_PORT', '18473')
    host = origin.hostname or 'localhost'
    if ':' in host:
        host = f'[{host}]'
    separator = settings.get('COMPOSE_PATH_SEPARATOR', ':')
    updates = {'APP_URL': urlunsplit((scheme, f'{host}:{port}', '', '', '')),
               'COMPOSE_FILE': separator.join(['docker-compose.yml', 'docker-compose.https.yml']) if scheme == 'https' else 'docker-compose.yml'}
    write_values(path, text, updates)
    print(f'{scheme.upper()} configuration selected. Existing secrets and data are preserved.')
    protocol_summary(values(path.read_text()), root)


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('action', choices=['setup', 'url', 'summary', 'https-enable', 'http-enable'])
    try:
        run(parser.parse_args().action)
    except (OSError, ValueError) as error:
        print(f'Setup failed: {error}', file=sys.stderr)
        sys.exit(1)
