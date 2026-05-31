# koriym/cs-sql

[![Continuous Integration](https://github.com/koriym/Koriym.CsSql/actions/workflows/continuous-integration.yml/badge.svg)](https://github.com/koriym/Koriym.CsSql/actions/workflows/continuous-integration.yml)
[![Coding Standards](https://github.com/koriym/Koriym.CsSql/actions/workflows/coding-standards.yml/badge.svg)](https://github.com/koriym/Koriym.CsSql/actions/workflows/coding-standards.yml)
[![Static Analysis](https://github.com/koriym/Koriym.CsSql/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/koriym/Koriym.CsSql/actions/workflows/static-analysis.yml)

A small PHP SQL formatter for stable, clause-oriented SQL output. It follows a
fixed, tool-friendly style for diagnostics, logs, examples, and developer
tooling.

## Installation

```bash
composer require koriym/cs-sql
```

## Usage

```php
<?php

use Koriym\CsSql\CsSql;

$formatter = new CsSql();

echo $formatter->format(
    'SELECT u.id, u.name FROM users u WHERE u.age >= 18 AND u.active = 1',
);
```

Output:

```sql
SELECT
    u.id,
    u.name
FROM
    users u
WHERE
    u.age >= 18
    AND u.active = 1
```

## CLI

The package also installs a `cs-sql` command:

```bash
vendor/bin/cs-sql path/to/sql
```

When the path is a directory, all `.sql` files below it are formatted in place
recursively. Non-SQL files are left unchanged.

For example, this formats every SQL sample in this repository:

```bash
vendor/bin/cs-sql examples
```

For CI, use `--check` to verify formatting without modifying files:

```bash
vendor/bin/cs-sql --check path/to/sql
```

The command exits with `0` when all SQL files are already formatted and `1` when
any file needs formatting.

In application projects, wire SQL formatting into the normal Composer coding
style scripts. The dedicated `sql` and `sql-fix` scripts are mostly for
symmetry and readability; day-to-day use can stay with `composer cs` and
`composer cs-fix`.

```json
{
  "scripts": {
    "cs": [
      "phpcs",
      "@sql"
    ],
    "cs-fix": [
      "phpcbf src tests",
      "@sql-fix"
    ],
    "sql": "cs-sql --check var/db/sql",
    "sql-fix": "cs-sql var/db/sql"
  },
  "scripts-descriptions": {
    "cs": "Check coding style",
    "cs-fix": "Fix coding style",
    "sql": "Check SQL formatting",
    "sql-fix": "Fix SQL formatting"
  }
}
```

This repository checks its own SQL sample in the same way:

```bash
composer sql-cs
```

## Style

The formatter intentionally has no runtime style options. `CsSql` represents
one fixed style inspired by common SQL formatter defaults:

- SQL keywords are uppercased.
- Identifiers keep their input spelling and case.
- One indentation level is four spaces.
- Clause keywords are left-aligned and clause bodies are indented.
- List items are split onto stable lines.
- `JOIN` sources and `ON` predicates are split onto separate indented lines.
- Alignment rivers are intentionally not used, keeping diffs small when names
  change.

### History

SQL formatting has two competing traditions. One comes from hand-crafted SQL,
including Joe Celko's style writing, where careful alignment can make a query
look like a table. The "river" style is the best-known example: keywords and
expressions are padded so that a vertical stream of whitespace runs through the
query.

That can be pleasant to read, but it is a poor default for an automatic
formatter. Small identifier changes can force unrelated spacing changes, making
Git diffs and reviews noisy. Modern formatter defaults tend to prefer stable
indentation, clause-oriented line breaks, and minimal alignment instead.

`cs-sql` chooses that modern direction. It keeps SQL readable by exposing the
query structure, while avoiding formatting choices that create large unrelated
diffs.

## Supported Scope

The formatter preserves SQL string literals, quoted identifiers, bracket
identifiers, comments, dotted identifiers, and multi-character operators. It has
dedicated formatting for common `SELECT`, `INSERT`, `UPDATE`, `DELETE`, `CREATE
TABLE`, `ALTER TABLE`, CTE, `UNION`, subquery, window function, procedure,
trigger, transaction, and `MERGE` shapes.

This package is a formatter, not a complete SQL parser, style checker, or
validator. It does not rewrite queries, validate dialect-specific syntax, or
guarantee semantic equivalence for arbitrary SQL outside the covered formatting
scope.

## Development

```bash
composer test
composer tests
composer cs-fix
composer sql-cs
```

`composer tests` runs PHP coding standards, SQL sample formatting checks,
PHPStan, Psalm, and PHPUnit. GitHub Actions runs PHPUnit and the SQL sample
check on PHP 8.0 through 8.5, and runs the full quality gate on PHP 8.1 through
8.5 because the static-analysis toolchain requires PHP 8.1+.
