# Architecture

## Layers

```
public/index.php        Composition root + routing (single entry point)
src/Presentation        Controllers, views (HTML strings), router, read ports of the UI
src/Application         Use cases (writing) + aggregators (reading), port interfaces
src/Domain              Pure domain logic without I/O (handicap, points, season planner, …)
src/Persistence         PDO repositories = adapters of the ports
src/Security            Auth, session, CSRF, validator, HTML sanitizer, TOTP
src/Cache               Cache manager/invalidation (cache tables)
src/Printing, src/Ocr   Adapters for mPDF and Tesseract
```

Dependencies point inwards: `Presentation → Application → Domain`;
`Persistence` implements interfaces from `Application`/`Presentation`.
`Domain` knows neither PDO nor HTTP and is fully covered by unit and
property tests.

### Ports and adapters – examples

| Port (interface) | Lives in | Adapter |
|---|---|---|
| `ResultStore`, `FixtureStatusStore`, `MatchdayFlagStore`, `HandicapFreezing` | `Application/ResultEntry` | `ResultRepository`, `FixtureRepository`, `MatchdayRepository`, `Persistence/ResultEntry/HandicapFreezingRepository` |
| `Transaction` | `Application` | `Persistence/PdoTransaction` |
| `TableRawDataReader` (among others) | `Application/Aggregation` | `Persistence/Aggregation/*RawDataRepository` |
| `WriteAccess` | `Security` | `AuthModule` (in tests `SwitchableWriteAccess`) |
| `MasterDataReader`, `CmsReader`, `SeasonPlanningReader`, `EntryData`, `PlayerStatsReader` | `Presentation` | repositories under `Persistence/*` |

Unit tests replace ports with small in-memory fakes
(`tests/Unit/**/InMemory*`, `Recording*`). Whoever adds a method to a port
must update these fakes as well.

## Composition root and routing

[public/index.php](../public/index.php) wires all objects by hand
(no DI container) and registers routes on the own
[Router](../src/Presentation/Router.php):

- `$router->public('/path', fn)` and `$router->publicWithId('/path', fn(int $id))`
  for read-only public pages (`/matchday/{id}`, `/score-sheet/{id}`).
- `$router->admin('/path', fn)` registers under `/admin/path`.

