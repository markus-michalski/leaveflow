# LeaveFlow — unified command surface
# Same targets work locally (via Docker) and in CI.
#
# Usage:
#   make help       — list all targets
#   make up         — start containers in background
#   make down       — stop containers
#   make install    — composer install inside the app container
#   make test       — run PHPUnit test suite
#   make stan       — run PHPStan static analysis
#   make cs         — run PHP-CS-Fixer (dry-run)
#   make cs-fix     — run PHP-CS-Fixer (apply fixes)
#   make deptrac    — check architecture boundaries
#   make ci         — full CI pipeline locally (stan + cs + test + deptrac)
#   make shell      — open a bash shell inside the app container

SHELL := /bin/bash
DC    := docker compose
EXEC  := $(DC) exec -u app app
RUN   := $(DC) run --rm -u app app

export USER_ID  ?= $(shell id -u)
export GROUP_ID ?= $(shell id -g)

.DEFAULT_GOAL := help

.PHONY: help
help: ## Show this help
	@awk 'BEGIN {FS = ":.*##"; printf "\nUsage:\n  make \033[36m<target>\033[0m\n\nTargets:\n"} /^[a-zA-Z_-]+:.*?##/ { printf "  \033[36m%-15s\033[0m %s\n", $$1, $$2 }' $(MAKEFILE_LIST)

## -- Containers --------------------------------------------------------

.PHONY: build
build: ## Build the Docker images
	$(DC) build

.PHONY: up
up: ## Start containers in background
	$(DC) up -d --build

.PHONY: down
down: ## Stop and remove containers
	$(DC) down

.PHONY: restart
restart: down up ## Restart the stack

.PHONY: logs
logs: ## Tail application logs
	$(DC) logs -f app

.PHONY: shell
shell: ## Open a bash shell inside the app container
	$(EXEC) bash

## -- Dependencies -----------------------------------------------------

.PHONY: install
install: ## composer install inside the app container
	$(EXEC) composer install --prefer-dist --no-progress --no-interaction

.PHONY: update
update: ## composer update inside the app container
	$(EXEC) composer update --prefer-dist --no-progress --no-interaction

## -- Quality Gates ----------------------------------------------------

.PHONY: test
test: ## Run PHPUnit test suite
	$(EXEC) bin/phpunit

.PHONY: test-coverage
test-coverage: ## Run tests with Pcov coverage
	$(EXEC) bin/phpunit --coverage-text --coverage-clover=coverage.xml

.PHONY: stan
stan: ## Run PHPStan (Level 8)
	$(EXEC) vendor/bin/phpstan analyse --memory-limit=1G

.PHONY: cs
cs: ## PHP-CS-Fixer dry-run
	$(EXEC) vendor/bin/php-cs-fixer fix --dry-run --diff

.PHONY: cs-fix
cs-fix: ## PHP-CS-Fixer apply fixes
	$(EXEC) vendor/bin/php-cs-fixer fix

.PHONY: deptrac
deptrac: ## Deptrac architecture check
	$(EXEC) vendor/bin/deptrac analyse --no-progress

.PHONY: ci
ci: stan cs test deptrac ## Full CI pipeline (stan + cs + test + deptrac)
	@echo "✓ CI pipeline passed"

.PHONY: install-hooks
install-hooks: ## Activate pre-commit hooks from .githooks/
	git config core.hooksPath .githooks
	@echo "✓ git hooks path set to .githooks/"

## -- Symfony ----------------------------------------------------------

.PHONY: console
console: ## Run a Symfony console command: make console CMD="about"
	$(EXEC) bin/console $(CMD)

.PHONY: cache-clear
cache-clear: ## Clear Symfony cache
	$(EXEC) bin/console cache:clear

.PHONY: db-reset
db-reset: ## Drop, create, migrate the database (DEV ONLY)
	$(EXEC) bin/console doctrine:database:drop --force --if-exists
	$(EXEC) bin/console doctrine:database:create
	$(EXEC) bin/console doctrine:migrations:migrate --no-interaction
