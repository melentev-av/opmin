# Local development shortcuts. Everything runs on the host PHP; CI uses the same composer scripts.

BOX_VERSION ?= 4.7.0
BOX ?= .build/bin/box.phar

.PHONY: help install test test-unit test-integration test-rector psalm cs cs-fix schema phar playground

help: ## Show this menu
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  make %-18s %s\n", $$1, $$2}'

install: ## Install composer dependencies
	composer install

test: ## Run all Testo suites
	composer test

test-unit: ## Run the unit suite
	composer test:unit

test-integration: ## Run the integration suite
	composer test:integration

test-rector: ## Run the Rector rules suite
	composer test:rector

psalm: ## Static analysis of the tool's code
	composer psalm

cs: ## Show coding standard violations
	composer cs:diff

cs-fix: ## Fix coding standard violations
	composer cs:fix

schema: ## Regenerate resources/opmin.schema.json
	composer schema:dump

$(BOX):
	mkdir -p $(dir $(BOX))
	curl -fsSL -o $(BOX) https://github.com/box-project/box/releases/download/$(BOX_VERSION)/box.phar

phar: $(BOX) ## Build the scoped PHAR into .build/phar/opmin.phar
	composer install --no-dev --quiet
	php -d phar.readonly=0 -d memory_limit=-1 $(BOX) compile --no-parallel
	composer install --quiet
	.build/phar/opmin.phar --version

playground: ## Create all playground scenarios
	bin/playground init
