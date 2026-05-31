# Changelog

## Unreleased

### Changed

- Renamed the package to `koriym/cs-sql` and the public formatter class to
  `Koriym\CsSql\CsSql`.
- Added the `cs-sql` CLI for formatting `.sql` files recursively in place, with
  `--check` mode for CI.
- Reworked the formatter around one fixed style:
  - SQL keywords are uppercased.
  - Identifiers preserve their input spelling and case.
  - Indentation is four spaces.
  - Clause keywords start on their own lines.
  - Clause bodies and list items are indented on stable lines.
  - `JOIN` sources and `ON` predicates are split onto separate indented lines.
  - Alignment rivers are intentionally avoided.

The layout takes inspiration from common formatter defaults such as SQLFluff,
but it is not intended to be SQLFluff-compatible output. `cs-sql` remains a
small PHP formatter with its own fixed style, including uppercase SQL keywords
and safe handling for PDO-style named placeholders such as `:limit`.

### Fixed

- Preserved string literals, quoted identifiers, bracket identifiers, comments,
  dotted identifiers, multi-character operators, and named placeholders during
  formatting.
- Updated the local quality gate and CI to run on the supported PHP versions.
