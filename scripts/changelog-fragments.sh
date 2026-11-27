#!/usr/bin/env bash
#
# Prints the changelog section that the fragments in changelog.d add up to:
# notes first, then each heading once, in a fixed order. Prints nothing if there
# are no fragments. Fails on anything that would be lost or would break the
# changelog: a file release.sh would not merge, an empty fragment or heading, a
# heading the changelog does not use, a list item with no heading, a link reference.
# release.sh uses it to build a release's section; see changelog.d/README.md.
#
# Usage: scripts/changelog-fragments.sh [directory]

set -euo pipefail

dir="${1:-changelog.d}"

if [[ ! -d "$dir" ]]; then
    echo "$dir is not a directory" >&2
    exit 1
fi

fragments=()
while IFS= read -r -d '' path; do
    name=${path##*/}
    if [[ ! -f "$path" || "$name" != *.md || "$name" == .* ]]; then
        echo "$path is not a fragment: use a file named <name>.md directly in $dir" >&2
        exit 1
    fi
    if ! grep -q '[^[:space:]]' "$path"; then
        echo "$path is empty" >&2
        exit 1
    fi
    fragments+=("$path")
done < <(find "$dir" -mindepth 1 -maxdepth 1 ! -name README.md -print0 | sort -z)

if [[ ${#fragments[@]} -eq 0 ]]; then
    exit 0
fi

awk '
    function fail(message) {
        print file ": " message > "/dev/stderr"
        failed = 1
        exit 1
    }
    function open_block(name) {
        if (section != "" && !started) fail("no entries under ### " section)
        section = name
        started = 0
    }
    BEGIN {
        count = split("Added Changed Deprecated Removed Fixed Security", order, " ")
        for (i = 1; i <= count; i++) known[order[i]] = 1
    }
    FNR == 1 {
        open_block("")
        file = FILENAME
    }
    # changelog-section.sh ends a section at one, and the links must stay in order.
    /^\[[^]]+\]: / { fail("link references are not allowed, use an inline link: " $0) }
    /^[ \t]*$/ { blank = 1; next }
    /^#+ / {
        name = $0
        sub(/^### /, "", name)
        if (!(name in known)) fail("heading must be one of ### Added, Changed, Deprecated, Removed, Fixed, Security: " $0)
        open_block(name)
        next
    }
    {
        # Text above the first heading (section "") is a note on the whole
        # release, such as a note on upgrading. A blank line separates
        # the notes of two fragments.
        if (section == "" && /^[-*] /) fail("list item above the first heading: " $0)
        if (section == "" && !started && body[""] != "") body[""] = body[""] "\n"
        body[section] = body[section] (blank && started ? "\n" : "") $0 "\n"
        blank = 0
        started = 1
    }
    END {
        if (failed) exit 1
        open_block("")
        out = body[""]
        for (i = 1; i <= count; i++) {
            if (order[i] in body) out = out (out == "" ? "" : "\n") "### " order[i] "\n\n" body[order[i]]
        }
        printf "%s", out
    }
' "${fragments[@]}"
