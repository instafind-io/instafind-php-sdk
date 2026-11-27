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
