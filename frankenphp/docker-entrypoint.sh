#!/bin/sh
set -e

# When running as the app user with mounted project sources (dev mode),
# Symfony needs cache/log dirs writable. Create them if missing.
if [ "$APP_ENV" != "prod" ] && [ "$1" = "frankenphp" ]; then
	if [ -f composer.json ]; then
		# Ensure vendor is installed in dev (helpful after a fresh checkout)
		if [ ! -d vendor ] || [ ! -f vendor/autoload.php ]; then
			composer install --prefer-dist --no-progress --no-interaction
		fi

		# Standard Symfony cache/log layout
		mkdir -p var/cache var/log var/share
	fi
fi

exec "$@"
