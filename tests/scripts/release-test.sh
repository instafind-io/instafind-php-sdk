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
cp -r "$repo/scripts" "$repo/changelog.d" "$repo/CHANGELOG.md" .
cp "$repo/src/Sdk.php" src/
# This repository's own fragments stand in for a release's notes; one more makes sure there is one.
printf '### Added\n\n- Something new.\n' > changelog.d/test-added.md
git add -A && git commit --quiet -m init && git push --quiet origin main

expect "no tag yet: patch is refused" 1 'first one is v1.0.0' -- scripts/release.sh patch
expect "no tag yet: major gives v1.0.0" 0 '^\(no release yet\) -> v1\.0\.0$' -- scripts/release.sh major
expect "the notes are the fragments" 0 '^- Something new\.$' -- scripts/release.sh major
expect "nothing is pushed without --push" 0 'Nothing pushed\. Run again with --push to release v1\.0\.0\.' -- scripts/release.sh major

# As if v1.0.0 were released: its section, the links, the constant, no fragments.
url=https://github.com/instafind-io/instafind-php-sdk
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
