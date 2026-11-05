# Contributing

Thank you for helping with the league website. This page explains the few
rules every change follows and walks through the most common contribution
that needs no programming: **adding a language**.

## Before you start

- Set up PHP, Composer and MariaDB as described in
  [development.md](development.md#requirements); the tests need
  the test database `bowlingliga_test`, which they rebuild themselves.
- Found a security problem? Please do not open an issue; report it
  privately as described in [SECURITY.md](../SECURITY.md).
- Working with an AI coding agent? [AGENTS.md](../AGENTS.md) holds the
  rules and commands in short form; Claude Code reads it via `CLAUDE.md`.
- Read the [architecture](architecture.md) (layers, ports and adapters) and,
  for domain changes, the rules in [domain-logic.md](domain-logic.md).

## Rules for every change

- **English in code**, identifiers, comments, commit messages and this
  documentation. Domain terms follow the [glossary](glossary.md).
- **Texts of the user interface live in the catalogs** `lang/<code>.php`,
  never in the code (see [Languages](architecture.md#languages)). Requirement
  numbers such as `(R12.9)` belong in comments only, never in visible texts.
- **Raw data is the single source of truth**: standings, averages and points
  are computed, not stored.
- A schema change is a new numbered migration in `db/migrations/` and is
  added to the `applySchema()` lists of the integration tests.
- **All tests green**, none skipped, no deprecations:

  ```bash
  php vendor/bin/phpunit --fail-on-all-issues --display-all-issues
  ```

  GitHub runs the same check for every pull request (PHP 8.3, 8.4 and 8.5
  with MariaDB, see `.github/workflows/tests.yml`).

- For changes to pages or the printed score sheet, look at the result
  (see [visual check](development.md#visual-check-via-screenshot-without-node)).

## Adding a language

The site ships with German (`de`, the default) and English (`en`). A new
language is **one new file and no code**: a catalog describes itself, and
the site finds every catalog in `lang/`. The work is translating about 800
short texts. Everything else adapts by itself: the language switch in the
footer, the choice from the browser's `Accept-Language`, the language of
printed score sheets per season and the print form.

### Steps

1. **Copy a catalog.** Copy `lang/en.php` to `lang/<code>.php`, where
   `<code>` is the two-letter ISO 639-1 code in lower case (e.g. `nl`, `fr`,
   `pl`). The file name is the language's code; other file names are
   ignored.
2. **Name your language** in the first entry, `language.name`, in its own
   language (e.g. `'Nederlands'`). Every selection of languages shows this
   name, so visitors find their language even if the page is shown in
   another one.
3. **Translate the texts**, keeping the keys exactly as they are. Rules:
   - Keep every placeholder in curly braces, e.g. `{team}`, `{count}`,
     `{date}` – you may move it within the sentence, but not rename or drop
     it.
   - Keep markup-free texts free of HTML; the pages escape every text.
   - Domain terms: use what bowlers in your language call them; the
     [glossary](glossary.md) explains what each German term means.
4. **Dates and numbers** are part of the catalog:
   - `date.pattern` builds a date from `{d}` (day without leading zero),
     `{dd}`, `{mm}`, `{month}` (short month name) and `{yyyy}`; pick the
     parts your language uses, e.g. `{dd}-{mm}-{yyyy}`.
   - `date.pattern_weekday` and `date.pattern_weekday_long` combine
     `{weekday}` and `{date}`.
   - `date.weekday.0` … `date.weekday.6` (Sunday first) and
     `date.month.1` … `date.month.12` are short names.
   - `number.decimal` is the decimal separator (`,` or `.`).
5. **Run the tests.** `tests/Unit/I18n/CatalogTest.php` checks that your
   catalog exists, has exactly the same keys as the German one, leaves no
   text empty and keeps all placeholders:

   ```bash
   php vendor/bin/phpunit --testsuite Unit
   ```

6. **Look at it.** Switch the language in the footer and go through the
   public pages, the team entry, the admin area and a printed score sheet
   (choose your language in the print form).

To make your language the default of an installation, set
`'language' => '<code>'` in `config/database.php`.

### Things to know

- A key missing in a catalog falls back to German and then to the key
  itself, so nothing breaks – but `CatalogTest` fails until the catalog is
  complete.
- **Plurals** are separate keys where the wording changes
  (`results.one_change` / `results.changes`, `print.legend.point` /
  `print.legend.points`): one form for exactly one, one for every other
  number. Languages with more plural forms (e.g. Polish) get the second form
  for every number other than one; extending this would need a change in the
  code – please open an issue first.
- **Right-to-left** scripts are not supported by the stylesheets yet.
- **Not translated on purpose:** the column names of the player import file
  (Team, Name, Geschlecht, Vorjahr Schnitt, Vorjahr Spiele) are a file
  format; team and player names, news and rules are data entered by the
  league.
- Messages for operators and developers (configuration errors, command line
  tools, internal checks) are not part of the catalogs.

## Adding a text

When a change needs a new text in the user interface:

1. Choose a key after the existing pattern (`area.purpose`, e.g.
   `season.lot_draws_hint`) and add it to **every** catalog.
2. In views, use the `text()` helper of the
   [ViewTexts](../src/Presentation/ViewTexts.php) trait (escaped) or
   `$translator->t()`; below the presentation layer, return a
   [Message](../src/Domain/Message.php) (key and parameters) or throw an
   exception implementing [Translatable](../src/Domain/Translatable.php)
   instead of a sentence.
3. The English page tests (`PublicViewTest`, `TeamEntryViewTest`,
   `AdminAreaIntegrationTest`) fail if a German text or a bare key shows up on
   an English page.
