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

		# Build Tailwind stylesheet on first boot so templates render
		# correctly without a manual `make tailwind` step. Safe to
		# re-run; symfonycasts/tailwind-bundle caches intermediate
		# state in var/tailwind.
		if [ -f bin/console ]; then
			php bin/console tailwind:build || true
		fi
	fi
fi

exec "$@"
