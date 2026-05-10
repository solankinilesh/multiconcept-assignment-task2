.PHONY: help up down build logs sh install migrate test stan cs worker drain clean

# Routes every PHP command through the `php` service so reviewers don't need a local
# PHP/Composer install. Override these vars to use a host PHP if you have one.
DC ?= docker compose
PHP_EXEC ?= $(DC) exec -T php
CONSOLE := $(PHP_EXEC) php bin/console

help: ## Show available targets
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN{FS=":.*?## "}{printf "  \033[36m%-10s\033[0m %s\n", $$1, $$2}'

up: ## Start php + nginx (worker stays off so its logs don't drown out HTTP logs)
	$(DC) up -d php nginx

down: ## Stop and remove all services
	$(DC) down

build: ## Rebuild the php image (do this if Dockerfile or php.ini changes)
	$(DC) build --no-cache php

logs: ## Tail container logs
	$(DC) logs -f

sh: ## Open a shell inside the php container
	$(DC) exec php bash

install: ## composer install inside the php container
	$(PHP_EXEC) composer install

migrate: ## Apply doctrine migrations
	$(CONSOLE) doctrine:migrations:migrate --no-interaction

test: ## Run phpunit
	$(PHP_EXEC) php bin/phpunit

stan: ## Run phpstan at level 8
	$(PHP_EXEC) vendor/bin/phpstan analyse --memory-limit=512M

cs: ## Run php-cs-fixer (apply changes)
	$(PHP_EXEC) sh -c 'PHP_CS_FIXER_IGNORE_ENV=1 vendor/bin/php-cs-fixer fix'

worker: ## Run the async worker in the foreground (Ctrl+C to stop)
	$(DC) run --rm worker php bin/console messenger:consume webhooks -vv

drain: ## Drain the failure transport (retry messages that exhausted retries)
	$(DC) run --rm worker php bin/console messenger:consume failed -vv --limit=20

clean: ## Drop the dev DB and clear caches
	rm -f var/data_*.db
	$(CONSOLE) cache:clear
