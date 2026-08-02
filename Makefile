IMAGE_NAME ?= hofladen-php
CONTAINER_NAME ?= hofladen-php-test
HOST_PORT ?= 8080
DOCKERFILE ?= docker/php/Dockerfile

.PHONY: build run stop shell logs

build:
	docker build -t $(IMAGE_NAME) -f $(DOCKERFILE) .

run:
	docker run --rm \
		-d \
		--name $(CONTAINER_NAME) \
		-p $(HOST_PORT):80 \
		-v "$(CURDIR):/var/www/html" \
		$(IMAGE_NAME)

stop:
	docker stop $(CONTAINER_NAME) 2>/dev/null || true

shell:
	docker run --rm -it \
		-v "$(CURDIR):/var/www/html" \
		$(IMAGE_NAME) bash

logs:
	docker logs -f $(CONTAINER_NAME)