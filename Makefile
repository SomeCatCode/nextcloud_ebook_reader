APP_ID := ebookreader
COMPOSE := docker compose -f docker/docker-compose.yml
ROOT := $(CURDIR)
PHP_IMAGE := $(APP_ID)-php-test

.PHONY: build dev-up dev-down dev-reset enable test-php test-js lint openapi appstore composer-install php-image

build:
	npm ci
	npm run build

php-image:
	docker build -t $(PHP_IMAGE) -f docker/php-test.Dockerfile docker

composer-install:
	docker run --rm -v "$(ROOT):/app" -w /app composer:2 install --no-interaction --prefer-dist --ignore-platform-reqs

dev-up:
	$(COMPOSE) up -d
	@echo "Nextcloud: http://localhost:8080 (admin/admin). Run 'make enable' once it is installed."

dev-down:
	$(COMPOSE) down

dev-reset:
	$(COMPOSE) down -v
	$(COMPOSE) up -d

enable:
	$(COMPOSE) exec -u www-data nextcloud php occ app:enable $(APP_ID)
	$(COMPOSE) exec -u www-data nextcloud php occ config:system:set debug --value=true --type=boolean

test-php: php-image
	docker run --rm -v "$(ROOT):/app" -w /app $(PHP_IMAGE) sh -c "composer install --no-interaction --prefer-dist && vendor/bin/phpunit -c phpunit.xml"

test-js:
	npm test

lint: php-image
	npm run lint
	npm run typecheck
	docker run --rm -v "$(ROOT):/app" -w /app $(PHP_IMAGE) sh -c "composer install --no-interaction --prefer-dist && composer run lint && vendor/bin/psalm --threads=1 --no-cache"

openapi: php-image
	docker run --rm -v "$(ROOT):/app" -w /app $(PHP_IMAGE) sh -c "composer install --no-interaction --prefer-dist && composer run openapi"

# Tarball with the built js/ but without sources, tests, docker and dev tooling.
# Set the app version in appinfo/info.xml and package.json: make bump VERSION=0.2.0
bump:
	@test -n "$(VERSION)" || (echo "usage: make bump VERSION=x.y.z" && exit 1)
	sed -i 's:<version>.*</version>:<version>$(VERSION)</version>:' appinfo/info.xml
	npm version $(VERSION) --no-git-tag-version --allow-same-version

appstore: build
	rm -rf build/artifacts/$(APP_ID)
	mkdir -p build/artifacts/$(APP_ID)
	tar -c --exclude-vcs \
		--exclude='./build' --exclude='./node_modules' --exclude='./tests' --exclude='./src' \
		--exclude='./docker' --exclude='./.github' --exclude='./docs' --exclude='./PLAN.md' \
		--exclude='./vendor' --exclude='./.phpunit.cache' \
		--exclude='./packages' \
		--exclude='./package.json' --exclude='./package-lock.json' --exclude='./vite.config.ts' --exclude='./tsconfig.json' \
		--exclude='./composer.json' --exclude='./composer.lock' --exclude='./psalm.xml' --exclude='./phpunit.xml' \
		--exclude='./.php-cs-fixer.dist.php' --exclude='./Makefile' \
		--exclude='./.tools' --exclude='./scripts' --exclude='./eslint.config.js' \
		--exclude='./psalm-baseline.xml' --exclude='./.gitignore' --exclude='./.gitattributes' \
		--exclude='./*.map' --exclude='./js/*.map' . | tar -x -C build/artifacts/$(APP_ID)
	tar -czf build/artifacts/$(APP_ID).tar.gz -C build/artifacts $(APP_ID)
	@echo "Created build/artifacts/$(APP_ID).tar.gz"
