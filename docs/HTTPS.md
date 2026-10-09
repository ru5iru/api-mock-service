# Local HTTP and HTTPS

MockDeck's default Compose configuration serves **HTTP at http://localhost:18473**. Optional `docker-compose.https.yml` enables **HTTPS at https://localhost:18473 on that same port**, retaining HTTP without redirects. Both listeners use the same PHP-FPM application, configuration, database and matching behavior. Scheme/host/port are excluded from mock signatures.

With the override enabled, Nginx detects whether the incoming connection is plaintext HTTP or TLS and routes it to private HTTP/HTTPS listeners. Docker publishes only `APP_PORT` (18473 by default). Internal ports 8080 and 8443 bind to container loopback and are not published. Without the override, the default configuration remains HTTP-only and requires no certificates.

## Generate a certificate

For the Windows browser + WSL Docker setup, install [mkcert](https://github.com/FiloSottile/mkcert#installation) on **Windows**. From Windows PowerShell in the repository folder:

```powershell
New-Item -ItemType Directory -Force docker/nginx/certs
mkcert -install
mkcert -cert-file docker/nginx/certs/localhost.pem -key-file docker/nginx/certs/localhost-key.pem localhost 127.0.0.1 ::1
```

Approve the certificate-store installation when prompted. Restart your browser if it has not picked up the new local CA. These files are mounted read-only into Nginx; they are excluded from Git and the app build context. Do not copy private keys between installations.

For Linux/macOS, install mkcert for that OS and run the same mkcert commands from the repository after `mkdir -p docker/nginx/certs`. Trust belongs to each client OS: Windows trust does not automatically configure WSL curl, containers or another machine.

## Enable both protocols on one port

Add the following to your **existing `.env`**, keeping application/database/dashboard secrets intact:

```dotenv
APP_PORT=18473
COMPOSE_FILE=docker-compose.yml:docker-compose.https.yml
```

The `:` separator is for Compose run from WSL/Linux/macOS. When invoking Docker Compose natively from Windows, use `COMPOSE_PATH_SEPARATOR=;` and `COMPOSE_FILE=docker-compose.yml;docker-compose.https.yml`, or pass both `-f` arguments explicitly.

`APP_URL` controls the origin used by **Copy mock curl** and background URL generation; it does not disable either listener. Keep `APP_URL=http://localhost:18473`, or use `APP_URL=https://localhost:18473` to copy HTTPS commands by default.

From WSL, after generating certificates:

```bash
docker compose config --quiet
docker compose up -d --force-recreate app callback-worker
docker compose run --rm --no-deps nginx nginx -t
docker compose up -d --force-recreate nginx
```

If you applied the earlier two-port setup, remove `APP_HTTPS_PORT` from `.env` and change any `APP_URL=https://localhost:18474` to `APP_URL=https://localhost:18473`. Recreating Nginx removes the former 18474 binding. `APP_HTTPS_PORT` is no longer used.

The `.env` `COMPOSE_FILE` setting ensures subsequent `docker compose`/`make up` commands retain the HTTPS override. Alternatively, for one-off commands, use `docker compose -f docker-compose.yml -f docker-compose.https.yml ...` consistently. Do not replace your whole `.env` with `.env.example`.

Open either:

- http://localhost:18473/dashboard
- https://localhost:18473/dashboard

When switching protocols, log in again if the browser requires it. Do not force `SESSION_SECURE_COOKIE=true` if you need the dashboard login to work over HTTP as well; Secure cookies are sent only over HTTPS.

## Check connectivity

```bash
curl -fsS http://localhost:18473/up
curl -fsS https://localhost:18473/up
```

The HTTPS call requires the local CA to be trusted by the curl client's OS. For an explicit CA file, use `curl --cacert /path/to/rootCA.pem https://localhost:18473/up`. `curl -k https://localhost:18473/up` is a temporary diagnostic that bypasses certificate verification, not the normal trusted setup. `make health APP_URL=https://localhost:18473` also requires client trust.

The official `nginx:1.27-alpine` image includes the stream, TLS-preread and HTTP real-IP modules used by `docker/nginx/same-port.conf`. The stream listener on container port 80 reads the TLS handshake without terminating TLS, then forwards plaintext connections to 127.0.0.1:8080 and TLS connections to 127.0.0.1:8443. The private listeners accept PROXY protocol from loopback only, preserving the client address for logs and PHP. TLS is terminated at the private HTTPS listener. No HTTP-to-HTTPS redirect or HSTS policy is added.

Nginx includes its standard `fastcgi_params`, which passes `HTTPS=$https` to PHP for TLS requests. HTTP and HTTPS share `docker/nginx/app-locations.conf`, so Laravel routing and serving behavior do not drift between protocols.

Missing certificates prevent the optional HTTPS Nginx configuration from starting. Plain HTTP-only startup continues to work without certificates when the override is not enabled. To disable HTTPS, remove `COMPOSE_FILE` from `.env` and recreate Nginx with the base Compose configuration.

This setup is for localhost development. An externally exposed deployment should use a certificate valid for its real DNS name and an appropriate TLS deployment configuration.

## Regression smoke test

With the dual-protocol Compose setup running and the local CA trusted, run:

```bash
MOCKDECK_SMOKE_ORIGIN=http://localhost:18473 python3 scripts/smoke_http_https.py
```

If the client does not use the OS trust store, add `MOCKDECK_SMOKE_CA=/path/to/rootCA.pem`. This verifies both protocols on exactly the same port, concurrent health requests, dotfile denial, and scheme-correct dashboard assets. It needs only Python 3; it makes no configuration or database changes. For a real configured mock, also call the dashboard-copied curl with each scheme and confirm the same response.
