# AGENTS.md

Guidance for coding agents in this repository. `CLAUDE.md` imports this file;
edit this one.

The SDK's design, scope and versioning rules are in the backend spec:
`~/code/instafind/instafind-backend/docs/superpowers/specs/2026-11-27-versioned-public-api-php-sdk.md`
(GitHub `msti/instafind-backend`, same path). Read it before changing the
public API of this package.

## Rules

- PHP `>=8.1`: no readonly classes, no features newer than 8.1. `declare(strict_types=1);` in every file.
- No required dependencies beyond `ext-json`. PSR-18 support is an optional adapter (`suggest`).
- Everything not marked `@internal` is public API under SemVer. Breaking it needs a major version.
- Typed, immutable response objects with `raw(): array`; errors are exceptions under `InstafindException`.
- Every change someone would notice adds a fragment to `changelog.d/`; never edit `CHANGELOG.md` by hand.
- Never tag or release without the owner's go. Not published to Packagist.

## Layout

- `src/Transport/`: the `Transport` interface and its adapters (cURL default, PSR-18, WordPress).
- `src/Auth/`: `Credential`, `StaticKey`, and `OAuth/` (PKCE flow, `OAuthConnection`, the host's `TokenStore`).
- `src/Http/`: `Request`, `Response`, `RetryPolicy`, `UserAgent`; `ApiClient` is `@internal` plumbing.
- `src/Exception/`: everything thrown, under `InstafindException`.
- `src/Testing/MockTransport.php`: public, for consumers' tests too.
- `src/Sdk.php`: `Sdk::VERSION`, set only by `scripts/release.sh`.

## Checks

The same as CI (`.github/workflows/ci.yml`):

```sh
composer update
vendor/bin/phpunit                                  # PHP 8.1 and 8.5 in CI
vendor/bin/phpstan analyse --memory-limit=1G        # level max
vendor/bin/php-cs-fixer fix --dry-run --diff        # PER-CS 2.0
scripts/changelog-fragments.sh changelog.d
```

Without a local PHP, run them in Docker, e.g.
`docker run --rm -u "$(id -u)" -v "$PWD":/app -w /app php:8.1-cli vendor/bin/phpunit`.

## Releases

`scripts/release.sh <patch|minor|major>` shows what would be released;
`--push` releases. The first release is `major` and gives `v1.0.0`.
`release.yml` refuses a tag whose `Sdk::VERSION` or changelog section does not
match it. Only with the owner's go.
