#!/bin/sh
set -eu
umask 077

: "${LARAVEL_STORAGE_PATH:=/var/lib/blessing}"
export LARAVEL_STORAGE_PATH
for directory in app framework/cache/data framework/sessions framework/views logs textures; do
    mkdir -p "$LARAVEL_STORAGE_PATH/$directory"
done

# No install/migrate/key generation here; CLI commands are explicit operator actions.
if [ "${1:-}" = apache2-foreground ]; then
    . /etc/apache2/blessing-envvars
    mkdir -p "$APACHE_RUN_DIR"
fi
exec docker-php-entrypoint "$@"
