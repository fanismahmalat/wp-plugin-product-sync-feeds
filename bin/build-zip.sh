#!/usr/bin/env bash
# Build the installable plugin zip and its release.json from product-sync-feeds/.
#
#   bin/build-zip.sh [output-dir]       (default: dist/)
#
# Produces:
#   product-sync-feeds.zip   the product-sync-feeds/ folder plus LICENSE
#   release.json             version, requirements and changelog for the license server
#   CHANGELOG.md             this version's changelog (release notes)
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SLUG="product-sync-feeds"
PLUGIN="$ROOT/$SLUG"
OUT="${1:-$ROOT/dist}"
mkdir -p "$OUT"
OUT="$(cd "$OUT" && pwd)"
MAIN="$PLUGIN/$SLUG.php"

header() { sed -n "s/^[[:space:]]*\*[[:space:]]*$1:[[:space:]]*//p" "$MAIN" | head -1 | tr -d '\r' | sed 's/[[:space:]]*$//'; }

VERSION="$(header 'Version')"
REQUIRES="$(header 'Requires at least')"
REQUIRES_PHP="$(header 'Requires PHP')"
TESTED="$(sed -n 's/^Tested up to:[[:space:]]*//p' "$PLUGIN/readme.txt" | head -1 | tr -d '\r ')"
CONSTANT="$(sed -n "s/.*define( 'PSF_VERSION', '\([^']*\)' ).*/\1/p" "$MAIN")"

if [[ -z "$VERSION" ]]; then
	echo "Could not read the Version header." >&2
	exit 1
fi
if [[ "$VERSION" != "$CONSTANT" ]]; then
	echo "Version header ($VERSION) and PSF_VERSION ($CONSTANT) differ." >&2
	exit 1
fi
if grep -q '^ \* Update URI:.*example\.com' "$MAIN" && [[ "${ALLOW_EXAMPLE_UPDATE_URI:-}" != "1" ]]; then
	echo "Update URI still points to example.com: set it to your license server first" >&2
	echo "(or ALLOW_EXAMPLE_UPDATE_URI=1 for a test build)." >&2
	exit 1
fi

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
rsync -a --exclude '.DS_Store' "$PLUGIN/" "$STAGE/$SLUG/"
cp "$ROOT/LICENSE" "$STAGE/$SLUG/LICENSE"

rm -f "$OUT/$SLUG.zip"
(cd "$STAGE" && zip -qr -X "$OUT/$SLUG.zip" "$SLUG")

# Changelog of this version from readme.txt: lines after "= x.y.z =" until the next "= ... =".
awk -v v="$VERSION" '
	$0 ~ "^= *" v " *=" { on = 1; next }
	on && /^= .* =/ { exit }
	on { print }
' "$PLUGIN/readme.txt" | sed '/./,$!d' > "$OUT/CHANGELOG.md"

python3 - "$OUT" "$VERSION" "$REQUIRES" "$REQUIRES_PHP" "$TESTED" "$SLUG.zip" <<'PY'
import json, sys, datetime, pathlib
out, version, requires, requires_php, tested, zipname = sys.argv[1:]
changelog = pathlib.Path(out, "CHANGELOG.md").read_text().strip()
data = {
    "version": version,
    "requires": requires,
    "requires_php": requires_php,
    "tested": tested,
    "released_at": datetime.datetime.now(datetime.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
    "changelog": changelog,
    "zip": zipname,
}
pathlib.Path(out, "release.json").write_text(json.dumps(data, indent=2) + "\n")
PY

echo "Built $OUT/$SLUG.zip ($VERSION)"
