#!/usr/bin/env bash
#
# Dry runs of scripts/release.sh against a throwaway origin (a bare repository
# in a temporary directory): the next version and notes it prints, what it
# refuses, and that it leaves the checkout it runs from alone (no tag, no
# worktree, no change) and its temporary clone removed. --push is not run: it
# needs GitHub for the live-integration gate.
#
# Usage: tests/scripts/release-test.sh

set -euo pipefail

repo="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
base=$(mktemp -d)
trap 'rm -rf "$base"' EXIT

# release.sh's own mktemp lands here, so the test can see it cleaned up.
export TMPDIR="$base/tmp"
mkdir "$TMPDIR"
# The origin here is a bare repository, not GitHub; the guard is tested below.
export RELEASE_ALLOW_ANY_ORIGIN=1
export GIT_AUTHOR_NAME=test GIT_AUTHOR_EMAIL=test@example.invalid GIT_COMMITTER_NAME=test GIT_COMMITTER_EMAIL=test@example.invalid

failures=0
fail() {
    echo "FAIL: $*" >&2
    failures=$((failures + 1))
}
expect() { # expect <description> <expected exit> <grep pattern> -- command...
    local what=$1 code=$2 pattern=$3
    shift 4
    local out status=0
    out=$("$@" 2>&1) || status=$?
    if [[ "$status" != "$code" ]]; then
        fail "$what: exit $status, expected $code. Output:"$'\n'"$out"
    elif ! grep -qE -- "$pattern" <<< "$out"; then
        fail "$what: output lacks /$pattern/. Output:"$'\n'"$out"
    else
        echo "ok: $what"
    fi
}

git init --quiet --bare --initial-branch=main "$base/origin.git"
git clone --quiet "$base/origin.git" "$base/work" 2>/dev/null
cd "$base/work"
git checkout --quiet -b main
mkdir -p src
cp -r "$repo/scripts" .
cp "$repo/src/Sdk.php" src/
# A changelog from before the first release, not the repository's own, which
# stops being one once v1.0.0 is out.
url=https://github.com/instafind-io/instafind-php-sdk
mkdir changelog.d
cp "$repo/changelog.d/README.md" changelog.d/
cat > CHANGELOG.md <<CHANGELOG
# Changelog

## [Unreleased]

[Unreleased]: $url/commits/main
CHANGELOG
printf '### Added\n\n- Something new.\n' > changelog.d/test-added.md
git add -A && git commit --quiet -m init && git push --quiet origin main

expect "no tag yet: patch is refused" 1 'first one is v1.0.0' -- scripts/release.sh patch
expect "no tag yet: major gives v1.0.0" 0 '^\(no release yet\) -> v1\.0\.0$' -- scripts/release.sh major
expect "the notes are the fragments" 0 '^- Something new\.$' -- scripts/release.sh major
expect "nothing is pushed without --push" 0 'Nothing pushed\. Run again with --push to release v1\.0\.0\.' -- scripts/release.sh major

# As if v1.0.0 were released: its section, the links, the constant, no fragments.
cat > CHANGELOG.md <<CHANGELOG
# Changelog

## [Unreleased]

## [1.0.0] - 2026-11-28

### Added

- Everything.

[Unreleased]: $url/compare/v1.0.0...HEAD
[1.0.0]: $url/releases/tag/v1.0.0
CHANGELOG
sed -i.bak -E "s/(public const VERSION = ')[^']*(';)/\11.0.0\2/" src/Sdk.php && rm -f src/Sdk.php.bak
git rm --quiet -- 'changelog.d/*.md' ':!changelog.d/README.md'
git commit --quiet -am "Release v1.0.0" && git tag -a v1.0.0 -m v1.0.0
git push --quiet origin main refs/tags/v1.0.0
printf '### Fixed\n\n- A fix.\n' > changelog.d/a-fix.md
git add -A && git commit --quiet -m fix && git push --quiet origin main

expect "patch after v1.0.0" 0 '^v1\.0\.0 -> v1\.0\.1$' -- scripts/release.sh patch
expect "minor after v1.0.0" 0 '^v1\.0\.0 -> v1\.1\.0$' -- scripts/release.sh minor
expect "the fix is in the notes" 0 '^### Fixed$' -- scripts/release.sh patch

