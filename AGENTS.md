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

## SemVer: what the public API is

From `v1.0.0` on, these rules decide what is breaking (major), and the README's
"Stability" section says the same for users:

- Response objects are built by the SDK only. Their constructors are
  `@internal`, so adding a field or accessor is a minor change. Result
  classes are `final`; one wraps another rather than extending it
  (`CampaignResult::result()` gives the `SearchResult`).
- Parameter names are public API because of named arguments: renaming one is
  breaking. Add new parameters only at the end, with a default.
- Closed sets of input values are backed enums (`SearchContext`,
  `SortOrder`, `RefreshMode`, `FieldType` …). Values the API sends back stay
  strings, so a new server value is not a break.
- Interfaces a host implements are frozen in 1.x: `Transport`, `Credential`,
  `TokenStore`, `ConnectionObserver`, `BulkListener`, `Sleeper`, `Clock`.
  Adding a method needs a new interface.
- Dates are `DateTimeImmutable`, never strings.
- Typed inputs where 1.0 takes arrays (`updateField()`, `putSearchBehaviors()`)
  may be added later as new methods or accepted types, never by changing what
  the array form accepts.

## Layout

- `src/PublicApi.php`, `src/IntegrationApi.php`, `src/ClientOptions.php`: the entry points.
- `src/Search/`: queries, results and enums of the public API.
- `src/Integration/`: results and enums of the integration API; `src/Indexing/` the `BulkLoader`; `src/Schema/` `IndexSchema` and `FieldType`.
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

`tests/Contract/OpenApiContractTest.php` checks the SDK against
`openapi/instafind-v1.json`, a copy of the backend's
`docs/openapi/instafind-v1.json`. Refresh it with
`scripts/update-openapi.sh [ref]` when the backend's document changes, then fix
what the test reports. Its two gap lists (`UNDECLARED_PARAMETERS`,
`UNDOCUMENTED_FIELDS`) name what the document lags behind the backend; the
test fails once a gap closes, so remove the entry then.

Without a local PHP, run them in Docker, e.g.
`docker run --rm -u "$(id -u)" -v "$PWD":/app -w /app php:8.1-cli vendor/bin/phpunit`.

## Live integration test

`.github/workflows/integration.yml` runs `tests/Integration/LiveApiTest.php`
against production's "PHP SDK CI" website: by hand (`gh workflow run
integration.yml`), daily at 05:17 UTC, and never on pull requests.
The job uses the environment `live-integration` (deployment branch `main`
only), which holds its secrets `INSTAFIND_CI_API_KEY`,
`INSTAFIND_CI_WEBSITE_ID`, `INSTAFIND_CI_BASE_URL`; never repository secrets,
which any branch's workflow could read. It runs no Composer packages, only
the SDK's autoloader and a PHPUnit PHAR pinned by checksum. Repository
variables: `INSTAFIND_CI_INDEX_ID` (the reused index, as the API cannot
list indexes) and optionally `INSTAFIND_CI_PUBLIC_BASE_URL` (default `https://s.instafind.io`). It writes
three fixed products and deletes them, sends one fixed order (which stays),
and sets the CI index's campaign page, default filters and template. Without
`INSTAFIND_CI_INDEX_ID` it creates the index and fails with the id to set.
The suite is not in the default `vendor/bin/phpunit` run; run it with
`--testsuite integration`. Locally it skips without the key.

## Releases

`scripts/release.sh <patch|minor|major>` shows what would be released;
`--push` releases, and only once the live integration job has passed on
`origin/main`'s tip (it prints the `gh workflow run` command otherwise). The
first release is `major` and gives `v1.0.0`.
`release.yml` refuses a tag whose `Sdk::VERSION` or changelog section does not
match it. Only with the owner's go.
