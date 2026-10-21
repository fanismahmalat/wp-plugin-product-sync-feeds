#!/usr/bin/env bash
# Build the installable plugin zip from product-sync-feeds/.
#
#   bin/build-zip.sh [output-dir]       (default: dist/)
#
# Produces product-sync-feeds.zip: the product-sync-feeds/ folder plus LICENSE.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SLUG="product-sync-feeds"
PLUGIN="$ROOT/$SLUG"
OUT="${1:-$ROOT/dist}"
mkdir -p "$OUT"
OUT="$(cd "$OUT" && pwd)"
MAIN="$PLUGIN/$SLUG.php"

VERSION="$(sed -n "s/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*//p" "$MAIN" | head -1 | tr -d '\r ')"
CONSTANT="$(sed -n "s/.*define( 'PSF_VERSION', '\([^']*\)' ).*/\1/p" "$MAIN")"

if [[ -z "$VERSION" ]]; then
	echo "Could not read the Version header." >&2
	exit 1
fi
if [[ "$VERSION" != "$CONSTANT" ]]; then
	echo "Version header ($VERSION) and PSF_VERSION ($CONSTANT) differ." >&2
	exit 1
fi

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
rsync -a --exclude '.DS_Store' "$PLUGIN/" "$STAGE/$SLUG/"
cp "$ROOT/LICENSE" "$STAGE/$SLUG/LICENSE"

rm -f "$OUT/$SLUG.zip"
(cd "$STAGE" && zip -qr -X "$OUT/$SLUG.zip" "$SLUG")

echo "Built $OUT/$SLUG.zip ($VERSION)"
