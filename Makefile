.PHONY: help install migrate test stan cs serve worker clean

PHP ?= php
COMPOSER ?= composer
CONSOLE := $(PHP) bin/console

help: ## Show available targets
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN{FS=":.*?## "}{printf "  \033[36m%-12s\033[0m %s\n", $$1, $$2}'

install: ## composer install
	$(COMPOSER) install

migrate: ## Apply doctrine migrations
	$(CONSOLE) doctrine:migrations:migrate --no-interaction

test: ## Run phpunit
	$(PHP) bin/phpunit

stan: ## Run phpstan
	vendor/bin/phpstan analyse --memory-limit=512M

cs: ## (placeholder) format code — wire php-cs-fixer here if needed
	@echo "no cs tool wired"

serve: ## Built-in PHP server on :8000
	$(PHP) -S 127.0.0.1:8000 -t public

worker: ## Run the messenger worker for the webhooks transport
	$(CONSOLE) messenger:consume webhooks -vv

clean: ## Drop the dev DB and clear caches
	rm -f var/data_*.db
	$(CONSOLE) cache:clear
