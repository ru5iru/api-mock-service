.PHONY: up down build validation-image logs shell test test-unit test-feature frontend-test design-validate lint format composer-validate compose-config validate health

TEST_ENV = -e APP_ENV=testing -e APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: -e CACHE_STORE=array -e SESSION_DRIVER=array -e MOCK_DASHBOARD_AUTH_ENABLED=false -e MOCK_LOG_STDOUT=false -e MOCK_LOG_FILE=false -e RUN_MIGRATIONS=false
APP_URL ?= http://localhost:18473

up:
	@docker compose up -d --build --wait || { \
		docker compose ps -a; \
		docker compose logs --tail=80 app db; \
		exit 1; \
	}

down:
	docker compose down

build:
	docker compose build --no-cache

validation-image:
	docker compose build app

logs:
	docker compose logs -f nginx app

test:
	docker compose run --rm $(TEST_ENV) app php artisan test

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

validate: compose-config design-validate frontend-test validation-image composer-validate lint test

health:
	@if curl --fail --silent --show-error --retry 5 --retry-delay 2 "$(APP_URL)/up"; then \
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
