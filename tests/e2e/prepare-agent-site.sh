#!/usr/bin/env bash
# Prepares a disposable WordPress site for the HTTP agent/security tests:
# plugin from the release ZIP, optional real Polylang and the official MCP Adapter, the labelled
# fixture corpus, a completed analysis, and one user + Application Password per role.
#
#   tests/e2e/prepare-agent-site.sh <wp-version> <port>
#
# Env: SITES_DIR, POLYLANG_DIR (built Polylang), MCP_ADAPTER_DIR (mcp-adapter with vendor/).
# Credentials are written to <site>/.agents.json (mode 600, outside the repository) and never printed.
set -euo pipefail

V="$1"
PORT="$2"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
ZIP="${ZIP:-$(ls -1 "$ROOT"/dist/semantic-seo-engine-vi-en-*.zip | head -1)}"
DIR="${SITES_DIR:-/tmp/sseo-sites}/agent-$V"
WP="wp --allow-root --path=$DIR"

"$HERE/setup-site.sh" "$V" "$PORT" "$DIR" >/dev/null
if [ -n "${POLYLANG_DIR:-}" ] && [ -d "$POLYLANG_DIR" ]; then
	cp -R "$POLYLANG_DIR" "$DIR/wp-content/plugins/polylang"
	$WP plugin activate polylang >/dev/null
fi
$WP eval-file "$HERE/load-corpus.php" > "$DIR/.corpus-map.json"
$WP rewrite flush >/dev/null
$WP plugin install "$ZIP" --activate >/dev/null
if [ -n "${MCP_ADAPTER_DIR:-}" ] && [ -d "$MCP_ADAPTER_DIR" ]; then
	cp -R "$MCP_ADAPTER_DIR" "$DIR/wp-content/plugins/mcp-adapter"
	$WP plugin activate mcp-adapter >/dev/null
fi
$WP sseo start inventory --then-analyze --run --user=admin >/dev/null
$WP eval '\SemanticSEO\Capabilities::ensure_agent_roles();'

php -r '
$roles = array( "reader" => "sseo_agent_reader", "analyst" => "sseo_agent_analyst", "operator" => "sseo_agent_operator", "worker" => "sseo_worker", "editor" => "editor" );
$out = array( "url" => $argv[1] );
foreach ( $roles as $login => $role ) {
	$pass = bin2hex( random_bytes( 12 ) );
	shell_exec( sprintf( "%s user create %s %s@example.test --role=%s --user_pass=%s --porcelain 2>/dev/null", $argv[2], escapeshellarg( "agent_" . $login ), "agent_" . $login, escapeshellarg( $role ), escapeshellarg( $pass ) ) );
	$app = trim( (string) shell_exec( sprintf( "%s user application-password create %s e2e --porcelain 2>/dev/null", $argv[2], escapeshellarg( "agent_" . $login ) ) ) );
	$out[ $login ] = array( "user" => "agent_" . $login, "app" => $app );
}
$admin = trim( (string) shell_exec( sprintf( "%s user application-password create admin e2e-admin --porcelain 2>/dev/null", $argv[2] ) ) );
$out["admin"] = array( "user" => "admin", "app" => $admin, "password" => trim( file_get_contents( $argv[3] ) ) );
file_put_contents( $argv[4], json_encode( $out ) );
chmod( $argv[4], 0600 );
' "http://127.0.0.1:$PORT" "$WP" "$DIR/.admin-pass" "$DIR/.agents.json"
echo "$DIR"
