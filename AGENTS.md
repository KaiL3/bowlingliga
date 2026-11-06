# Bowlingliga – Bowling League Website

Instructions for AI coding agents (and a quick start for humans). PHP ≥ 8.4
without a framework, MariaDB with InnoDB, Ports & Adapters.
Entry point and details: [docs/README.md](docs/README.md). Domain rules:
[docs/domain-logic.md](docs/domain-logic.md) (authoritative, sections R1–R38
with the Rxx.y numbers referenced in the code); correctness properties 1–23:
[docs/testing.md](docs/testing.md).

## Commands

```bash
php vendor/bin/phpunit                    # all suites, a few minutes
php vendor/bin/phpunit --testsuite Unit   # fast, no database needed
php -S 127.0.0.1:8000 -t public           # development server
```

If `php` is not on the PATH, use its full path; the skills accept it in the
variable `PHP` (`PHP=${PHP:-php}`).

The integration tests need a reachable MariaDB; they create and
rebuild the database `bowlingliga_test` themselves (see
[docs/development.md](docs/development.md)). Without a database they are
skipped, which does not count as green.

Tools that need neither a `mysql` client nor Node:

- `php .claude/skills/db-query/query.php "SELECT …"` – SQL against the
  configured database, read-only unless `--write` is given.
- `.claude/skills/visual-check/` – screenshots of pages and print PDFs via
  Firefox headless (procedure in its `SKILL.md`).

Claude Code loads these as the skills `db-query`, `visual-check`,
`new-admin-area` and `update-docs`; other agents can follow the same
`SKILL.md` files as plain instructions.

## Rules

- **Raw data is the single source of truth** (R35). Standings, averages,
  points and honours are computed; caches only for performance.
  Frozen handicaps are raw data and are never silently overwritten.
- **Layers:** `Domain` without I/O → `Application` (use cases, ports)
  → `Persistence` (PDO adapters) / `Presentation` (controllers, views as
  PHP strings). Wiring only in `public/index.php`. Add new port methods
  to the in-memory fakes under `tests/Unit` as well.
- **English** in identifiers, code comments and commit messages; the user
  interface is German and English via the catalogs `lang/<code>.php`, never
  hard-coded texts. Rule references are written `(R12.9)` or `R3.3–R3.14`,
  only in comments, never in visible texts (test
  `NoRequirementNumbersInTextsTest`), and must exist in
  `docs/domain-logic.md` (test `RuleReferencesTest`).
  `declare(strict_types=1)`, `final`, `readonly`.
- Views escape every output; forms use `$csrf->fieldHtml()`;
  writing use cases check `WriteAccess` and use `Transaction`.
- Schema change = new numbered migration in `db/migrations/` plus
  `applySchema()`/`dropSchema()` in the integration tests.
- **The development database** named in `config/database.php` may hold real
  league data: read only, unless the maintainer explicitly asks for a change
  (then within a transaction). For experiments fill an empty database with
  `php bin/seed-demo.php`.
- **Deactivate instead of delete:** a feature that is left out keeps its
  code and tests and is only unwired (example: OCR).
- Clarify domain ambiguities (game rules, matchday status, print content)
  with a concrete question instead of guessing.
- Commit only on request.

## After every change

1. Full test suite green, no skipped tests, no deprecations.
2. For UI changes, check the rendering/PDF via screenshot (`visual-check`).
3. Update the docs (`update-docs`): changed rules in `docs/domain-logic.md`,
   the reason for a decision in the commit message.
