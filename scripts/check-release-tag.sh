#!/usr/bin/env bash
#
# Refuses a release tag that the checkout does not match: CHANGELOG.md must
# have a section for it, and Sdk::VERSION must be its version (the User-Agent
# reports that constant). release.yml runs it on every pushed tag, release.sh
# before it tags.
#
# Usage: scripts/check-release-tag.sh <vX.Y.Z> [checkout]

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if [[ $# -lt 1 || $# -gt 2 ]]; then
    echo "Usage: $0 <vX.Y.Z> [checkout]" >&2
    exit 2
fi

tag="$1"
root="${2:-.}"

if [[ ! "$tag" =~ ^v(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$ ]]; then
    echo "$tag is not a vMAJOR.MINOR.PATCH tag." >&2
    exit 1
fi
version="${tag#v}"

constant=$(sed -nE "s/^[[:space:]]*public const VERSION = '([^']*)';.*/\1/p" "$root/src/Sdk.php")
if [[ "$constant" != "$version" ]]; then
    echo "Sdk::VERSION is '${constant:-missing}', the tag is $tag. Cut releases with scripts/release.sh." >&2
    exit 1
fi

if ! "$SCRIPT_DIR/changelog-section.sh" "$version" "$root/CHANGELOG.md" > /dev/null; then
    echo "CHANGELOG.md has no section for $version. Cut releases with scripts/release.sh." >&2
    exit 1
fi

echo "$tag matches Sdk::VERSION and has a changelog section."
