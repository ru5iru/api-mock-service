# Local HTTP and HTTPS

The default Compose configuration serves HTTP at `http://localhost:18473` without certificates. Enabling `docker-compose.https.yml` serves **both HTTP and HTTPS on that same port**, without redirecting HTTP. Both use the same endpoints, responses, database and matching behavior; request signatures ignore scheme, host and port.

Run Bash commands from the repository root in WSL/Linux. Run PowerShell commands in Windows PowerShell. Do not paste PowerShell commands into Bash.

## First-time startup

Requirements: Docker Engine/Desktop with Compose v2, OpenSSL, and mkcert for optional local HTTPS. On Windows, enable Docker Desktop integration for your WSL distribution. PHP, Composer and PostgreSQL run in containers; separate host installations are not needed. `make` is optional for startup.

For a **new installation only**:

```bash
cp .env.example .env
openssl rand -base64 32
```

Put the generated value in `.env` as `APP_KEY=base64:YOUR_GENERATED_VALUE`. Set unique `DB_PASSWORD` and `MOCK_DASHBOARD_PASSWORD` values and choose `MOCK_DASHBOARD_USERNAME`. Keep existing secrets when updating an installation; do not overwrite its `.env`.

For HTTP only, leave `COMPOSE_FILE` commented out, set `APP_PORT=18473` and `APP_URL=http://localhost:18473`, then use the startup commands below. For both protocols, generate certificates and enable the override **before** startup.

## Generate certificates: choose one route

### Route A — generate in WSL/Ubuntu

```bash
sudo apt update
sudo apt install mkcert libnss3-tools
mkcert -install
mkdir -p docker/nginx/certs
mkcert -cert-file docker/nginx/certs/localhost.pem -key-file docker/nginx/certs/localhost-key.pem localhost 127.0.0.1 ::1
ls -l docker/nginx/certs
```

The directory must contain **localhost.pem** and **localhost-key.pem**. Use forward slashes: `mkdir docker\nginx\certs` in Bash creates an incorrectly named `dockernginxcerts` directory. `mkdir -p docker/nginx/certs` creates the intended nested directory. If the incorrect directory is empty, remove it with `rmdir dockernginxcerts`.

Run mkcert consistently as the same Linux user. Root and a normal user have different CA directories; `mkcert -CAROOT` identifies the CA used by the current user. Installing this CA in WSL does not install it in Windows.

### Route B — generate in Windows PowerShell

