#!/usr/bin/env bash
# Fetches a WordPress release and the matching WordPress PHPUnit test library for the
# integration suite, then prints the environment variables to export.
#
#   bin/install-wp-tests.sh <wp-version> [target-dir]
#
# Database settings come from WP_TESTS_DB_NAME/USER/PASSWORD/HOST (defaults: wp_tests/wp/wp/127.0.0.1),
# see tests/php/wp-tests-config.php. The test database is emptied by every run: never point it at
# a real site's database.
set -euo pipefail

V="${1:?WordPress version, e.g. 7.1.3}"
DIR="${2:-${TMPDIR:-/tmp}/sseo-wp-$V}"
mkdir -p "$DIR"

if [ ! -f "$DIR/wordpress/wp-settings.php" ]; then
	git -c advice.detachedHead=false clone --quiet --depth 1 --branch "$V" https://github.com/WordPress/WordPress.git "$DIR/wordpress"
fi
if [ ! -f "$DIR/wordpress-develop/tests/phpunit/includes/functions.php" ]; then
	git -c advice.detachedHead=false clone --quiet --depth 1 --branch "$V" --filter=blob:none --sparse https://github.com/WordPress/wordpress-develop.git "$DIR/wordpress-develop"
	git -C "$DIR/wordpress-develop" sparse-checkout set tests/phpunit/includes tests/phpunit/data
fi

echo "export WP_CORE_DIR=$DIR/wordpress"
echo "export WP_TESTS_DIR=$DIR/wordpress-develop/tests/phpunit"