URLs, query parameters (`?season=`, `?tab=`, `?fixture=` …) and form field
names are English (switched from German on 2026-10-25, see
[Languages](#languages)); only the texts follow the visitor's language.

Configuration: `config/database.php` (not versioned; template
`.example`). The environment variable `BOWLINGLIGA_CONFIG` can point to a
different configuration file (used by the E2E test). The configuration also
contains `totp_key` (base64, 32 bytes) for encrypting the 2FA secrets; the
former key name `totp_schluessel` is still accepted.

### Pattern of every admin route

```php
$router->admin('/area/action', static function () use (...): void {
    if (! $ensureAdminAccess()) { return; }                 // 401 + login form
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { /* render overview */ return; }
    if (! $csrf->isValid($_POST['csrf_token'] ?? null)) {   // 400
        http_response_code(400); /* form with notice */ return;
    }
    $result = $controller->action($_POST);                  // ActionResult
    if (! $result->successful) { http_response_code(422); }
    echo $adminPage('Titel', $controller->page(..., $result->message));
});
```

- Controllers take raw `$_POST` arrays, convert them into types and call use
  cases. Domain exceptions (`ValidationException`, `AccessDeniedException`,
  `RuntimeException` subclasses with user-facing messages) are translated
  into an `ActionResult`.
- Views return HTML strings. Every output of data is escaped with
  `htmlspecialchars`. Only CMS HTML is output raw, because it passes through
  the `HtmlSanitizer` (HTML Purifier, whitelist) when it is saved.
- Use cases check `WriteAccess` once more themselves (defence in depth).

## Security

- **Login** ([AuthModule](../src/Security/AuthModule.php)): password hash
  Argon2id (fallback bcrypt), optional TOTP 2FA, 5 failed attempts → 15 minute
  lock, sliding session timeout of 30 minutes and at most 8 hours after the
  login. The session id is regenerated after login. A session ends
  immediately when its account has been deleted or its `session_version`
  changed (password, 2FA, "log out all other sessions"); the own session
  takes over the new value via `SessionRenewal`. An account lock does not end
  sessions (otherwise anyone could log out the admin with five wrong passwords).
  For an unknown username a valid dummy hash is checked (same response time).
  Every login step runs in a transaction that locks the account row
  (`findByUsernameForUpdate`/`findForUpdate`): parallel attempts on the same
  account run one after the other, so the 5-attempt limit holds exactly.
- **Session cookie:** HttpOnly, SameSite=Strict, Secure with HTTPS.
- **HSTS** ([TransportSecurity](../src/Security/TransportSecurity.php)): over
  HTTPS every response carries `Strict-Transport-Security` (without
  `includeSubDomains`), so from the second visit on the browser never sends
  an unencrypted request that someone in the same network could intercept.
  The same class decides HTTPS for the session cookie and the QR address.
- **CSRF:** synchronizer token per session
  ([CsrfTokenHelper](../src/Security/CsrfTokenHelper.php)); non-strings
  are rejected.
- **SQL:** prepared statements only, via `AbstractRepository`.
- **Upload of the original score sheet:** file type determined from the
  content, allowlist JPEG/PNG/PDF, stored as a file in the score sheet
  directory (see below), served under `/score-sheet/{id}` – publicly only once
  the fixture is complete. File names come only from hashes, never from the
  upload.

## Score sheet files

- The original score sheets (R3.15) lie outside the database in a
  content-addressed directory: `score_sheet_dir` from the configuration
  (server `/var/lib/<instance>/score-sheets`, locally `storage/score-sheets`),
  layout `objects/3f/a9c1…` like git. File name = SHA-256 of the content, so
  the same upload is stored once and the hash in `original_score_sheet` is
  also the checksum.
- Port [BlobStore](../src/Application/BlobStore.php), adapter
  [FileSystemBlobStore](../src/Persistence/FileSystemBlobStore.php) (writes a
  temporary file, `fsync`, `rename`; in unit tests `InMemoryBlobStore`).
  [OriginalScoreSheetRepository](../src/Persistence/OriginalScoreSheetRepository.php)
  combines file and record.
- **No common transaction:** first the file, then the record. A failure
  leaves at most a file without a record, never a record without its file.
- **Deleting:** `OriginalScoreSheetRepository::delete()` removes the file as
  soon as no other record uses the hash. Fixtures deleted by the database
  cascade (deleting a season, re-forming positionday fixtures) leave files
  behind; the use cases then call the port
  [ScoreSheetFileCleanup](../src/Application/ScoreSheetFileCleanup.php), which
  deletes unreferenced files older than 15 minutes (an upload in progress has
  written its file but not yet its record). `bin/check-score-sheets.php`
  checks the files and cleans up with `--clean`.
- Delivery sends the hash as `ETag`, a repeated request is answered with
  `304`. A record whose file is missing answers `500` (lost raw data, not
  "not found").

## Result entry by the teams

- The team page `/entry` needs no login: the code from the QR code is
  the authorisation for exactly one fixture and side (`Security/TeamEntryCodes`,
  HMAC with a key derived from `totp_key`). It also replaces the CSRF token;
  team routes use no session (a session would expire between two games).
  Cross-site POSTs (`Sec-Fetch-Site`) are redirected. The code is in the URL,
  so these pages send `Referrer-Policy: no-referrer` and `Cache-Control: no-store`.
- `Application/TeamEntry/TeamResultEntry` holds the rules (who may change
  what, checking a game, sending, withdrawing, confirming, sending back), port
  `TeamEntryStore` with adapter `Persistence/TeamEntry/TeamEntryRepository`.
  Blind values are never stored there; `Application/ResultEntry/BlindFilling`
  derives them on every read and at the final saving. Every action locks the
  fixture row (`SELECT … FOR UPDATE`) inside a `Transaction`, because both
  teams save at the same time.
- The final saving uses the same `ResultRecording` as the admin, constructed
  with `TeamEntryWriteAccess` instead of the login; that access is granted
  only while both confirmations are being turned into results.
- The QR code on the print score sheet comes from a callable passed to
  `PrintController`; the address is `base_url` from the configuration or,
  without it, scheme and host of the current request.
- The guided steps are plain forms: every action answers with a redirect to
  the next step (`?step=`, notice via `?notice=`), an error renders the
  step with status 422. `team-entry.js` only hides the net field of players
  who do not play and refreshes the confirmation step every 15 s
  (`?part=1`); nothing is reloaded while a game is being entered.

## Raw data, aggregation and cache

- Raw data = net single games, lineups, configuration, master data,
  previous season records, **frozen handicaps**.
- Reading aggregators in `Application/Aggregation`
  (`TableAggregator`, `AverageListAggregator`, `HonoursAggregator`)
  compute from the raw data via the reader ports on every request. They
  only take matchdays with `is_played = 1` into account.
- The cache tables (`cache_*`) and the `CacheManager`
  exist. Currently only result entry **invalidates** them; the public pages
  do not read from the cache but compute directly. With a growing amount of
  data this is the starting point for performance work.

## Transactions

- Every repository method runs through `AbstractRepository::transactional()`.
  If an outer transaction is already running, it joins it instead of
  committing itself.
- Use cases with several writing steps use the port
  [Transaction](../src/Application/Transaction.php) (adapter `PdoTransaction`).
  Currently: `ResultRecording` (draft, confirmation, removal, correction).
  The season plan is saved in a single repository transaction.

## Error handling

- **Writes are all or nothing** (R4.3): a failing step rolls the whole
  transaction back; the user gets a message naming the operation.
- **Domain errors are messages:** validation and rule violations are
  exceptions with a German user-facing text (`ValidationException`,
  `ResultEntryException`, `TeamEntryException`, …) that controllers turn into
  an `ActionResult` or a note on the page, with status 422.
- **Database details never reach the screen** where no login protects the
  page: the team entry logs PDO errors and shows a general message.
- **No database connection** within `connect_timeout`: no reading or writing,
  an error page instead (R4.4).
- **Derived values fail safe:** they are computed from the raw data on every
  request; a missing or invalid cache is never an error (R35).
- **Missing preconditions are explained, not hidden:** no handicap formula,
  no lane count, too few lane pairs, incomplete previous matchdays before a
  positionday – each gives a message with the reason (R12.13, R18.5, R18.6,
  R31.4).

## Privacy by design

- Only name and gender are stored about players, pseudonyms are fine; a
  removed player's personal data is deleted (R25).
- No external services at runtime (fonts, scripts, styles are local, R4.2,
  R36.8); no tracking, no third-party cookies.

## Patterns in the admin area

- **Tabs via links:** sections of a page (e.g. master data) are selected via
  `?tab=…`; only the selected section is rendered. After a POST action the
  controller derives the tab from the action
  (`MasterDataController::tabForAction()`), so that you stay in the same
  section after saving. Works without JavaScript; on the phone `admin-bar.js`
  only scrolls the active tab into view.
- **Season of the admin area:** the selection below the admin bar (GET
  `?season=`) is kept in the admin session (`AdminView::SESSION_SEASON`,
  closure `$adminSeasonId` in `index.php`), so forms, redirects and links
  without the parameter stay in the chosen season; without a choice the
  current season (`Domain/CurrentSeason`). Public pages carry the season in
  the URL instead and need no session.
- **Prefilling forms after errors:** the route passes `$_POST` (with several
  forms on one page together with an `area` key) to the view.
- **Key figures of a player for a matchday** (AVG, number of games, HDC before
  the matchday including the previous season rule, frozen or computed) always
  come from `PlayerStatsReader` (adapter `HandicapFreezingRepository`). The
  virtual score sheet and the print score sheet both use it, so that they
  show the same values.

## Languages

The user interface is available in German and English (decision of
2026-10-25); code, comments and documentation stay English.

- **Texts** live in catalogs [lang/de.php](../lang/de.php) and
  [lang/en.php](../lang/en.php): key => text, placeholders `{name}`.
  [Translator](../src/I18n/Translator.php) returns the text of a key in the
  request's language; a missing key falls back to German, then to the key
  itself. `CatalogTest` checks that all catalogs have the same keys and
  placeholders. The catalogs describe themselves:
  [Languages](../src/I18n/Languages.php) finds every `lang/<code>.php`
  (two-letter name), its key `language.name` names the language in itself;
  a new language is one new file (see [contribute.md](contribute.md#adding-a-language)).
- **Language of a request**
  ([LanguageNegotiator](../src/I18n/LanguageNegotiator.php)): the visitor's
  choice (cookie `bl_lang`, set by the footer form via `/appearance`), on
  the first visit the browser's `Accept-Language`, otherwise `language` from
  the configuration (default `de`). Responses carry `Content-Language` and
  `Vary: Accept-Language, Cookie`; `<html lang>` follows the language.
- **Dates and numbers** via [Formatter](../src/I18n/Formatter.php); weekday
  and month names, the date pattern and the decimal separator come from the
  catalog.
- **Migration in phases:** 1 basis and page frame, 2 public pages, 3 team
  entry, 4 admin area incl. error messages, 5 print (all done). The printed score
  sheet uses the season's language, not the visitor's (see
  [domain-logic.md](domain-logic.md#r19--print-score-sheet)). Until a text
  is moved to the catalog it stays German. URLs are English (see
  [routes.md](routes.md)); editorial content (posts, rules) stays in one
  language.
- **Messages instead of sentences:** texts for the user that arise below the
  presentation layer are a [Message](../src/Domain/Message.php) (catalog key
  and parameters, no I/O). Exceptions meant for the user implement
  [Translatable](../src/Domain/Translatable.php) via the trait
  [CarriesMessage](../src/Domain/CarriesMessage.php); domain results carry
  `Message`s as notes and warnings (schedule, lane assignment, positionday,
  handicap, import problems). The presentation layer shows them with
  `Translator::message()` / `Translator::error()`: nested messages are
  translated (e.g. the field name of a validation error, `field.*`), a
  parameter `date` (ISO) is formatted. `getMessage()` holds the key with its
  parameters, for logs. Exceptions without a message (operator and
  developer errors) are shown as they are.
- **Views:** the trait [ViewTexts](../src/Presentation/ViewTexts.php) gives
  views and HTML-building controllers `text()` (escaped, optionally with
  markup parameters) and `plain()`; [ValueLabels](../src/Presentation/ValueLabels.php)
  names stored enum values (`value.*`). Admin page titles are passed to
  `$adminPage` as catalog keys. Scripts get their texts from `data-*`
  attributes (e.g. `score-sheet.js`).
- **Data stays language-neutral:** persistence delivers values, not phrases
  (e.g. the lanes "1/2" of a fixture; the view writes "Bahn 1/2" or
  "Lanes 1/2").

## Frontend and design

- Server-rendered HTML, mobile-first CSS in
  [public/assets/css/app.css](../public/assets/css/app.css). nginx lets
  browsers cache assets for an hour; `Layout::versioned()` therefore appends
  the modification time (`app.css?v=…`), so the stylesheet and
  `appearance.js` are reloaded right after an update.
- **Two styles, each light and dark:** "Lane" (clean, signal orange, Space
  Grotesk) and "Bento" (tiles, blue, Plus Jakarta Sans). The
  [Layout](../src/Presentation/Layout.php) sets
  `<html data-style="lane|bento" data-mode="auto|light|dark">` from the
  [Appearance](../src/Presentation/Appearance.php) (cookies `bl_style`,
  `bl_mode`). `auto` follows `prefers-color-scheme`. Default: Lane, auto.
- **All colours, radii and fonts are CSS variables** (blocks at the top of
  `app.css`). Components only use variables; new pages must work in all four
  combinations. Contrast: text at least 4.5:1. `--accent` is a surface colour;
  for text on a light background there is `--accent-text`.
- **Switcher** in the footer: without JavaScript a form
  (POST `/appearance`, sets cookies, 303 back, local targets only), with
  JavaScript effective immediately (`appearance.js`). Below the league name
  an inconspicuous link "Quellcode auf GitHub" points to the upstream
  repository (fixed in `Layout`, also in other installations). External
  assets stay forbidden (R36.8); plain links are fine.
- **Mobile (< 48rem):** main navigation as a fixed bar at the bottom with
  icons. Below 40rem: columns with `.only-wide` disappear, `.table__extra`
  appears below the team name, the score sheet turns into one card per
  player.
- **Fonts served locally** from `public/assets/fonts/` (WOFF2, Latin, SIL OFL,
  licence texts next to them, R36.8).
- Building blocks of the public pages: `.card`, `.page-header`, `.result`
  (result block of a fixture, points via `ScoreSheetCalculator`), `.grid`,
  `.badge`, `.rank`.
- JavaScript only as an enhancement: `tabs.js` (fixture tabs),
  `score-sheet.js` (live calculation in the admin score sheet),
  `appearance.js`, `admin-bar.js`. Without JavaScript everything stays usable.
- **Admin area:** same variables and styles. Building blocks in
  [AdminComponents](../src/Presentation/AdminComponents.php) (page header,
  card, messages, status chip, date) and in the "Adminbereich" section of
  `app.css`: `.tiles`/`.tile`, `.data-list`, `.collapsible`, `.key-figures`,
  `.chips`, `.team-cards`, `.form--grid`, `.grid--work` (list on the left,
  form on the right), `.action-bar` (stays visible at the bottom). On the
  desktop the virtual score sheet is a table; below 40rem the CSS turns each
  row into a player card (`st-*` classes, labels via `data-label`).
  The entry page may grow wider (`.content:has(.js-score-sheet)`, up to
  125rem); the teams sit side by side as soon as each has at least 48rem of
  space (`auto-fit`), otherwise one below the other – never scrolling
  sideways.
- `score-sheet.js` mirrors
  [ScoreSheetCalculator](../src/Presentation/ScoreSheetCalculator.php).
  **Always change calculation rules in both places.**
