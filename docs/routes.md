# Features and Routes

All routes are registered in [public/index.php](../public/index.php). The
URL paths and query parameters are German (visible to users); form field
names are English.

## Public (read-only)

| Route | Content | Classes |
|---|---|---|
| `/` | Home page: link to the next matchday, last matchday with results, league table, CMS posts, season selection | `PublicController::homePage`, `CmsView` |
| `/matchdays?season=` | Two tabs: "Nächster Spieltag" (next matchday not played yet from today on, all fixtures as table "Bahn · Team · Bahn · Team" with each team's starting lane, sorted by lane; explanation on a positionday or lot draw without fixtures, R14.8) and "Alle Spieltage" (list; only played matchdays are clickable). Without a next matchday only the list | `PublicController::matchdayList`, `PublicDataReader::nextMatchday`, `PublicView`, `tabs.js` |
| `/matchday/{id}` | Matchday detail: one tab per fixture (by lane pair), digital score sheet, average lists, matchday table, original score sheet; without public player data (R40) only the result with team sums per game and the matchday table | as above + `ScoreSheetDisplayRepository`, `tabs.js` |
| `/table?season=` | League table; team names link to the team page | `TableAggregator` |
| `/team/{id}` | Team page (team season id): current average list (without public player data, R40, a note), state after the last played matchday; ghost team/unknown → message | `PublicDataReader::teamAverageList`, `AverageListAggregator` |
| `/player/{id}?season=` | Player page (R39): key figures of the season, season selection, handicap and average history as inline SVG with the values per matchday, teams per season; linked from the names in the average lists; unknown player → 404 with message; without public player data (R40) 404 for visitors without login | `Application/PlayerProfile/PlayerProfiles`, `PlayerProfileRepository`, `PlayerProfileView`, `SvgLineChart` |
| `/honours?season=` | Honours from the end date; without public player data (R40) a note | `HonoursAggregator` |
| `/rules` | CMS rules; with a readable HDC formula of the current season a second tab "Handicap-Tabelle" (HDC per whole average, jump link `#rules-panel-handicap`) | `CmsView`, `Domain/Handicap/HandicapTableService` |
| `/score-sheet/{id}` | Uploaded original score sheet (image/PDF) from the score sheet directory; public only for a complete fixture and with public player data (R40), otherwise for admins, `ETag` = SHA-256, `304` on repetition, `500` if the file is missing | `OriginalScoreSheetRepository`, `FileSystemBlobStore` |
| `/entry?code=` | Team page of the guided result entry by the teams (R37): `&step=1–3` own lineup and nets of the game (blind positions filled automatically, standing once both have entered the game), `4` overview with sending/withdrawing, `5` confirmation (checklist, checking and confirming or sending back the opponent's entry; not against the ghost team); without `schritt` the first open game, the overview or – once sent – the confirmation. `&notice=` shows a notice after an action (`TeamEntryController::NOTICES`). `&part=1` returns only the sheet (refresh of the confirmation step by `team-entry.js`). Invalid or expired code → 404 with message. No login; `Referrer-Policy: no-referrer`, `Cache-Control: no-store` | `TeamEntryController::page`/`sheet`, `TeamEntryView`, `team-entry.js` |
| `/entry/{speichern,abschicken,zurueckziehen,bestaetigen,zurueckgeben}` (POST) | Save one game (`code`, `spiel`, `lineup[player]` = `plays`/`blind`/empty, `nets[player]`, `nach` = next step; forward checks the game), send the own entry, withdraw it, confirm the opponent's entry, send it back (`note`). Success → 303 to the next step, error → the step with 422. The code is the authorisation (no CSRF token, no session; cross-site requests via `Sec-Fetch-Site` are redirected) | `TeamEntryController::action`, `Application/TeamEntry/TeamResultEntry` |
| `/appearance` (POST) | Choose style and colour mode (`style`, `mode`, cookies), 303 back to the page (`return_to`) | closure + `Appearance` |

Without `?season=` the current season is shown: the running one, in the summer break the one that ended last, before the first season the next one (R28.18, `Domain/CurrentSeason`). Every navigation item and the logo keep a chosen season; the matchday and team pages use their own season. Links carry `?season=` only for another than the current season, so bookmarks follow the current season.

## Admin area (login required)

After login every admin page shows an **admin bar** below the header with
all areas and "Abmelden" (log out). The area of the current page is
highlighted. The entries come from `AdminView::barEntries()`, rendering is
done by `Layout`. On the phone the bar can be swiped sideways (`admin-bar.js`
scrolls the active area into view).

| Route | Method | Function | Controller |
|---|---|---|---|
| `/admin/login`, `/admin/logout` | GET/POST | Login step 1 (`username`, `password`; with active 2FA 303 to `/admin/login/code`), logout | closures + `AuthModule` |
| `/admin/login/code` | GET/POST | Login step 2: code from the app (`totp`), `cancel=1`; only reachable while the time limit (5 min) after a successful password check is running | `AuthModule::confirmSecondFactor` |
| `/admin` | GET | Overview with links | `AdminView` |
| `/admin/master-data?tab=` | GET | Tabs players, seasons, teams (with roster), matchdays, admin accounts, import; after an action its tab stays selected | `MasterDataController` |
| `/admin/master-data/player?id=&season=` | GET | Player page in the admin area, same content as `/player/{id}`; linked from the player list and the rosters; without `season` the admin's season | `PlayerProfiles`, `PlayerProfileView` |
| `/admin/master-data/import-preview` | POST (upload) | Player import: check the CSV and show it as a preview, save nothing | `PlayerImportController`, `Application/Import/PlayerImport` |
| `/admin/master-data/import-template` | GET | Download the template CSV of the player import in the current language (column names, separator, example rows) | `PlayerImportController::template`, `PlayerImportView::template` |
| `/admin/master-data/import` | POST | Save the confirmed import in one transaction (teams, players, rosters, previous season records), then tab "Teams" | as above |
| `/admin/master-data/{action}` | POST | `spieler-anlegen/-entfernen`, `saison-anlegen/-entfernen`, `team-anlegen/-entfernen`, `spieltag-anlegen`, `konto-anlegen/-entfernen`, `kader-zuordnen/-entfernen` (create/remove player, season, team, account; create matchday; assign/remove roster) | as above |
| `/admin/season` | GET | Selected season: master data, configuration (display only after the season start, R12.7), card "Spieltage" (cancel, take back, postpone), positiondays with fixtures; `?postpone={matchday id}&date=` shows the preview of a postponement old → new without saving (R8.21) | `SeasonManagementController` |
| `/admin/season/{stammdaten,konfiguration,positionday}` | POST | Change master data (any time), change configuration (until the season start; empty handicap factor = no handicap), form positionday fixtures from the standings (as soon as all previous matchdays are played; locked once results are entered) | as above + `SeasonAdministration`, `PositiondayManagement` |
| `/admin/season/{cancel,reinstate}` | POST | Cancel a whole matchday without entered results, or take the cancellation back (`matchday_id`, R8.20) | `SeasonManagementController`, `Application/Schedule/MatchdayRescheduling` |
| `/admin/season/postpone` | POST | Postpone a matchday not complete to a later `date`; all later matchdays and the season end move along (R8.21) | as above |
| `/admin/season/lot-draw` | POST | Enter or change the drawn fixtures of a lot draw (without lane, only while no results are entered) | `SeasonManagementController`, `Application/LotDraw/LotDrawManagement` |
| `/admin/season-planning` | GET | Overview of the saved plan | `SeasonPlanningController` |
| `/admin/season-planning/preview` | GET | Plan and lane preview with fingerprint; `types[n]=regular\|positionday\|lot_draw` sets the type per matchday ("Neu berechnen", recalculate) | as above |
| `/admin/season-planning/apply` | POST | Save the plan (`fingerprint` must match the preview; `confirmed=1` required if the preview carries warnings, e.g. uneven distribution, R9.12/R18.9) | as above |
| `/admin/season-planning/matchday` | POST | Correct the date of a matchday before the season start (the type stays; it is chosen in the preview) | as above + `MatchdayManagement` |
| `/admin/results` | GET | Overview of all fixtures with status | `ResultEntryController` |
| `/admin/results/score-sheet?fixture=` | GET | Virtual score sheet in the paper layout; below it the card "Verlauf" (history of the fixture, admin only, R37.11) | as above, `ResultEntryView`, `score-sheet.js` |
| `/admin/results/save` | POST | `action=draft` or `confirm`; fields `net[home\|away][player id][game]`, `blind[home\|away][player id][game]` (per single game, R27.5; also for a player who serves as blind player); rules in `TeamSheetRules` (at most 4 per game, no re-entry) and `BlindPlayerOrder` (order of the blind players); for a complete fixture optionally `recalculate_chain=1` → 303 to the chain preview | as above + `ResultRecording` |
| `/admin/results/handicap-chain?fixture=` | GET | Preview of the handicap chain recalculation from the matchday of the fixture (old → new), changes nothing | as above + `ResultRecording::previewHandicapChain` |
| `/admin/results/handicap-chain` | POST | Apply after confirmation (`confirmed=1`, `fingerprint`); an outdated preview is rejected | as above + `ResultRecording::applyHandicapChain` |
| `/admin/results/team-entry` | POST | Switch the result entry by the teams on/off for a season (`season_id`, `enabled=1`); the card with the switch and the running entries is below the results overview | `TeamEntryController::adminAction`, `adminSeasonCard` |
| `/admin/results/team-entry-take-over` | POST | Take the values entered by the teams over into the virtual score sheet as a draft and end the team entry (`fixture_id`); the card with state, links and QR codes of both teams is below the virtual score sheet | `TeamEntryController::adminFixtureCard`, `TeamResultEntry::takeOver` |
| `/admin/results/team-entry-codes` | POST | New codes for a fixture; printed QR codes become invalid | `TeamResultEntry::renewCodes` |
| `/admin/results/remove` | POST | Remove all results of a fixture | as above |
| `/admin/results/upload` | POST | Upload the original score sheet as evidence (no OCR) | as above + `OriginalScoreSheetStorage` |
| `/admin/print` | GET | Choose the matchday and the language of the sheets; the next unplayed matchday and the season's language are preselected | `PrintController::selection` |
| `/admin/print?matchday=` (`&language=`) | GET | Score sheets of all fixtures of the matchday as one PDF, one fixture per page, sorted by lane (PDF via mPDF, otherwise HTML). Errors (no fixtures, missing lane, teams open) show the selection with a notice | `PrintController::generateForMatchday` |
| `/admin/print?fixture=` (`&language=`) | GET | Single print score sheet of one fixture; without `language` in the season's language | `PrintController::generate` |
| `/admin/honours?season=` | GET | Honours preview before the end of the season | `HonoursPreviewController` |
| `/admin/account` | GET | My account: status of the two-factor login, setup in progress (QR code, key, app link), change password, log out all other sessions | `AccountController`, `AccountView`, `QrCode` |
| `/admin/account/{action}` | POST | `totp-starten` (create a secret, keep it only encrypted in the session), `totp-aktivieren` (`code`), `totp-abbrechen`, `totp-deaktivieren` (`password`, `code`), `passwort` (`current`, `new`, `repeat`), `sitzungen-beenden` (all other sessions of the account end, this one stays logged in) | as above + `AdminAccountManagement` |
| `/admin/home-page` (`?edit=`) | GET | Maintain home page posts | `CmsEditController` |
| `/admin/home-page/{anlegen,aendern,entfernen}` | POST | create, change, remove a post | as above |
| `/admin/rules`, `/admin/rules/save` | GET/POST | Maintain the rules | as above |
| `/admin/league` (`?saved=1`), `/admin/league/save` | GET/POST | League name and public player data of the installation (R38, R40); saving redirects to the form (303), so that the header already shows the new name | `LeagueSettingsController`, `LeagueSettingsView` |

Course of a season in the admin area: master data (season → teams →
players → rosters) → season planning (preview → apply) → per matchday print
the score sheets of all fixtures → result entry (net values per roster
player → confirm; or, with the team entry switched on, by the teams via
the QR codes on the sheets) → upload of the original as evidence.

## Functions without a UI

These use cases are implemented and tested but not reachable via a route.
Until they get a UI they can only be changed directly in the database:

| Function | Use case | Consequence without a UI |
|---|---|---|
| Edit previous season records | `PreviousSeasonRecordManagement` | Only via the player import (columns previous average/previous games); changing a single record is not possible yet |
| OCR of the score sheets | `Domain/Ocr/OcrModule`, `Ocr/TesseractTextRecognizer` | Deferred: handwriting cannot be recognised |