printf '### Changed\n\n- **Breaking:** renamed.\n' > changelog.d/breaking.md
git add -A && git commit --quiet -m breaking && git push --quiet origin main
expect "a breaking change refuses minor" 1 'cannot carry' -- scripts/release.sh minor
expect "a breaking change takes major" 0 '^v1\.0\.0 -> v2\.0\.0$' -- scripts/release.sh major

# A local tag is not a release: the next version counts from origin's tags.
git tag v9.9.9
expect "a stray local tag is ignored" 0 '^v1\.0\.0 -> v2\.0\.0$' -- scripts/release.sh major
git tag -d v9.9.9 > /dev/null

# The guards: where the release could go, and what the clone's git acts on.
expect "an exported GIT_DIR is refused" 1 'GIT_DIR is set' -- env GIT_DIR="$base/work/.git" scripts/release.sh patch
expect "an exported GIT_WORK_TREE is refused" 1 'GIT_WORK_TREE is set' -- env GIT_WORK_TREE="$base/work" scripts/release.sh patch
expect "an origin that is not the GitHub repository is refused" 1 "origin's fetch URL is $base/origin\.git, not instafind-io/instafind-php-sdk on GitHub" -- env RELEASE_ALLOW_ANY_ORIGIN= scripts/release.sh patch
git remote set-url origin https://github.com/instafind-io/instafind-php-sdk.git
git config "url.$base/origin.git.insteadOf" https://github.com/instafind-io/instafind-php-sdk.git
expect "a GitHub URL rewritten by insteadOf is checked as rewritten" 1 "fetch URL is $base/origin\.git" -- env RELEASE_ALLOW_ANY_ORIGIN= scripts/release.sh patch
git config --unset "url.$base/origin.git.insteadOf"
git remote set-url origin "$base/origin.git"
# The URL checks run before anything goes over the network.
git remote set-url origin git@github.com:instafind-io/instafind-php-sdk.git
git config remote.origin.pushurl https://example.com/elsewhere.git
expect "a push URL elsewhere is refused" 1 "origin's push URL is https://example\.com/elsewhere\.git" -- env RELEASE_ALLOW_ANY_ORIGIN= scripts/release.sh patch
git config --unset remote.origin.pushurl
git remote set-url origin "$base/origin.git"
git config --add remote.origin.url "$base/other.git"
expect "an origin with two URLs is refused" 1 'origin has 2 URLs' -- scripts/release.sh patch
git config --unset remote.origin.url "$base/other\.git"
expect "a global clone.defaultRemoteName does not rename the clone's remote" 0 '^v1\.0\.0 -> v2\.0\.0$' -- env GIT_CONFIG_COUNT=1 GIT_CONFIG_KEY_0=clone.defaultRemoteName GIT_CONFIG_VALUE_0=upstream scripts/release.sh major

# An identity only this checkout has (includeIf, or .git/config) would not reach the clone.
git config user.name "Local Only"
git config user.email local@example.invalid
expect "an identity the clone would not have is refused" 1 'would commit as' -- env -u GIT_COMMITTER_NAME -u GIT_COMMITTER_EMAIL scripts/release.sh patch
git config --unset user.name && git config --unset user.email

# The owner's rule: no hand-made worktrees, so the script clones instead.
if grep -qE '^[^#]*git[^#]*worktree' "$repo/scripts/release.sh"; then
    fail "scripts/release.sh uses git worktree"
fi

# What the runs left behind.
[[ "$(git tag -l)" == "v1.0.0" ]] || fail "the checkout has tags it did not have: $(git tag -l | tr '\n' ' ')"
[[ "$(git worktree list | wc -l)" -eq 1 ]] || fail "worktrees were added: $(git worktree list)"
[[ -z "$(git status --porcelain)" ]] || fail "the checkout changed: $(git status --porcelain)"
[[ "$(git rev-parse HEAD)" == "$(git rev-parse origin/main)" ]] || fail "the checkout's HEAD moved"
[[ -z "$(ls -A "$TMPDIR")" ]] || fail "temporary files were left: $(ls -A "$TMPDIR")"
[[ "$(git -C "$base/origin.git" tag -l)" == "v1.0.0" ]] || fail "origin got tags: $(git -C "$base/origin.git" tag -l | tr '\n' ' ')"

if [[ "$failures" -gt 0 ]]; then
    echo "$failures failed." >&2
    exit 1
fi
echo "All release dry runs passed."
