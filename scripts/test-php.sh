#!/usr/bin/env sh
set -eu

if ! command -v php >/dev/null 2>&1; then
    echo "PHP CLI nicht installiert; PHP-Tests werden lokal übersprungen (auf IONOS/CI mit PHP erneut ausführen)."
    exit 0
fi

php -l content/current.php
php -l admin/img.php
php -l admin/index.php
php -l admin/maintenance.php
php -l admin/preflight.php
find admin/lib admin/tests -type f -name '*.php' -print0 | xargs -0 -n1 php -l
php admin/tests/run.php
