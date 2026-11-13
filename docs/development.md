# Development

## Requirements

| Component | Version / note |
|---|---|
| PHP | ≥ 8.4 (developed with 8.5) with the extensions `pdo_mysql`, `sodium`, `gd`, `fileinfo`, `zip`, `curl` |
| Composer | 2.x |
| MariaDB | 10.11 or newer (Debian 12; CI tests 10.11 and the newest LTS 12.3), local, on `127.0.0.1:3306` by default. MySQL is not supported: the migrations use MariaDB syntax |
| Firefox | optional, for screenshots (skill `visual-check`) |
| Node.js | not needed; JavaScript is checked via Firefox headless (screenshot) |
| Tesseract | not needed (OCR is deferred) |

Linux, macOS and Windows work alike. Notes:

- If `php` is not on the PATH, call it with its full path. The skills and
  examples accept the variable `PHP` for this (`PHP=${PHP:-php}`).
- **Windows / PowerShell:** after installing PHP or Composer, reload PATH in
  an open shell:
  `$env:Path = [Environment]::GetEnvironmentVariable('Path','Machine') + ';' + [Environment]::GetEnvironmentVariable('Path','User')`.
- No `mysql` client needed: database queries via short PHP/PDO scripts
  (skill `db-query`).
- Uploads of score sheet photos up to 10 MB need `upload_max_filesize = 10M`
  and `post_max_size = 12M` in `php.ini` (PHP defaults: 2M and 8M);
  `deploy/install.sh` sets them on the server.
- Development server: `php -S 127.0.0.1:8000 -t public`.
  The server delivers existing files (CSS/JS) directly, all other paths go to
  `public/index.php`. The tests start the server with `public/index.php` as
  router script; then there are no static files, which is enough for the
  tests.

## Databases

| Database | Purpose | Rules |
|---|---|---|
| Development database (e.g. `bowlingliga`), named in `config/database.php` | Your own data for the development server | May hold real league data: read for smoke tests, or remove temporary data completely afterwards. |
| `bowlingliga_test` | Integration tests (root without password by default) | Dropped and rebuilt from the migrations for every test |

For a fictional league to try things out or take screenshots, fill an
empty database with `php bin/seed-demo.php` (see
[operations.md](operations.md)); `InstallationIntegrationTest` runs it
against the test database, so it keeps up with the schema.

The uploaded original score sheets do not lie in the database but in
`score_sheet_dir` (locally empty = `storage/score-sheets/` in the project,
ignored by git). Tests use their own temporary directories or the
`InMemoryBlobStore`.

The connection of the integration tests can be overridden via
`BOWLINGLIGA_TEST_DB_HOST/PORT/NAME/USER/PASSWORD/CHARSET`.

## Tests

```bash
php vendor/bin/phpunit                         # all (≈ 3–5 min)
php vendor/bin/phpunit --testsuite Unit
php vendor/bin/phpunit tests/Integration/Admin  # E2E via HTTP
```

| Suite | Content |
|---|---|
| `tests/Unit` | Controllers, views, use cases with in-memory ports, security |
| `tests/Property` | Correctness properties 1–23, see [testing.md](testing.md) (eris, ≥ 100 iterations) |
| `tests/Integration` | Real test database: repositories, aggregation, result entry, season planning, login, public views |
| `tests/Integration/Admin` | End-to-end: starts `php -S` with `public/index.php` (configuration via `BOWLINGLIGA_CONFIG`), logs in and runs through all admin areas via cURL |

Expectation: all tests green, no skipped tests, no deprecations. Run the
complete suite before finishing any change. If MariaDB is not running, the
integration tests are skipped – start it first.

