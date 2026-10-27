# First-time setup and daily commands

Run every `make` command below from the repository root in **Bash on Linux, macOS or WSL**. On Windows, use WSL with Docker Desktop integration enabled. PHP, Composer and PostgreSQL run in containers; you do not need to install them on the host.

## 1. Install prerequisites

Install Docker Engine/Desktop with **Compose v2**, GNU Make, Python 3 and curl. Start Docker before continuing. On Ubuntu/WSL, after installing Docker Desktop/Engine:

```bash
sudo apt update
sudo apt install make python3 curl
make help
```

Frontend/release validation additionally needs Node.js 22 (including npm). Browser checks install Playwright/Chromium. Optional local HTTPS needs mkcert; on Ubuntu/WSL install it with `sudo apt install mkcert libnss3-tools`. Make does not install Docker, Node or OS packages for you.

## 2. Prepare your instance

```bash
make setup
```

This checks Docker/Compose availability, creates `.env` from `.env.example` if missing, and generates a unique application key, database password and dashboard password wherever known placeholders or empty values remain. Re-running it preserves configured credentials, settings and database data. Secrets are not printed to the terminal. Open `.env` locally and note `MOCK_DASHBOARD_USERNAME` (default `admin`) and the generated `MOCK_DASHBOARD_PASSWORD` for login.

For a **new** `.env` without a complete, nonempty certificate pair, setup selects HTTP only (`COMPOSE_FILE=docker-compose.yml`) and an HTTP `APP_URL`. It does not generate certificates. With the certificate pair already present, setup retains the template's selection. For an **existing** `.env`, setup preserves its protocol selection and every configured value; use `make http-enable` explicitly if you want certificate-free HTTP. Setup/help output lists the effective Compose files, APP_URL scheme and next command without printing credentials.

The default port is **18473**. To use another port, edit both `APP_PORT` and `APP_URL` before starting, for example:

```dotenv
APP_PORT=19483
APP_URL=http://localhost:19483
```

Keep existing keys/passwords when updating an existing installation. Do not delete `.env` or regenerate its application key to perform an update. If you change a database password after PostgreSQL has already initialized, changing `.env` alone does not change the database role's password.

## 3. Start HTTP and sign in

For a new certificate-free installation, `make setup` has already selected HTTP. For an existing `.env` or a different selection, run `make http-enable` first. Read the credentials in `.env`, then:

```bash
make up
make health
make status
```

`up` builds current application code, starts the app, callback worker, database and Nginx, and waits for startup. Migrations run automatically. `health` reads `.env`'s `APP_URL` and checks `/up`. Open `http://localhost:18473/dashboard` (or your configured port), sign in using `.env` credentials, create/import an endpoint, then configure a response before calling its copied mock curl.

If startup fails, `make up` prints app/database/Nginx diagnostics. Use `make status` and `make logs` to investigate; Ctrl+C stops following logs without stopping the service.

## 4. Optional HTTP + HTTPS on the same port

After `make setup`, install mkcert and run as the same OS user each time:

```bash
make https-setup
make up
make health
make https-check
```

`https-setup` runs `mkcert -install`, creates the correct certificate directory, generates the localhost certificate/key only when both are absent, then selects the HTTPS Compose override and an HTTPS `APP_URL`. It preserves an existing certificate pair and refuses to overwrite an incomplete pair. `https-check` verifies **both** schemes on the configured port with TLS verification enabled.

**WSL and Windows have separate trust stores.** If certificates were generated in WSL, follow [Windows CA trust](HTTPS.md#trust-a-wsl-generated-certificate-in-windows) before opening HTTPS in Windows Chrome. Make cannot install a WSL CA into Windows automatically. Do not copy the CA private key. Existing Windows-generated certificates can be used with `make https-enable` followed by `make up`; trust that same CA in WSL too.

For an explicit CA rather than system trust:

```bash
CURL_CA_BUNDLE=/path/to/rootCA.pem make health
MOCKDECK_SMOKE_CA=/path/to/rootCA.pem make https-check
```

Both `http://localhost:18473` and `https://localhost:18473` work, without redirects. `APP_URL` determines the origin of copied mock curls. To return to HTTP only without deleting certificates or data:

```bash
make http-enable
make up
make health
```

See [HTTPS details and troubleshooting](HTTPS.md) for native Windows certificate generation, certificate expiry/replacement, and client trust. Protocol-selection helpers set the project's two-file/base-only Compose configuration; if you maintain additional custom Compose overlays, add those back explicitly afterward.

## 5. Update, inspect and stop

```bash
make up             # after application source changes: rebuild and apply
make restart        # after .env changes: recreate app, worker, then Nginx
make nginx-reload   # after certificate/Nginx-only changes: validate and recreate Nginx
make status
make logs
make shell
make down           # stops/removes containers; keeps database/log volumes
```

`nginx-check` and `nginx-reload` require the app to be running so Nginx can resolve its upstream. `restart` does not rebuild source changes: use `up` for those. There is deliberately no wipe-data/reset-volumes shortcut.

For administrative CLI operations, use `make artisan ARGS="list"` to discover commands. Commands that export configuration or generate credentials may output sensitive values; review the command's own help.

## 6. Validate changes

With Node.js 22, Python 3 and Docker available:

```bash
make validate
make browser-install
MOCKDECK_BASE_URL=http://localhost:18473 make browser-test
# If HTTPS is enabled:
make https-check
```

`validate` runs Compose config, setup-helper tests, design tokens, frontend tests, app-image build, Composer validation, Pint and PHPUnit. It does not change application configuration or run browser/connectivity checks. The latter need a running service. Browser checks need dashboard access; use an isolated local instance with dashboard authentication disabled if required, rather than disabling authentication on a shared instance. `browser-install` installs pinned tooling locally and may request OS-package installation privileges.

## Command reference

| Command | Purpose |
|---|---|
| `make` / `make help` | Show setup order and command menu |
| `make setup` / `make check-tools` | Prepare safe `.env` / check runtime prerequisites |
| `make up` / `make down` | Build/start / stop while retaining data |
| `make status` / `make logs` | Status / live app, worker and Nginx logs |
| `make health` | Check `.env` origin; override with `APP_URL=...` |
| `make https-setup` / `make https-enable` | Generate/trust certificates and enable / enable existing certificates |
| `make http-enable` / `make https-check` | Select HTTP only / test dual-protocol listener |
| `make restart` | Recreate application services and Nginx |
| `make nginx-check` / `make nginx-reload` | Validate config / validate then recreate Nginx |
| `make validate` | Complete automated non-browser validation |
| `make setup-test` / `make frontend-test` / `make design-validate` | Individual host-side checks |
| `make composer-validate` / `make lint` / `make format` | Metadata / PHP style check / apply formatting |
| `make test` / `make test-unit` / `make test-feature` | PHP suites in the app image |
| `make compose-config` / `make validation-image` / `make build` | Config / cached app build / uncached build |
| `make browser-install` / `make browser-test` | Install tooling / run layout and documentation checks in both themes |
| `make shell` / `make artisan ARGS="..."` | App shell / administrative Laravel command |

Make wraps Docker Compose; it does not replace it. Direct Compose remains available for advanced diagnostics. [Validation details](VALIDATION.md) explain CI and release checks.
