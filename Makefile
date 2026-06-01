# Deal Promoter — common operations.
# Every app command runs inside the `app` container (working dir: apps/pipeline).
# Run `make` or `make help` for the list.

DC      := docker compose
EXEC    := $(DC) exec app
CONSOLE := $(EXEC) bin/console

# Verbosity / extra args for `make cycle` (e.g. `make cycle ARGS=-v`).
ARGS ?= -vv

.DEFAULT_GOAL := help

## ----------------------------------------------------------------------------
## Environment
## ----------------------------------------------------------------------------

.PHONY: up
up: ## Start app + postgres in the background
	$(DC) up -d

.PHONY: down
down: ## Stop and remove the containers
	$(DC) down

.PHONY: build
build: ## Rebuild the app image
	$(DC) build

.PHONY: restart
restart: down up ## Restart the stack

.PHONY: logs
logs: ## Tail the app container logs
	$(DC) logs -f app

.PHONY: shell
shell: ## Open a shell inside the app container
	$(EXEC) bash

.PHONY: setup
setup: up install migrate migrate-test ## First-run: start, install deps, migrate dev + test DBs

## ----------------------------------------------------------------------------
## Dependencies & database
## ----------------------------------------------------------------------------

.PHONY: install
install: ## Install Composer dependencies
	$(EXEC) composer install

.PHONY: migrate
migrate: ## Run pending Doctrine migrations
	$(CONSOLE) doctrine:migrations:migrate -n

.PHONY: migrate-test
migrate-test: ## Run pending migrations on the test database (needed before `make qa`)
	$(CONSOLE) doctrine:migrations:migrate -n --env=test

.PHONY: migration
migration: ## Generate a migration from entity changes
	$(CONSOLE) doctrine:migrations:diff

## ----------------------------------------------------------------------------
## Pipeline
## ----------------------------------------------------------------------------

.PHONY: cycle
cycle: ## Run one Cycle (override detail with ARGS=-v)
	$(CONSOLE) app:run-cycle $(ARGS)

.PHONY: review
review: ## Print the review page URL
	@echo "Review page: http://localhost:8000"

## ----------------------------------------------------------------------------
## Quality
## ----------------------------------------------------------------------------

.PHONY: qa
qa: ## Run the full QA suite (phpunit + phpstan + cs-fixer)
	$(EXEC) composer qa

.PHONY: test
test: ## Run PHPUnit
	$(EXEC) vendor/bin/phpunit

.PHONY: phpstan
phpstan: ## Run PHPStan (max level)
	$(EXEC) vendor/bin/phpstan analyse

.PHONY: cs
cs: ## Check code style (dry-run)
	$(EXEC) vendor/bin/php-cs-fixer fix --dry-run --diff

.PHONY: cs-fix
cs-fix: ## Apply code-style fixes
	$(EXEC) vendor/bin/php-cs-fixer fix

## ----------------------------------------------------------------------------
## Help
## ----------------------------------------------------------------------------

.PHONY: help
help: ## Show this help
	@grep -hE '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-12s\033[0m %s\n", $$1, $$2}'
