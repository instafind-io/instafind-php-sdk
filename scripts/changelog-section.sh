#!/usr/bin/env bash
#
# Prints one version's section of a changelog, without its heading. Fails if
# the section is missing or empty. release.yml uses it (through check-release-tag.sh) to refuse a tag with no
# entry and for the GitHub Release notes; release.sh to refuse lines under
# Unreleased, which belong in changelog.d.
#
# Usage: scripts/changelog-section.sh <v1.2.3 | 1.2.3 | Unreleased> [file]

set -euo pipefail

if [[ $# -lt 1 || $# -gt 2 ]]; then
    echo "Usage: $0 <v1.2.3 | 1.2.3 | Unreleased> [file]" >&2
    exit 2
fi

version="${1#v}"
file="${2:-CHANGELOG.md}"

section=$(awk -v heading="## [$version]" '
    $0 == heading || index($0, heading " - ") == 1 { found = 1; next }
    found && (/^## \[/ || /^\[[^]]+\]: /) { exit }
    found { print }
' "$file")

if [[ -z "${section//[[:space:]]/}" ]]; then
    echo "$file has no entries for $version" >&2
    exit 1
fi

# Command substitution already dropped trailing blank lines; drop leading ones.
printf '%s\n' "$section" | sed '/./,$!d'
