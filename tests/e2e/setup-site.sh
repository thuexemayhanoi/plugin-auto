#!/usr/bin/env bash
# Creates a disposable local WordPress site for end-to-end tests (never a production site).
#
#   tests/e2e/setup-site.sh <wp-version> <port> <dir>
#
# Requires: WP-CLI, a local MariaDB/MySQL user wp/wp, a WordPress copy in /opt/wp/wp-<version>
# (or WP_SOURCE=<dir>). Starts `php -S` in the background and prints the admin password file.
set -euo pipefail

V="$1"
PORT="$2"
DIR="$3"
SRC="${WP_SOURCE:-/opt/wp/wp-$V}"
DB="sseo_e2e_${V//./_}"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WP="wp --allow-root --path=$DIR"

[ -d "$SRC" ] || { echo "WordPress $V not found in $SRC" >&2; exit 1; }
pkill -f "php -S 127.0.0.1:$PORT" 2>/dev/null || true
rm -rf "$DIR"
cp -R "$SRC" "$DIR"
mysql -uwp -pwp -h127.0.0.1 -e "DROP DATABASE IF EXISTS $DB; CREATE DATABASE $DB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"

$WP config create --dbname="$DB" --dbuser=wp --dbpass=wp --dbhost=127.0.0.1 --skip-check --force --extra-php <<'PHP'
define( 'WP_ENVIRONMENT_TYPE', 'local' );
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
PHP
PASS="$(head -c 24 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 20)"
$WP core install --url="http://127.0.0.1:$PORT" --title="SSEO E2E $V" --admin_user=admin --admin_password="$PASS" --admin_email=admin@example.test --skip-email >/dev/null
$WP rewrite structure '/%postname%/' >/dev/null
$WP option update blogdescription 'Disposable test site' >/dev/null
echo "$PASS" > "$DIR/.admin-pass"
chmod 600 "$DIR/.admin-pass"

cp "$HERE/router.php" "$DIR/.router.php"
# exec + group redirection: no shell keeps the caller's stdout/stderr open (pipelines can finish).
( cd "$DIR" && PHP_CLI_SERVER_WORKERS=4 exec nohup php -S "127.0.0.1:$PORT" .router.php ) >"$DIR/.server.log" 2>&1 </dev/null &
for _ in $(seq 1 50); do
	curl -fsS -o /dev/null "http://127.0.0.1:$PORT/" && break
	sleep 0.2
done
echo "WordPress $V ready at http://127.0.0.1:$PORT (admin password in $DIR/.admin-pass)"
