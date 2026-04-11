IMAGE     := event-aggregator
CONTAINER := event-aggregator
CONTEXT   := .

.PHONY: help up down build shell logs

.DEFAULT_GOAL := help

help:
	@echo ""
	@echo "Usage: make [target]"
	@echo ""
	@echo "  build    Build the Docker image"
	@echo "  up       Build and start the container (runs composer install on boot)"
	@echo "  down     Stop and remove the container"
	@echo "  shell    Open an interactive shell inside the running container"
	@echo "  logs     Tail the container logs"
	@echo ""

up: build
	@echo "Starting container..."
	@docker run -d \
		--name $(CONTAINER) \
		--env-file $(CONTEXT)/.env.dev \
		-v $(PWD)/$(CONTEXT):/app \
		$(IMAGE) \
		bash -c "composer install --no-interaction; tail -f /dev/null"
	@echo ""
	@echo "  Service is up and running."
	@echo "  Container : $(CONTAINER)"
	@echo ""
	@echo "  Run 'make shell' to open a shell inside the container."
	@echo "  Run 'make down' to stop and remove it."

build:
	@echo "Building image $(IMAGE)..."
	@docker build -t $(IMAGE) $(CONTEXT)

down:
	@docker stop $(CONTAINER) 2>/dev/null || true
	@docker rm   $(CONTAINER) 2>/dev/null || true
	@echo "Container stopped and removed."

shell:
	@docker exec -it $(CONTAINER) bash

logs:
	@docker logs -f $(CONTAINER)
