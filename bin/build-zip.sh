#!/usr/bin/env bash
# Builds the installable plugin ZIP: dist/semantic-seo-engine-vi-en-<version>.zip with exactly one
# root folder (semantic-seo-engine-vi-en/). Development files (tests, tooling, node_modules,
# Composer vendor, docs sources) are never included. Model weights are never included.
#
# Usage: bin/build-zip.sh            (expects `npm ci` to have been run for the browser runtime)
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SLUG="semantic-seo-engine-vi-en"
VERSION="$(sed -n 's/^ \* Version:[[:space:]]*//p' "$ROOT/$SLUG.php" | head -1 | tr -d '\r')"
STABLE="$(sed -n 's/^Stable tag:[[:space:]]*//p' "$ROOT/readme.txt" | head -1 | tr -d '\r')"
BUILD="$ROOT/build"
DIST="$ROOT/dist"
OUT="$DIST/$SLUG-$VERSION.zip"

if [ -z "$VERSION" ]; then
	echo "Cannot read Version header" >&2
	exit 1
fi
if [ "$VERSION" != "$STABLE" ]; then
	echo "Version mismatch: plugin header $VERSION vs readme.txt Stable tag $STABLE" >&2
	exit 1
fi

if [ ! -f "$ROOT/assets/vendor/transformers/transformers.min.js" ]; then
	echo "Browser runtime missing; building it (requires npm ci)…"
	node "$ROOT/bin/build-vendor.mjs" >/dev/null
fi

rm -rf "$BUILD" && mkdir -p "$BUILD/$SLUG" "$DIST"
INCLUDE=( "$SLUG.php" uninstall.php readme.txt LICENSE includes admin assets blocks templates languages )
for item in "${INCLUDE[@]}"; do
	if [ -e "$ROOT/$item" ]; then
		cp -R "$ROOT/$item" "$BUILD/$SLUG/"
	fi
done

# Safety net: nothing development-only or secret-looking may ship.
find "$BUILD/$SLUG" \( -name '.DS_Store' -o -name '*.map' -o -name '*.log' -o -name '.git*' -o -name '*.po~' \) -delete
for forbidden in tests node_modules vendor bin tools docs .github composer.json package.json phpunit.xml.dist; do
	if [ -e "$BUILD/$SLUG/$forbidden" ]; then
		echo "Forbidden path in build: $forbidden" >&2
		exit 1
	fi
done
if grep -rIl --exclude='*.wasm' -E '(ghp_[A-Za-z0-9]{20,}|AKIA[0-9A-Z]{16}|-----BEGIN (RSA |EC )?PRIVATE KEY|hf_[A-Za-z0-9]{30,})' "$BUILD/$SLUG" >/dev/null; then
	echo "Secret-like string found in build" >&2
	exit 1
fi
if find "$BUILD/$SLUG" -name '*.onnx' -o -name '*.onnx_data' -o -name '*.safetensors' | grep -q .; then
	echo "Model weights must not be bundled" >&2
	exit 1
fi

# PHP syntax check of everything that ships.
find "$BUILD/$SLUG" -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null

rm -f "$OUT"
( cd "$BUILD" && zip -qr -X "$OUT" "$SLUG" )
( cd "$DIST" && sha256sum "$(basename "$OUT")" > "$(basename "$OUT").sha256" )

ROOTS="$(unzip -Z1 "$OUT" | cut -d/ -f1 | sort -u)"
if [ "$ROOTS" != "$SLUG" ]; then
	echo "ZIP must contain exactly one root folder, found: $ROOTS" >&2
	exit 1
fi
echo "Built $OUT"
echo "  files: $(unzip -Z1 "$OUT" | grep -vc '/$')"
echo "  size:  $(stat -c %s "$OUT") bytes"
echo "  sha256: $(cut -d' ' -f1 "$OUT.sha256")"