Install [mkcert for Windows](https://github.com/FiloSottile/mkcert#installation), then run in the repository folder:

```powershell
New-Item -ItemType Directory -Force docker/nginx/certs
mkcert -install
mkcert -cert-file docker/nginx/certs/localhost.pem -key-file docker/nginx/certs/localhost-key.pem localhost 127.0.0.1 ::1
```

This trusts the Windows-generated CA on Windows. WSL curl needs trust for that same CA separately, or an explicit `--cacert` argument. Do not generate a second unrelated CA to try to trust the first certificate.

For native Linux/macOS, follow the [mkcert installation instructions](https://github.com/FiloSottile/mkcert#installation) for that OS, then run `mkdir -p docker/nginx/certs` and the certificate-generation command above.

Certificates mount read-only into Nginx and are excluded from Git and the app build context. Each installation generates its own certificate/key; none are distributed with MockDeck.

## Trust a WSL-generated certificate in Windows

If you used Route A and Windows Chrome shows `NET::ERR_CERT_AUTHORITY_INVALID`, import the **same CA that issued the server certificate**. Replace `YOUR_WINDOWS_USER` with your Windows profile folder name.

In WSL, as the Linux user that generated the certificate:

```bash
cp "$(mkcert -CAROOT)/rootCA.pem" /mnt/c/Users/YOUR_WINDOWS_USER/Desktop/mockdeck-rootCA.pem
```

In Windows PowerShell, as the Windows user running the browser:

```powershell
certutil -user -addstore Root "$env:USERPROFILE\Desktop\mockdeck-rootCA.pem"
```

Close all Chrome windows, reopen Chrome, and visit `https://localhost:18473/dashboard`. Only import your own generated CA. Copy/import **rootCA.pem only**; never copy/share `rootCA-key.pem`. No application rebuild, Nginx restart or server-certificate regeneration is required just to update browser trust.

## Enable both protocols on one port

Add/update these settings in your existing `.env`:

```dotenv
APP_PORT=18473
APP_URL=https://localhost:18473
COMPOSE_FILE=docker-compose.yml:docker-compose.https.yml
```

The `:` separator is for Compose run from WSL/Linux/macOS. For native Windows Compose, use `COMPOSE_PATH_SEPARATOR=;` and `COMPOSE_FILE=docker-compose.yml;docker-compose.https.yml`, or pass both `-f` files explicitly.

`APP_URL` controls the origin used by **Copy mock curl** and background URL generation. Set it to HTTP if you prefer copied commands to use HTTP; both listeners remain available. Custom ports must match in `APP_PORT` and `APP_URL`.

For the earlier two-port setup, remove `APP_HTTPS_PORT` and change any HTTPS `APP_URL` using 18474 to 18473. Recreating Nginx removes the old published port. `APP_HTTPS_PORT` is no longer used.

## Build and start

For a first installation, or when application code has changed:

```bash
docker compose config --quiet
docker compose up -d --build --wait
docker compose ps -a
```

The app startup automatically runs database migrations. No separate migration command is needed. The same commands can be invoked through `make up` if make is installed. Compose validation checks configuration structure, not the existence or validity of certificate files. Containers starting successfully is not a substitute for connectivity checks below.

For an existing running installation where only certificates or Nginx settings changed:

```bash
docker compose run --rm --no-deps nginx sh -c 'ls -l /etc/nginx/certs && nginx -t'
docker compose up -d --force-recreate nginx
```

The app must already be running for Nginx to resolve its FastCGI upstream during validation. If you changed `APP_URL` or other application environment settings, recreate app and callback-worker first, then recreate Nginx so its upstream address is refreshed:

```bash
docker compose up -d --force-recreate app callback-worker
docker compose run --rm --no-deps nginx nginx -t
docker compose up -d --force-recreate nginx
```

The `.env` `COMPOSE_FILE` setting keeps subsequent Compose/make commands on the selected configuration. Without it, use `docker compose -f docker-compose.yml -f docker-compose.https.yml ...` consistently.

## Verify and sign in

```bash
curl -fsS http://localhost:18473/up
curl -fsS https://localhost:18473/up
```

Both should return HTTP 200. If the curl client's OS does not trust the issuing CA, use:

```bash
curl --cacert /path/to/rootCA.pem https://localhost:18473/up
```

Use `mkcert -CAROOT` on the machine/user that generated the certificate to locate `rootCA.pem`. `curl -k` bypasses verification and is only a temporary diagnostic. `make health` uses HTTP by default; `make health APP_URL=https://localhost:18473` requires client trust.

Open either `http://localhost:18473/dashboard` or `https://localhost:18473/dashboard` and sign in with your configured dashboard credentials. Do not force `SESSION_SECURE_COOKIE=true` if HTTP dashboard login is also required. Add an endpoint and at least one response, or import configuration, then test the copied mock curl. Changing only its scheme does not change request matching.

## Troubleshoot connection and certificate failures

| Symptom | Meaning and next action |
|---|---|
| `mkcert: command not found` | Install mkcert in the shell/OS where you are running it. On Ubuntu use the Route A package commands; installing in WSL does not install a Windows executable. |
| `failed to save certificate ... no such file or directory` | The output directory is missing or was created with Bash backslashes. Run `mkdir -p docker/nginx/certs`, then generate both files from the repository root. |
| Nginx `Restarting` and `cannot load certificate ... localhost.pem ... No such file` | The certificate is absent from the container mount; both HTTP and HTTPS are unavailable because Nginx cannot start. Check local files, inspect the mount with the command below, generate missing files, validate, then recreate only Nginx. |
| `curl: (7) Failed to connect` | Nothing is reachable at that address/port. Inspect container status, published port and Nginx logs. This happens before TLS validation or mock matching. |
| Chrome `NET::ERR_CERT_AUTHORITY_INVALID` or curl error 60 | TLS is reachable but the client does not trust the issuing CA. Install that CA in the client's trust store or use `--cacert`; see Windows/WSL instructions above. |
| `no port 80/tcp` during restart | Inspect Nginx startup logs first; a restarting container is not a working listener. Also verify the merged configuration includes the base Compose file and correct `APP_PORT`. |
| `can not modify ... default.conf (read-only file system?)` | The image's IPv6 startup helper cannot edit a deliberately read-only configuration mount. This message alone is informational; inspect the subsequent fatal error. Do not make the config writable to fix a missing certificate. |

Diagnostics, from the repository root:

```bash
docker compose ps -a
docker compose port nginx 80
docker compose logs --tail=80 nginx
ls -l docker/nginx/certs
docker compose run --rm --no-deps nginx sh -c 'ls -l /etc/nginx/certs && nginx -t'
grep -E '^(APP_PORT|APP_URL|COMPOSE_FILE|COMPOSE_PATH_SEPARATOR)=' .env
curl -v http://127.0.0.1:18473/up
```

Do not paste your full `.env` or private keys into support messages. Fix missing local files before recreating Nginx; certificate-only changes do not require rebuilding the application image.

## Architecture, fallback and regression check

The official `nginx:1.27-alpine` image includes the stream, TLS-preread and HTTP real-IP modules used by `docker/nginx/same-port.conf`. One published loopback port maps to container port 80. TLS detection routes plaintext to private 127.0.0.1:8080 and TLS to private 127.0.0.1:8443. A trusted local PROXY-protocol hop preserves client addresses. Both use `docker/nginx/app-locations.conf`; standard FastCGI parameters pass the actual HTTPS state to Laravel. Internal listeners are not published. No redirect or HSTS policy is added.

Missing certificates prevent the dual-protocol Nginx configuration from starting. To return to HTTP only, remove/comment `COMPOSE_FILE`, set `APP_URL=http://localhost:18473`, recreate app/worker if that value changed, and recreate Nginx using only the base Compose configuration.

With the dual-protocol service running and the CA trusted:

```bash
MOCKDECK_SMOKE_ORIGIN=http://localhost:18473 python3 scripts/smoke_http_https.py
```

Add `MOCKDECK_SMOKE_CA=/path/to/rootCA.pem` when Python needs explicit CA trust. This read-only check verifies both protocols on the same port, concurrent health requests, dotfile denial and scheme-correct dashboard assets. For a configured mock, also invoke the dashboard-copied curl with each scheme.

This is localhost development setup. Externally exposed deployments need a certificate valid for their real DNS name and an appropriate production TLS configuration. Official references: [mkcert](https://github.com/FiloSottile/mkcert) and [Windows certutil](https://learn.microsoft.com/en-us/windows-server/administration/windows-commands/certutil#-addstore).
