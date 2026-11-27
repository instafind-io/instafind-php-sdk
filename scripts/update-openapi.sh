#!/usr/bin/env bash
#
# Refreshes openapi/instafind-v1.json, the SDK's copy of the backend's OpenAPI
# document, from a ref of the backend repository (default: main). Run the
# contract test afterwards: vendor/bin/phpunit --testsuite contract
#
# Usage: scripts/update-openapi.sh [ref]
# Needs gh with read access to the backend repository.

set -euo pipefail

ref="${1:-main}"
repo="${INSTAFIND_BACKEND_REPO:-msti/instafind-backend}"
target="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/openapi/instafind-v1.json"

tmp=$(mktemp)
trap 'rm -f "$tmp"' EXIT
gh api -H 'Accept: application/vnd.github.raw' "repos/$repo/contents/docs/openapi/instafind-v1.json?ref=$ref" > "$tmp"
# Refuse anything that is not an OpenAPI 3.1 document.
grep -q '"openapi": *"3\.1' "$tmp" || { echo "Not an OpenAPI 3.1 document: $repo@$ref" >&2; exit 1; }
mv "$tmp" "$target"
trap - EXIT
echo "openapi/instafind-v1.json is now $repo@$ref ($(gh api "repos/$repo/commits/$ref" --jq .sha | cut -c1-8))."
