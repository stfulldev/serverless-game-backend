SHELL := /bin/bash
.DEFAULT_GOAL := help

COMPOSE := docker compose

export APP_UID ?= $(shell id -u)
export APP_GID ?= $(shell id -g)

.PHONY: help init env build rebuild install composer-install npm-install key up stop restart down destroy ps logs logs-app logs-node logs-dynamodb shell node-shell artisan composer npm test format assets quality

help: ## Show available commands
	@awk 'BEGIN {FS = ":.*##"; printf "Usage: make <target>\n\nTargets:\n"} /^[a-zA-Z0-9_-]+:.*##/ {printf "  %-20s %s\n", $$1, $$2}' $(MAKEFILE_LIST)

init: env ## Build and start the project after a fresh clone
	@$(MAKE) --no-print-directory build
	@$(MAKE) --no-print-directory install
	@$(MAKE) --no-print-directory key
	@$(MAKE) --no-print-directory up
	@$(MAKE) --no-print-directory ps

env: ## Create .env from .env.example when it is missing
	@if [ ! -f .env ]; then cp .env.example .env; printf 'Created .env from .env.example\n'; fi

build: ## Build or update development images
	$(COMPOSE) build --pull

rebuild: ## Rebuild development images without cache
	$(COMPOSE) build --pull --no-cache

install: composer-install npm-install ## Install PHP and Node dependencies in Docker volumes

composer-install: ## Install locked Composer dependencies
	$(COMPOSE) run --rm --no-deps app composer install --no-interaction --prefer-dist

npm-install: ## Install locked npm dependencies
	$(COMPOSE) run --rm --no-deps node npm ci

key: env ## Generate APP_KEY when it is missing
	@if grep -Eq '^APP_KEY=base64:.+' .env; then printf 'APP_KEY is already configured\n'; else $(COMPOSE) run --rm --no-deps app php artisan key:generate --force; fi

up: env ## Start all services in the background
	$(COMPOSE) up -d --remove-orphans

stop: ## Stop services without removing them
	$(COMPOSE) stop

restart: ## Restart all running services
	$(COMPOSE) restart

down: ## Stop and remove containers while preserving data volumes
	$(COMPOSE) down --remove-orphans

destroy: ## Remove containers and all project data volumes
	@read -r -p 'Delete containers, dependencies, and local DynamoDB data? [y/N] ' answer; \
	if [[ "$$answer" =~ ^[Yy]$$ ]]; then $(COMPOSE) down --volumes --remove-orphans; else printf 'Cancelled\n'; fi

ps: ## Show service status and health
	$(COMPOSE) ps

logs: ## Follow logs from all services
	$(COMPOSE) logs --follow --tail=100

logs-app: ## Follow Laravel logs
	$(COMPOSE) logs --follow --tail=100 app

logs-node: ## Follow Vite logs
	$(COMPOSE) logs --follow --tail=100 node

logs-dynamodb: ## Follow DynamoDB Local logs
	$(COMPOSE) logs --follow --tail=100 dynamodb

shell: ## Open a shell in the running Laravel container
	$(COMPOSE) exec app bash

node-shell: ## Open a shell in the running Node container
	$(COMPOSE) exec node bash

artisan: ## Run Artisan; example: make artisan ARGS="route:list"
	$(COMPOSE) exec app php artisan $(ARGS)

composer: ## Run Composer; example: make composer ARGS="show --direct"
	$(COMPOSE) exec app composer $(ARGS)

npm: ## Run npm; example: make npm ARGS="outdated"
	$(COMPOSE) exec node npm $(ARGS)

test: ## Run the PHPUnit suite in an isolated container
	$(COMPOSE) run --rm --no-deps app php artisan test --compact

format: ## Format PHP files with Laravel Pint
	$(COMPOSE) run --rm --no-deps app vendor/bin/pint --format agent

assets: ## Build production frontend assets
	$(COMPOSE) run --rm --no-deps node npm run build

quality: ## Validate dependencies, run audits, tests, and the asset build
	$(COMPOSE) run --rm --no-deps app composer validate --strict
	$(COMPOSE) run --rm --no-deps app composer audit
	$(COMPOSE) run --rm --no-deps node npm audit --audit-level=high
	$(COMPOSE) run --rm --no-deps app php artisan test --compact
	$(COMPOSE) run --rm --no-deps node npm run build
