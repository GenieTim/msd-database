.PHONY: test stan check build assets cs cs-check rector rector-check format format-check lint

test:
	./vendor/bin/phpunit

stan:
	./vendor/bin/phpstan analyse

cs:
	./vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php

cs-check:
	./vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php --dry-run --diff

rector:
	./vendor/bin/rector process

rector-check:
	./vendor/bin/rector process --dry-run

format:
	npx prettier --write .

format-check:
	npx prettier --check .

lint:
	php bin/console lint:container
	php bin/console lint:yaml config --parse-tags
	php bin/console lint:twig templates

check: lint cs-check rector-check stan test

assets:
	yarn build

build: assets
