#!/usr/bin/env bash
#
# Cuts a release: works out the next version from the newest tag on origin,
# merges the fragments in changelog.d into a new section of the changelog,
# sets Sdk::VERSION, and commits and tags it on top of origin/main. Works in a
# temporary clone of origin, so it runs from any checkout and leaves yours alone
# (no fetch into it, no worktree, no local tag).
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

# git commands in the clone must act on the clone: an exported GIT_DIR,
# GIT_OBJECT_DIRECTORY and the like would point them at the caller's repository.
for var in GIT_DIR GIT_WORK_TREE GIT_COMMON_DIR GIT_INDEX_FILE GIT_OBJECT_DIRECTORY GIT_ALTERNATE_OBJECT_DIRECTORIES GIT_NAMESPACE; do
    if [[ -n "${!var:-}" ]]; then
        echo "$var is set in the environment; unset it and run this again." >&2
        exit 1
    fi
done

# The release goes to this repository on GitHub and nowhere else: one fetch
# URL, and push URLs that all name it. get-url applies insteadOf rewrites, so a
# rewritten URL is checked as it will be used. RELEASE_ALLOW_ANY_ORIGIN=1 is for
# tests/scripts/release-test.sh, which releases into a throwaway origin.
github='^(https://github\.com/|git@github\.com:|ssh://git@github\.com/)instafind-io/instafind-php-sdk(\.git)?/?$'
check_url() {
    if [[ "${RELEASE_ALLOW_ANY_ORIGIN:-}" != "1" && ! "$1" =~ $github ]]; then
        echo "origin's $2 URL is $1, not instafind-io/instafind-php-sdk on GitHub. Release from a plain clone of it." >&2
        exit 1
    fi
}
fetch_urls=()
while IFS= read -r url; do fetch_urls+=("$url"); done < <(git remote get-url --all origin)
if [[ ${#fetch_urls[@]} -ne 1 ]]; then
    echo "origin has ${#fetch_urls[@]} URLs; release from a checkout whose origin has one." >&2
    exit 1
fi
origin_url=${fetch_urls[0]}
check_url "$origin_url" fetch
push_urls=()
while IFS= read -r url; do push_urls+=("$url"); done < <(git remote get-url --push --all origin)
for push_url in "${push_urls[@]}"; do
    check_url "$push_url" push
done

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

# A clone of origin's main, thrown away on exit: a tag made in it and not
# pushed goes with it.
tree=$(mktemp -d)
cleanup() {
    rm -rf "$tree"
}
trap cleanup EXIT
# --origin: a global clone.defaultRemoteName must not rename the remote.
git clone --quiet --no-tags --origin origin --branch main --single-branch "$origin_url" "$tree"
# The release goes where this checkout pushes, as from a worktree: every push
# URL (checked above), which is the fetch URL when there is no pushurl.
for push_url in "${push_urls[@]}"; do
    if [[ "$push_url" != "$origin_url" || ${#push_urls[@]} -gt 1 ]]; then
        git -C "$tree" config --add remote.origin.pushurl "$push_url"
    fi
done
# Global insteadOf rules apply again in the clone, to URLs already rewritten
# above: check the URLs the clone itself will use.
while IFS= read -r url; do check_url "$url" fetch; done < <(git -C "$tree" remote get-url --all origin)
while IFS= read -r url; do check_url "$url" push; done < <(git -C "$tree" remote get-url --push --all origin)
if [[ -n "$latest" ]]; then
    git -C "$tree" fetch --quiet --no-tags origin "refs/tags/$latest:refs/tags/$latest"
fi

# The release commit and tag are made in the clone. Config the checkout gets
# from `includeIf "gitdir:…"` or from its own .git/config does not reach a
# clone in a temporary directory, so refuse when it would commit or tag as
# someone else, sign differently, or reach GitHub with another SSH command.
ident() { git "$@" 2>/dev/null | sed -E 's/ [0-9]+ [-+][0-9]{4}$//' || true; }
for key in GIT_AUTHOR_IDENT GIT_COMMITTER_IDENT; do
    here=$(ident var "$key")
    there=$(ident -C "$tree" var "$key")
    if [[ "$here" != "$there" ]]; then
        echo "The temporary clone's $key would be '$there', this checkout's '$here'." >&2
        echo "Set user.name and user.email in your global git config (not per repository or with includeIf), then run this again." >&2
        exit 1
    fi
done
for key in commit.gpgSign tag.gpgSign user.signingKey gpg.format gpg.program core.sshCommand; do
    here=$(git config --get "$key" || true)
    there=$(git -C "$tree" config --get "$key" || true)
    if [[ "$here" != "$there" ]]; then
        echo "This checkout's $key is '$here', the temporary clone's would be '$there'." >&2
        echo "Set it in your global git config (not per repository or with includeIf), or unset it here, then run this again." >&2
        exit 1
    fi
done

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
# release commit goes on top of.
sha=$(git -C "$tree" rev-parse HEAD)
gh_err=$(mktemp)
if ! green=$(gh run list --repo instafind-io/instafind-php-sdk --workflow=integration.yml --commit "$sha" --status success --limit 1 --json databaseId --jq length 2>"$gh_err"); then
    echo "Could not ask GitHub for live integration runs: $(cat "$gh_err")" >&2
    rm -f "$gh_err"
    exit 1
fi
rm -f "$gh_err"
if [[ "$green" != "1" ]]; then
    echo "No green live integration run on $sha. Run it, wait for it, then release:" >&2
    echo "  gh workflow run integration.yml --repo instafind-io/instafind-php-sdk --ref main" >&2
    exit 1
fi

git -C "$tree" rm --quiet -- 'changelog.d/*.md' ':!changelog.d/README.md'
git -C "$tree" commit --quiet -am "Release $tag"
git -C "$tree" tag -a "$tag" -m "$tag"
if ! git -C "$tree" push --quiet --atomic origin HEAD:refs/heads/main "refs/tags/$tag"; then
    if [[ ${#push_urls[@]} -gt 1 ]]; then
        echo "A push was refused. Each push URL takes main and $tag together or not at all, but another may have taken them: check with git ls-remote --tags on each before running this again." >&2
    else
        echo "Push refused, nothing released. If main moved, run this again." >&2
    fi
    exit 1
fi

echo "Pushed $tag. The GitHub Release follows: gh run list --workflow=release.yml"
