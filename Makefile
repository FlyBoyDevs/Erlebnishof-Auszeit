IMAGE ?= erlebnishof-auszeit-preview
PORT ?= 8080

.PHONY: help build run test verify images release

help:
	@echo "make build    Build the local Docker preview image"
	@echo "make run      Run it at http://localhost:$(PORT)"
	@echo "make test     Run JavaScript and site checks"
	@echo "make verify   Verify local references and contracts"
	@echo "make images   Validate optimized image variants"
	@echo "make release  Build the IONOS release artifact"

build:
	docker build -f docker/Dockerfile -t $(IMAGE) .

run: build
	docker run --rm --name hofladen-preview -p $(PORT):80 $(IMAGE)

test:
	npm test

verify:
	npm run verify

images:
	npm run images:check

release:
	npm run release
