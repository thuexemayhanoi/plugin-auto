#!/usr/bin/env bash
# Fails if a tracked file contains something that looks like a credential. Used by CI and locally.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

PATTERNS='(ghp_[A-Za-z0-9]{36}|github_pat_[A-Za-z0-9_]{40,}|AKIA[0-9A-Z]{16}|-----BEGIN (RSA |EC |OPENSSH )?PRIVATE KEY|hf_[A-Za-z0-9]{30,}|xox[baprs]-[A-Za-z0-9-]{10,}|sk-[A-Za-z0-9]{32,}|AIza[0-9A-Za-z_-]{35})'
# WordPress Application Passwords look like "abcd efgh ijkl mnop qrst uvwx".
APP_PASSWORD='(["'"'"'])[a-zA-Z0-9]{4}( [a-zA-Z0-9]{4}){5}\1'

hits="$(git ls-files -z | grep -zv -E '\.(png|jpe?g|gif|wasm|onnx|mo)$' | xargs -0 grep -nIE "$PATTERNS|$APP_PASSWORD" 2>/dev/null || true)"
if [ -n "$hits" ]; then
	echo "Possible secrets found:" >&2
	echo "$hits" | sed -E 's/(.{0,120}).*/\1/' >&2
	exit 1
fi
echo "No secret-like strings in $(git ls-files | wc -l) tracked files."
