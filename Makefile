.PHONY: test stan check build assets

test:
	./vendor/bin/phpunit

stan:
	./vendor/bin/phpstan analyse

check: stan test

assets:
	yarn build

build: assets