**Continuous integration:** [.github/workflows/tests.yml](../.github/workflows/tests.yml)
runs the complete suite on GitHub for every push to `main` and every pull
request and once a week, with PHP 8.4 and 8.5 against MariaDB 12.3 (newest
LTS) and with PHP 8.4 against MariaDB 10.11 (lowest supported). It calls
PHPUnit with `--fail-on-all-issues`, so a skipped test, a deprecation or a
notice fails the run as well. Locally the same check is
`php vendor/bin/phpunit --fail-on-all-issues --display-all-issues`.
Before installing, `composer validate --strict` checks that `composer.lock`
matches `composer.json`; after a change to `composer.json` run
`composer update --lock` (or `composer update <package>`). `composer audit`
then fails the run if a locked package has a known security advisory; the
weekly run catches advisories published between pull requests.

**Secret scan:** the job "Secret scan" of the same workflow runs
[gitleaks](https://github.com/gitleaks/gitleaks) over the whole history.
[.gitleaks.toml](../.gitleaks.toml) uses the default rules and only relaxes
the heuristic rule for generic keys under `tests/` (made-up passwords). Run
it locally with `gitleaks git . --config .gitleaks.toml --redact`. On
GitHub, secret scanning with push protection is switched on in the
repository settings as well.

### Dependency updates

[Renovate](https://docs.renovatebot.com/) (GitHub app) opens pull requests
for Composer packages, GitHub Actions, the gitleaks version and the MariaDB
version of the CI, configured in
[.github/renovate.json](../.github/renovate.json):

- weekly on Monday morning, a version must be at least 7 days old; the
  lock file is refreshed once a month; open updates are listed in the
  "Dependency Dashboard" issue,
- minor and patch updates of the development tools and the CI tools are
  merged automatically once the tests are green; runtime packages (mPDF,
  HTML Purifier, …) and major updates are reviewed by hand,
- `config.platform.php` in `composer.json` is 8.4.1, so packages are always
  resolved for the lowest supported PHP version (8.4.1 rather than 8.4.0,
  since PHPUnit 13 requires it),
- MariaDB updates only appear as a checkbox in the Dependency Dashboard:
  Renovate cannot tell an LTS line from a short-term release, so approve
  only a new LTS (see [endoflife.date/mariadb](https://endoflife.date/mariadb));
  closing a major update pull request instead would make Renovate ignore
  that major version for good, including a later LTS of it,
- not updated: the PHP constraint and MariaDB 10.11 of the CI (both state the
  lowest supported version and are changed by hand).

**TinyMCE** (editor of the CMS texts) comes as the Composer packages
`tinymce/tinymce` and `mklkj/tinymce-i18n` (German language pack), so
Renovate proposes its updates and `composer audit` reports its advisories
like for every other package. The web server only delivers `public/`:
after every `composer install`/`update` the script
`bin/publish-tinymce.php` copies the files the editor loads to
`public/assets/vendor/tinymce/` (not versioned, rebuilt on every
deployment). A new plugin in `rich-text.js` needs its file in the list of
that script (`CmsEditViewTest` checks that every loaded file is there).
After an update of TinyMCE check the editor via screenshot, light and dark.

Supported versions: PHP as long as it gets active support from the PHP
project (8.3 left it at the end of 2025), MariaDB from 10.11 (Debian 12).

## Conventions

- English identifiers and comments, German UI texts; domain terms according
  to the [glossary](glossary.md). Rule references as `(R12.9)` or
  `R3.3–R3.14` in comments and docblocks only, never in visible texts (test
  `NoRequirementNumbersInTextsTest`). Every referenced rule must exist in
  [domain-logic.md](domain-logic.md), also as part of a range there (test
  `RuleReferencesTest`); a new rule therefore goes into the document first.
- `declare(strict_types=1)`, `final` classes, `readonly` constructor
  properties.
- Domain errors as their own `…Exception` with a user-facing message;
  the controller translates them into an `ActionResult`.
- Views escape every output; forms contain `$csrf->fieldHtml()`.
- Tests use PHPUnit attributes such as `#[DataProvider('name')]` with a static
  provider; docblock annotations (`@dataProvider`) are ignored by PHPUnit 12 and later.
  PHPUnit captures `error_log()` during a test, so code that logs gets the log
  function injected (example: `TeamEntryController`, parameter `log`).
- New schema changes: add a new numbered migration and update
  `applySchema()`/`dropSchema()` in the integration tests. Locally, after an
  update with new migrations, run `php bin/migrate.php` once (against the
  development database). Migrations must be repeatable (`IF NOT EXISTS`), because
  `bin/migrate.php` (table `schema_migration`) applies them on the server
  during an update and brings older databases up to date.
- What was implemented and why is described by the commit message.
- Commit only on request.

## Recipe: new admin feature area

1. Use case in `src/Application/…` with port interfaces. It checks
   `WriteAccess` and uses `Transaction` when there are several writing steps.
2. Adapter in `src/Persistence/…` (extends `AbstractRepository`, uses
   `transactional()` and prepared statements).
3. Controller + view in `src/Presentation/`. The controller takes
   `array $input` and returns an `ActionResult` or HTML. The view escapes.
4. Wire it in `public/index.php` (composition root) and create the routes
   following the pattern in [architecture.md](architecture.md). Add the link
   in `AdminView::renderOverview()` and the entry in `AdminView::barEntries()`.
5. Tests: unit tests for controller/view/use case, an integration test
   against the test database, an E2E case in `AdminAreaIntegrationTest`
   (401 without login, writing run with a persistence check).

The skill `new-admin-area` contains the detailed recipe.

## Visual check via screenshot (without Node)

Firefox headless takes screenshots of pages and PDFs. This allows checking
every view without changing data. Ready-made scripts and procedure:
[.claude/skills/visual-check/](../.claude/skills/visual-check/SKILL.md)
(plain PHP, so it runs on every platform without shell scripts).

- **Server with router script** (`router.php`): it sets the cookies
  `bl_style`/`bl_mode`/`bl_lang` from the environment variables
  `BOWLINGLIGA_STYLE`/`_MODE`/`_LANG`, delivers existing files below
  `public/` with `return false` and otherwise includes `public/index.php`.
  For admin pages (`BOWLINGLIGA_ADMIN=<account id>`) it additionally sets up
  a logged-in session – only for plain requests (GET).
- **Screenshot** (`screenshot.php`): `firefox --headless --profile <own
  profile next to the target> --no-remote --screenshot <target.png>
  --window-size=1280,1500 <url>`, with a time limit of 40 s (a direct call
  hung once). Desktop 1280 px, phone 390 px width. `FIREFOX` overrides the
  path of the binary.
- **PDFs:** first save with `curl`, then open `file:///…pdf` in the same way
  (Firefox shows it with pdf.js). Quick page count check: count
  `/Type /Page` in the PDF.
- Only terminate your own processes (Firefox with its own profile, PHP on
  the test port), never a Firefox the user has open.

## mPDF quirks (print score sheet)

mPDF renders HTML differently from a browser. For the print layout:

- **Page breaks** only with `<pagebreak />`; `page-break-before` on a `div`
  is ignored. For the HTML fallback via CSS
  `pagebreak { display:block; page-break-before:always; }`.
- **Column widths** from `<colgroup>` are ignored. Write widths as
  `style="width:…"` on the cells of the first row.
- **Child selectors** (`a > b`) in the stylesheet have no effect; use simple
  classes or inline styles.
- **Halves of equal width:** mPDF fits nested tables to their content. Two
  blocks with `float:left/right; width:48.5%` are reliable.
- **Vertical text** in table headers: attribute `text-rotate="90"` on the
  `th`.
- The A4 landscape format comes from the mPDF configuration
  (`'format' => 'A4-L'`), not from CSS `@page size`.

## Templates

The virtual and the print score sheet reproduce the league's paper score
sheet; `ScoreSheetCalculatorTest` recalculates the values of a real one
(811/782/804 = 2397, points 8 : 6). The original templates of the league
(score sheet, average list, table, schedule scheme) are not part of the
repository; maintainers keep them under `.local/`, which git ignores.
