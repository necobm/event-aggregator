SERVICE   := app
COMPOSE   := docker compose

.PHONY: help up down build shell logs test

.DEFAULT_GOAL := help

help:
	@echo ""
	@echo "Usage: make [target]"
	@echo ""
	@echo "  build    Build the app image via docker compose"
	@echo "  up       Build and start the full stack (app + database)"
	@echo "  down     Stop and remove the stack (volumes are preserved)"
	@echo "  shell    Open an interactive shell inside the app container"
	@echo "  logs     Tail the app container logs"
	@echo "  test     Run the PHPUnit suite inside the app container"
	@echo ""

build:
	@echo "Building stack..."
	@$(COMPOSE) build

up: build
	@echo "Starting stack..."
	@$(COMPOSE) up -d
	@echo ""
	@echo "  Stack is up."
	@echo "  Run 'make shell' to open a shell inside the app container."
	@echo "  Run 'make down' to stop and remove it."

down:
	@$(COMPOSE) down
	@echo "Stack stopped."

shell:
	@$(COMPOSE) exec $(SERVICE) bash

logs:
	@$(COMPOSE) logs -f $(SERVICE)

test:
	@$(COMPOSE) exec $(SERVICE) vendor/bin/phpunit
