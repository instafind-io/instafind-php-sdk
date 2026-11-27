#!/usr/bin/env bash
#
# Cuts a release: works out the next version from the newest tag on origin,
# merges the fragments in changelog.d into a new section of the changelog,
# sets Sdk::VERSION, and commits and tags it on top of origin/main. Works in a
# temporary worktree, so it runs from any checkout and leaves yours alone.
#
# Usage: scripts/release.sh <patch|minor|major> [--push]
#
# The first release has no tag to count from: it must be `major` and is
# v1.0.0. Without --push it only shows what would be released. With --push,
# the live integration job (integration.yml) must have passed on origin/main,
# and main and the tag go up together or not at all; the tag is the release
# consumers install (release.yml then publishes the GitHub Release). Release
# only with the owner's go.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

bump=""
push=0
for arg in "$@"; do
    case "$arg" in
        patch|minor|major) bump="$arg" ;;
        --push) push=1 ;;
        *) bump=""; break ;;
    esac
done
if [[ -z "$bump" ]]; then
    echo "Usage: $0 <patch|minor|major> [--push]" >&2
    exit 2
fi

git fetch --quiet --tags origin main

# Origin's tags, not local ones: a stray local tag is not a release.
latest=$(git ls-remote --tags --refs origin 'v*' \
    | sed 's|.*refs/tags/||' \
    | grep -E '^v(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$' \
    | sort -V | tail -n 1 || true)
if [[ -z "$latest" ]]; then
    if [[ "$bump" != "major" ]]; then
        echo "No release yet: the first one is v1.0.0, cut it with: $0 major" >&2
        exit 1
    fi
    major=0 minor=0 patch=0
    next="1.0.0"
else
    IFS=. read -r major minor patch <<< "${latest#v}"
    case "$bump" in
        major) next="$((major + 1)).0.0" ;;
        minor) next="$major.$((minor + 1)).0" ;;
        patch) next="$major.$minor.$((patch + 1))" ;;
    esac
fi
tag="v$next"

tree=$(mktemp -d)
unpushed_tag=""
cleanup() {
    git worktree remove --force "$tree" 2>/dev/null || true
    rm -rf "$tree"
    if [[ -n "$unpushed_tag" ]]; then
        git tag -d "$unpushed_tag" >/dev/null
    fi
}
trap cleanup EXIT
git worktree add --quiet --detach "$tree" origin/main

changelog="$tree/CHANGELOG.md"
version_file="$tree/src/Sdk.php"

# A branch from before changelog.d, or a line added by hand.
if stray=$("$SCRIPT_DIR/changelog-section.sh" Unreleased "$changelog" 2>/dev/null); then
    printf '%s\n' "$stray" >&2
    echo "Those lines are under Unreleased in CHANGELOG.md. Move them to a file in changelog.d." >&2
    exit 1
fi

notes=$("$SCRIPT_DIR/changelog-fragments.sh" "$tree/changelog.d")
if [[ -z "$notes" ]]; then
    echo "Nothing to release: add a file to changelog.d first." >&2
    exit 1
fi

# SemVer: from 1.0.0 on, only a major release may break the public API.
if grep -qF '**Breaking:**' <<< "$notes" && [[ "$bump" == "patch" || ("$bump" == "minor" && "$major" -ge 1) ]]; then
    echo "changelog.d has a **Breaking:** entry, which a $bump release cannot carry." >&2
    exit 1
fi
if [[ "$bump" == "patch" ]] && grep -qxF '### Added' <<< "$notes"; then
    echo "changelog.d has Added entries, which a patch release cannot carry. Use minor." >&2
    exit 1
fi

link=$(grep -m 1 '^\[Unreleased\]: ' "$changelog" || true)
if [[ -z "$latest" ]]; then
    if [[ "$link" != *"/commits/main" ]]; then
        echo "CHANGELOG.md's [Unreleased] link should point at /commits/main before the first release." >&2
        exit 1
    fi
    url=${link#'[Unreleased]: '}
    url=${url%/commits/main}
    version_link="[$next]: $url/releases/tag/$tag"
else
    if [[ "$link" != *"/compare/$latest...HEAD" ]]; then
        echo "CHANGELOG.md's [Unreleased] link does not start at $latest, the newest tag on origin." >&2
        exit 1
    fi
    url=${link#'[Unreleased]: '}
    url=${url%/compare/*}
    version_link="[$next]: $url/compare/$latest...$tag"
fi

NOTES="$notes" awk -v next_version="$next" -v tag="$tag" -v url="$url" -v version_link="$version_link" -v today="$(date -u +%F)" '
    $0 == "## [Unreleased]" {
        print
        print ""
        print "## [" next_version "] - " today
        print ""
        print ENVIRON["NOTES"]
        next
    }
    index($0, "[Unreleased]: ") == 1 {
        print "[Unreleased]: " url "/compare/" tag "...HEAD"
        print version_link
        next
    }
    { print }
' "$changelog" > "$changelog.new"
mv "$changelog.new" "$changelog"

# The version the User-Agent reports; release.yml refuses a tag that differs.
sed -i.bak -E "s/(public const VERSION = ')[^']*(';)/\1$next\2/" "$version_file"
rm -f "$version_file.bak"

# release.yml refuses a tag without both, and by then the number is used.
"$SCRIPT_DIR/check-release-tag.sh" "$tag" "$tree" > /dev/null

echo "${latest:-(no release yet)} -> $tag"
echo
printf '%s\n' "$notes"
echo

if [[ "$push" != "1" ]]; then
    echo "Nothing pushed. Run again with --push to release $tag."
    exit 0
fi

# The live integration job must have passed on origin/main's tip, which the
# release commit goes on top of. The newest finished run counts, not any green
# one: the daily schedule runs the same commit again, and a later red run means
# production no longer works with it.
sha=$(git -C "$tree" rev-parse HEAD)
gh_err=$(mktemp)
if ! latest_run=$(gh run list --repo instafind-io/instafind-php-sdk --workflow=integration.yml --commit "$sha" --status completed --limit 1 --json conclusion --jq '.[0].conclusion // ""' 2>"$gh_err"); then
    echo "Could not ask GitHub for live integration runs: $(cat "$gh_err")" >&2
    rm -f "$gh_err"
    exit 1
fi
rm -f "$gh_err"
if [[ "$latest_run" != "success" ]]; then
    echo "The newest finished live integration run on $sha is ${latest_run:-missing}, not success. Run it, wait for it, then release:" >&2
    echo "  gh workflow run integration.yml --repo instafind-io/instafind-php-sdk --ref main" >&2
    exit 1
fi

git -C "$tree" rm --quiet -- 'changelog.d/*.md' ':!changelog.d/README.md'
git -C "$tree" commit --quiet -am "Release $tag"
git -C "$tree" tag -a "$tag" -m "$tag"
unpushed_tag="$tag"
if ! git -C "$tree" push --quiet --atomic origin HEAD:refs/heads/main "refs/tags/$tag"; then
    echo "Push refused, nothing released. If main moved, run this again." >&2
    exit 1
fi
unpushed_tag=""

echo "Pushed $tag. The GitHub Release follows: gh run list --workflow=release.yml"
