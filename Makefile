.DEFAULT_GOAL := help

.PHONY: help setup env build up down restart destroy logs shell artisan composer migrate fresh seed test pint pail key cache-clear

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-15s\033[0m %s\n", $$1, $$2}'

setup: env build up ## Full initial setup: env file, build, start, key, migrate
	docker compose exec app php artisan key:generate
	docker compose exec app php artisan migrate
	@echo ""
	@echo "✔ Setup complete — app running at http://localhost:8080"

env: ## Create .env from .env.example if missing
	@test -f .env || (cp .env.example .env && echo ".env created from .env.example")

build: ## Build the Docker images
	docker compose build

up: ## Start the containers and wait until healthy
	docker compose up -d --wait

down: ## Stop the containers (keeps data)
	docker compose down

restart: ## Restart the containers
	docker compose restart

destroy: ## Stop containers and remove volumes (deletes MySQL/Redis data)
	docker compose down -v

logs: ## Tail container logs
	docker compose logs -f

shell: ## Open a shell in the app container
	docker compose exec app bash

artisan: ## Run an artisan command, e.g. make artisan cmd="route:list"
	docker compose exec app php artisan $(cmd)

composer: ## Run a composer command, e.g. make composer cmd="require foo/bar"
	docker compose exec app composer $(cmd)

key: ## Generate the application key
	docker compose exec app php artisan key:generate

migrate: ## Run database migrations
	docker compose exec app php artisan migrate

fresh: ## Drop all tables and re-run migrations
	docker compose exec app php artisan migrate:fresh

seed: ## Run database seeders
	docker compose exec app php artisan db:seed

test: ## Run the test suite
	docker compose exec app php artisan test

pint: ## Fix code style with Pint
	docker compose exec app ./vendor/bin/pint

pail: ## Tail application logs with Pail
	docker compose exec app php artisan pail

cache-clear: ## Clear all Laravel caches
	docker compose exec app php artisan optimize:clear
