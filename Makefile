.PHONY: browser-regression-test mcp-offline-test mcp-test up down build validation-image logs shell test test-unit test-feature frontend-test design-validate lint format composer-validate compose-config validate health https-check browser-test

TEST_ENV = -e APP_ENV=testing -e APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: -e CACHE_STORE=array -e SESSION_DRIVER=array -e MOCK_DASHBOARD_AUTH_ENABLED=false -e MOCK_LOG_STDOUT=false -e MOCK_LOG_FILE=false -e RUN_MIGRATIONS=false
APP_URL ?= $(shell python3 scripts/setup.py url)
.DEFAULT_GOAL := help
.NOTPARALLEL: validate

.PHONY: help check-tools setup setup-test status restart nginx-check nginx-reload https-setup https-enable http-enable browser-install artisan

help:
	@printf '%s\n' \
	  'First start: make setup → read .env credentials → make up → make health' \
	  'setup          Check tools and create .env / fill placeholder secrets safely' \
	  'up / down      Build and start / stop (keep database volumes)' \
	  'status / logs  Container status / follow application, database and Nginx logs' \
	  'health         Check .env APP_URL (override with APP_URL=...)' \
	  'https-setup    Install local CA, generate missing certificates, enable dual HTTP/HTTPS' \
	  'https-enable   Enable existing certificates; then run make up' \
	  'http-enable    Return to HTTP only; then run make up' \
	  'https-check    Verify both protocols; optional MOCKDECK_SMOKE_CA=/path/to/CA.pem' \
	  'nginx-check / nginx-reload  Validate Nginx config / validate and recreate Nginx' \
	  'restart        Recreate app, worker and Nginx after environment changes' \
	  'validate       Compose, design tokens, setup tests, frontend, image, Composer, Pint, PHPUnit' \
	  'setup-test / test / test-unit / test-feature / frontend-test / design-validate / lint' \
	  'mcp-test / mcp-offline-test  MCP suite / real client with external networking disabled' \
	  'format         Apply PHP formatting to source files' \
	  'browser-install / browser-test  Install Playwright / read-only checks in both themes' \
	  'browser-regression-test  Fixture-writing UI checks; disposable DB and opt-in required' \
	  'build / shell / artisan ARGS="..."  Clean image build / shell / Artisan command' \
	  'Full setup and prerequisites: docs/SETUP.md'

check-tools:
	@command -v docker >/dev/null || { echo "Install Docker Engine/Desktop first." >&2; exit 1; }
	@docker compose version >/dev/null
	@docker info >/dev/null || { echo "Start Docker; on Windows enable WSL integration." >&2; exit 1; }
	@command -v python3 >/dev/null || { echo "Install Python 3 first." >&2; exit 1; }
	@command -v curl >/dev/null || { echo "Install curl first." >&2; exit 1; }

setup: check-tools
	python3 scripts/setup.py setup
	docker compose config --quiet

status:
	docker compose ps -a

restart:
	docker compose up -d --force-recreate --wait app callback-worker
	docker compose up -d --force-recreate --wait nginx

nginx-check:
	docker compose run --rm --no-deps nginx nginx -t

nginx-reload: nginx-check
	docker compose up -d --force-recreate --wait nginx

https-setup:
	@test -f .env || { echo "Run make setup first." >&2; exit 1; }
	@command -v mkcert >/dev/null || { echo "Install mkcert first; see docs/HTTPS.md." >&2; exit 1; }
	mkcert -install
	mkdir -p docker/nginx/certs
	@if [ ! -s docker/nginx/certs/localhost.pem ] && [ ! -s docker/nginx/certs/localhost-key.pem ]; then \
		mkcert -cert-file docker/nginx/certs/localhost.pem -key-file docker/nginx/certs/localhost-key.pem localhost 127.0.0.1 ::1; \
	elif [ ! -s docker/nginx/certs/localhost.pem ] || [ ! -s docker/nginx/certs/localhost-key.pem ]; then \
		echo "Incomplete certificate pair. Restore the missing file or regenerate the pair manually; see docs/HTTPS.md." >&2; exit 1; \
	fi
	python3 scripts/setup.py https-enable

https-enable:
	python3 scripts/setup.py https-enable

http-enable:
	python3 scripts/setup.py http-enable

browser-install:
	npm install --no-save --package-lock=false playwright@1.62.1
	npx playwright install --with-deps chromium

artisan:
	docker compose exec app php artisan $(ARGS)

up:
	@test -f .env || { echo "Run make setup first." >&2; exit 1; }
	@docker compose up -d --build --wait || { \
		docker compose ps -a; \
		docker compose logs --tail=80 app db nginx; \
		exit 1; \
	}

down:
	docker compose down

build:
	docker compose build --no-cache

validation-image:
	docker compose build app

logs:
	docker compose logs -f nginx app callback-worker db

test:
	docker compose run --rm $(TEST_ENV) app php artisan test

# Full real-client workflow with loopback only; no external network or services.
mcp-offline-test: validation-image
	docker run --rm --network none -e APP_ENV=testing -e APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: -e CACHE_STORE=array -e SESSION_DRIVER=array -e MOCK_DASHBOARD_AUTH_ENABLED=false -e MOCK_LOG_STDOUT=false -e MOCK_LOG_FILE=false -e RUN_MIGRATIONS=false mockdeck-laravel-app:latest php artisan test --filter=McpClientIntegrationTest

mcp-test:
	docker compose run --rm $(TEST_ENV) app php artisan test --filter=Mcp

test-unit:
	docker compose run --rm $(TEST_ENV) app php artisan test --testsuite=Unit

test-feature:
	docker compose run --rm $(TEST_ENV) app php artisan test --testsuite=Feature

frontend-test:
	node --test tests/Frontend/*.test.mjs

design-validate:
	python3 scripts/validate_design_tokens.py

lint:
	docker compose run --rm $(TEST_ENV) app vendor/bin/pint --test

format:
	docker compose run --rm --no-deps -v "$(CURDIR):/var/www/html" -v /var/www/html/vendor --entrypoint vendor/bin/pint app

composer-validate:
	docker compose run --rm $(TEST_ENV) app composer validate --strict

compose-config:
	docker compose config --quiet

validate: compose-config setup-test design-validate frontend-test validation-image composer-validate lint test

health:
	@if curl --fail --silent --show-error --retry 5 --retry-delay 2 --retry-connrefused "$(APP_URL)/up"; then \
		printf '\nMockDeck is healthy.\n'; \
	else \
		status=$$?; \
		printf '\nHealth check failed. Container status:\n' >&2; \
		docker compose ps -a >&2; \
		printf '\nRecent app and nginx logs:\n' >&2; \
		docker compose logs --tail=80 app nginx >&2; \
		exit $$status; \
	fi

shell:
	docker compose exec app sh

# Requires a running dual-protocol service and a trusted certificate.
https-check:
	MOCKDECK_SMOKE_ORIGIN="$(APP_URL)" python3 scripts/smoke_http_https.py

# Requires Playwright and an accessible dashboard (both themes are tested).
browser-test:
	node tests/Browser/layout-smoke.mjs
	node tests/Browser/documentation-smoke.mjs

setup-test:
	python3 -m unittest discover -s tests/Setup -p "*_test.py"

# Writes test fixtures. Set MOCKDECK_BROWSER_FIXTURE=1 only for a disposable DB.
browser-regression-test:
	node tests/Browser/critique-regressions.mjs
	node tests/Browser/endpoint-editor-regressions.mjs
