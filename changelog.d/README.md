# Changelog fragments

A pull request with a change someone would notice adds one new file here, with a
name no other pull request would pick and no slashes:
`changelog.d/curl-transport.md`. It never edits `CHANGELOG.md`. Separate files
are what let parallel pull requests merge without conflicts, and a pull request
open across a release simply lands in the next one.

```markdown
### Fixed

- `CurlTransport` no longer sends `Expect: 100-continue` with large bodies.
```

The headings are `### Added`, `### Changed`, `### Deprecated`, `### Removed`,
`### Fixed` and `### Security`, and one file can use several. Prefix a change
that breaks the public API (anything not marked `@internal`) with
**Breaking:**; it needs a major release. Text above the first heading is a note
on the whole release, such as how to upgrade.

`scripts/release.sh` merges the files into a new section of `CHANGELOG.md`,
sets `Sdk::VERSION` and deletes them. `scripts/changelog-fragments.sh` prints
what they add up to now.
