# Domain Rules

This is the authoritative description of the league's rules and of the
website's behaviour, together with where each rule is implemented. The code
refers to these rules by number: `(R12.9)` means rule 9 of section R12. The
numbers are stable; a rule that no longer applies keeps its number and is
marked as **deferred** or **superseded**, so that older references stay
traceable. Decisions and their dates are recorded in the commit history;
German UI terms and their English names are listed in the
[glossary](glossary.md). German labels are quoted as they appear on the
website.

A rule without a number in brackets belongs to the section as a whole.

## Contents

| Area | Sections |
|---|---|
| Public pages | [R1](#r1--results-of-a-fixture) · [R2](#r2--average-list-of-a-team) · [R14](#r14--season-selection-and-matchday-list) · [R15](#r15--matchday-detail-view) · [R16](#r16--league-table-and-day-table) · [R28](#r28--navigation-and-season-choice) · [R33](#r33--home-page-posts) · [R34](#r34--rules-page) · [R36](#r36--mobile-use) · [R39](#r39--player-page) · [R40](#r40--public-player-data) · [R41](#r41--team-page) |
| League data | [R6](#r6--players) · [R7](#r7--teams) · [R8](#r8--seasons-matchdays-and-season-configuration) · [R25](#r25--data-minimisation) · [R29](#r29--previous-season-records) · [R38](#r38--league-name) · [R40](#r40--public-player-data) |
| Season planning | [R9](#r9--season-planning) · [R18](#r18--lane-assignment) · [R31](#r31--positionday) · [R32](#r32--lot-draw) |
| Scoring | [R11](#r11--player-average) · [R12](#r12--handicap) · [R13](#r13--honours) · [R17](#r17--points) · [R20](#r20--ghost-team) · [R21](#r21--roster-and-lineup) · [R24](#r24--played-matchday) · [R27](#r27--blind-players) |
| Result entry | [R3](#r3--original-score-sheets-ocr-deferred) · [R19](#r19--print-score-sheet) · [R22](#r22--manual-result-entry) · [R30](#r30--result-entry-overview-and-virtual-score-sheet) · [R37](#r37--result-entry-by-the-teams) · [R10](#r10--online-documentation-superseded) |
| Operation and security | [R4](#r4--persistence) · [R5](#r5--access-and-sessions) · [R23](#r23--admin-accounts-and-two-factor-login) · [R26](#r26--privacy-policy-and-legal-notice-open) · [R35](#r35--raw-data-and-derived-values) |

## Basic terms

- A **season** has several **matchdays**; on a matchday the teams play in
  **fixtures** of exactly two teams. Each team plays at most one fixture per
  matchday.
- A **match evening** of a team: 3 single games, in each game exactly 4
  positions. A **series** is a player's 3 games of one evening.
- **Net** is the pins scored, **gross** = net + handicap (HDC).
- **Raw data** is the only source of truth; everything else is derived
  (see [R35](#r35--raw-data-and-derived-values)).

---

## R1 – Results of a fixture

- Selecting a matchday shows each fixture with both teams and the result of
  each team (R1.1, R1.2); target: within 3 seconds.
- A matchday without results is marked "ohne Ergebnisse" and shows no values
  (R1.3); an unknown matchday shows a message instead of values (R1.5).
- Everything public is readable without login (R1.4); player data only if the
  league makes it public ([R40](#r40--public-player-data)).
- Implementation: `PublicController`, `PublicView`, `PublicDataReader`.

## R2 – Average list of a team

- The detail view of a played matchday shows an average list for both teams
  of each fixture, with the state up to and including this matchday (R2.1,
  R2.5).
- Per player: gender (R2.7), HDC frozen for this matchday – for a player who
  missed it the last one frozen before it in the season, since a missed
  matchday does not change it; empty if the player has none (R2.8, R2.15) –;
  the gross values are net + this HDC; games played (R2.9), pins net and gross
  (R2.10), average net and gross with two decimals (R2.2, R2.11), best series
  net and gross (R2.12), best single game net and gross (R2.13). Sorted by
  average net, descending (R2.3). A total row sums games and pins and gives
  the team averages (R2.14).
- A team without games yet shows a message and an empty list (R2.4); a
  loading error shows a message instead of incomplete values (R2.6).
- Clicking a team in the league table shows its team page
  ([R41](#r41--team-page)) with the current average list (`/team/{id}`, state
  after the last played matchday).
- Implementation: `Application/Aggregation/AverageListAggregator`,
  `AverageListRawDataRepository`.

## R3 – Original score sheets (OCR deferred)

- **Upload:** JPEG, PNG or PDF up to 10 MB; the file type is determined from
  the content; anything else is rejected with the reason (R3.1, R3.2). On a
  phone the camera can be used directly (R36.6).
- **Storage:** the original is kept unchanged (photos as prepared, see below)
  and permanently as a file,
  content-addressed by its SHA-256 (also its checksum in the database); the
  same content is stored only once (R3.15). It belongs to its fixture (R3.16)
  and is public in the detail view only once the fixture is complete. It is
  kept regardless of how the results were entered (R3.17).
- **Photos** (JPEG, PNG) are prepared on upload: turned upright by their
  EXIF orientation, scaled down to at most 2000 pixels on the longer edge and
  stored as JPEG (quality 85). Re-encoding drops all metadata, so location,
  device and time of capture do not become public, and keeps the files
  small. Photos above 50 megapixels or that cannot be decoded are rejected.
  PDFs stay unchanged. Score sheets stored before this rule stay as they
  are (R3.20, `Domain/ScoreSheet/ScoreSheetPhoto`).
- **Only the sheet is stored:** a photo is cropped in the browser before
  the upload. Four corner handles are placed on the corners of the sheet (with
  a magnifier while dragging, a button to rotate by 90°), and the marked area
  is rectified to A4, landscape or portrait by its shape. The uncropped photo
  is not kept. Without JavaScript photos are rejected; PDFs (e.g. from a scan
  app) need no cropping (R3.21, `public/assets/js/sheet-crop.js`,
  `ResultEntryController::saveOriginal` checks the field `cropped`). Planned:
  corner marks on the printed score sheet to propose the corners
  automatically, and an upload by the teams with one place per fixture.
- **Deleting a fixture** deletes its score sheets and their files, unless
  another record uses the same content (R3.19).
- **Deferred – OCR (decision of 2026-10-12):** recognising the handwritten
  results, the order digits and the blind mark "X" (R3.3–R3.14, R3.18) does
  not work with the local Tesseract. The OCR classes (`src/Ocr`,
  `Domain/Ocr`) stay in the code but are not wired; results are entered in
  the virtual score sheet ([R30](#r30--result-entry-overview-and-virtual-score-sheet)).
  The Tesseract wrapper `thiagoalessio/tesseract_ocr` is only suggested in
  `composer.json`; re-enabling OCR means `composer require` it again.
- Implementation and storage details: [architecture.md](architecture.md#score-sheet-files).

## R4 – Persistence

- All data lives in a local MariaDB database on the same server; the
  application needs no external or cloud service (R4.1, R4.2).
- A failing write is rolled back completely and reported (R4.3); see
  [architecture.md](architecture.md#transactions).
- If the database cannot be reached within the connection timeout
  (`connect_timeout`), reading and writing are refused with a message (R4.4).
- Data is returned unchanged as it was written (R4.5).

## R5 – Access and sessions

- Writing requires an admin login; without it nothing is changed and the
  login form is shown (R5.1). Reading is public (R5.4). Exception: the result
  entry by the teams with the code of its fixture ([R37](#r37--result-entry-by-the-teams)).
- A session ends after 30 minutes without activity (R5.2, R5.7) and at the
  latest 8 hours after login (R5.8); logging out ends it (R5.5).
- A failed login does not reveal whether the username or the password was
  wrong (R5.3). 5 failed attempts in a row lock the account for 15 minutes
  (R5.6), but only for devices that are not trusted (R5.10).
- Changing the password, setting up or switching off two-factor login, or
  "Alle anderen Sitzungen abmelden" end all other sessions of the account
  and forget its other trusted devices; the own session stays and its
  browser stays trusted. A lock does not end sessions (R5.9).
- A browser in which an account logged in completely (password and, if
  active, code) is a trusted device of that account for 90 days; every
  complete login renews it. The account lock does not apply to it, so
  whoever knows a username can lock out unknown devices but not the owner.
  Failed attempts from a trusted device count only for that device; after 5
  it is forgotten. At most 10 trusted devices per account, the most recently
  used ones; one browser can be trusted for several accounts. The rule
  applies to every kind of account, not only to admins (R5.10).
- Implementation: `Security/AuthModule`, details in
  [architecture.md](architecture.md#security).

## R6 – Players

- A player has a name (1–100 characters) and a gender ("männlich" or
  "weiblich"); both are required (R6.1–R6.3). Players can be edited (R6.4).
- Removing a player removes all their roster memberships (R6.5).
- A player is assigned to a team's roster per season (R6.6); a second
  assignment to the same roster is rejected (R6.7). A player belongs to at
  most one roster per season – no change of team within a season (R6.8).
- **CSV import** (Stammdaten → Import): columns team, name, gender
  (required) and previous average, previous games (optional, but only
  together; stored as a previous season record,
  [R29](#r29--previous-season-records)). Column names and gender values are
  recognised in every language of the website, also mixed in one file, in
  any order and case (catalog keys `import.column.*` and `import.gender.*`,
  `ImportVocabulary`, `CatalogImportVocabulary`); a word may not mean two
  things in different languages. The import tab names the columns and offers
  a template CSV in the current language. Duplicate names in the file or
  existing players abort the import; preview first, then one transaction
  (`Application/Import/*`).

## R7 – Teams

- A team exists across seasons; its name (1–100 characters) is set per
  season and must be unique within the season (R7.1–R7.3). Renaming keeps the
  team's identity (R7.4).
- A team can be removed from a season only before the season has started
  (R7.6, R7.7); removing an unknown team changes nothing (R7.5).

## R8 – Seasons, matchdays and season configuration

- A season has a name (1–100 characters), a start and an end date after the
  start (R8.1, R8.2). Its length is set either by the end date or by a number
  of matchdays (R8.17).
- A matchday has a number unique within the season and a date within the
  season (R8.3, R8.4); valid changes are saved (R8.5). Each fixture consists
  of two different teams; positionday fixtures are fixed only later (R8.6).
- A new season takes over the teams of the previous season with their names
  and rosters – not the ghost team, and with the same team id (R8.7). Before
  the start, teams can be removed, renamed and their rosters changed (R8.8).
- A season can be deleted only before its start (R8.9, R8.10).
- **Season configuration** (editable until the season starts, prefilled from
  the previous season):
  - number of lanes: even, at least 2 (R8.11, R8.12),
  - entry fee ("Startgeld") and blind player fee: amounts ≥ 0.00 (R8.13–R8.16),
  - target score, maximum handicap and handicap formula ([R12](#r12--handicap)),
  - ghost score ([R20](#r20--ghost-team)),
  - "Vorjahr im Schnitt bis Spieltag" ([R11](#r11--player-average)),
  - positionday interval ([R31](#r31--positionday)),
  - game points and bonus points scale ([R17](#r17--points)),
  - team entry on/off ([R37](#r37--result-entry-by-the-teams)).
- Every matchday has a type: regular, positionday or lot draw (R8.18); a lot
  draw is set by the admin before the season starts (R8.19).
- **Cancel a matchday** (decision of 2026-11-08): the league management can
  cancel a whole matchday as long as no results are entered for it, not even
  a draft. It keeps its number and its date, its fixtures are dropped without
  replacement and the other dates stay. It takes no results, is not printed,
  not open to team entry and missing from the result entry overview; it does
  not block the order of the result entry ([R30.20](#r30--result-entry-overview-and-virtual-score-sheet))
  or the forming of a positionday ([R31](#r31--positionday)). In public it is
  listed as "abgesagt" and skipped as next matchday. Rules counted by matchday
  number keep counting it ("Vorjahr im Schnitt bis Spieltag", positionday
  interval). The cancellation can be taken back only while the matchday lies
  in the future and no later matchday has results; otherwise an incomplete
  matchday would suddenly lie before entered ones and undermine the order of
  the result entry (R8.20).
- **Postpone a matchday** (decision of 2026-11-08): only a whole matchday that
  is not complete and not cancelled, to a later date chosen by the league
  management (suggestion: one week later). The matchday and all later ones
  (cancelled ones too) move by the same number of days, and so does the
  season end. A preview shows old → new before anything is saved. Codes of
  the team entry follow the new date (R8.21).
- Implementation: `SeasonAdministration`, `SeasonConfiguration`,
  `SeasonManagementController` (admin area "Saison", card "Spieltage"),
  `Application/Schedule/MatchdayRescheduling`, `MatchdayScheduleRepository`.

## R9 – Season planning

- **Round robin:** every pair of teams meets equally often (R9.1); no team
  plays twice on a matchday (R9.2). Over a long season the round robin is
  repeated (R9.11).
- The plan with its fixtures and lane assignment is saved in one transaction
  (R9.3, R9.9); on an error nothing changes (R9.4). With fewer than 2 teams no
  plan is created (R9.5).
- **Ghost team:** with an odd number of teams a ghost team is added (R9.6),
  with an even number not (R9.8); every real team plays it equally often
  (R9.7).
- **Length and dates:** by end date or number of matchdays (R9.10); weekly
  dates from the season start.
- If the length does not allow an even distribution, there is a warning and
  the plan is taken over only after explicit confirmation (R9.12).
- **Preview:** before the season starts the admin sees the plan with lanes
  and positiondays and sets the type of every matchday (regular, positionday,
  lot draw), then recalculates (R9.13, R9.14). Special days get no fixtures
  in advance and are skipped by the round robin, so every pairing still
  occurs equally often. In the saved plan only dates can be corrected; the
  whole plan can be replaced as long as the season has not started and no
  results exist.
- Implementation: `Domain/SeasonPlanner` (`generateSchedule(…, $types)`,
  `Matchday::fromNumber`, `Matchday::isSpecialDay`),
  `Application/SeasonPlanning/SeasonPlanningManagement`.

## R10 – Online documentation (superseded)

- Planned originally: players document their games online after the
  opponent agreed (consent pending/given/refused/expired after 48 hours).
- **Superseded by [R37](#r37--result-entry-by-the-teams)** (result entry by
  the teams with confirmation by the opponent). The generic extension
  interface with consent states was not built.

## R11 – Player average

- The average uses only the net results of games the player actually played
  themselves; blind games and ghost team results do not count (R11.1, R20.4,
  R27.13).
- It is the arithmetic mean over these games (R11.2). Internally it is used
  exactly; it is displayed with two decimals so that the HDC can be checked
  by hand.
- **Previous season:** the average after matchday k includes the player's
  previous season record of this season ([R29](#r29--previous-season-records))
  – or, without one, their games of the preceding season – as long as k is at
  most the season setting "Vorjahr im Schnitt bis Spieltag" (default 5,
  0 = never) (R11.3, R11.4). Afterwards only the current season counts
  (R11.5); without a previous season the current one alone (R11.6).
- Without any countable game the player has no average ("ohne
  Durchschnitt") (R11.7).
- Implementation: `Domain/Average/AverageModule`
  (`calculateExactAverage`, `includesPreviousSeason`).

## R12 – Handicap

- **Formula per season** (R12.1); default: HDC = (target score − average) ×
  0.8. Target score 0–300, default 200 (R12.2); maximum handicap 0–300,
  default 64 (R12.3), prefilled from the previous season (R12.4, R12.5) and
  changeable only before the season starts (R12.6, R12.7).
- **Rounding:** the formula uses the exact average; only the result is rounded
  half up to a whole number (R12.8). The result is limited to 0 … maximum
  (R12.10, R12.15).
- **Basis:** the average immediately before the matchday, i.e. after the
  previous matchday (R12.8). With the default previous-season limit of 5 the
  previous season therefore still counts for the HDC of matchday 6.
- **First matchday of a new player** (no games and no previous season
  record): the HDC follows from the average of the games of this matchday
  (R12.9).
- **Gross** = net + HDC of the player who played the game (R12.11).
- **Freezing:** when a fixture is confirmed, the HDC of every player of the
  matchday is stored for this matchday and used unchanged for its gross,
  even if the average changes later (R12.12). Frozen handicaps are raw data
  ([R35](#r35--raw-data-and-derived-values)).
- **Without HDC:** without a formula no HDC and no gross are calculated and a
  message is shown (R12.13); without an average (outside the first-matchday
  case) or with an invalid formula result the player is "ohne Handicap"
  (R12.14, R12.16).
- **Corrections:** correcting a past matchday never changes frozen handicaps
  by itself (R12.17). The admin can request a recalculation of the
  **handicap chain** from the corrected matchday: the system recalculates all
  frozen handicaps from there in chronological order, shows a preview old →
  new, and saves only after explicit confirmation (R12.18); without it nothing
  changes (R12.19). Reachable only from the score sheet of a complete fixture
  (checkbox "Handicap-Kette … neu berechnen"); an outdated preview is
  rejected via its fingerprint.
- **Handicap table** on the rules page: HDC per whole average, computed with
  the same module; exact only for whole averages.
- Implementation: `Domain/Handicap/HandicapModule`, `HandicapFreezeService`,
  `HandicapChainRecalculation`;
  `Persistence/ResultEntry/HandicapFreezingRepository` (average before the
  matchday, `averageCounter`); `HandicapTableService`.

## R13 – Honours

- Categories "Höchste Serie" and "Höchstes Spiel", each net and gross, places
  1–3, separately for women and men (R13.1).
- A **series** counts only if a real player played exactly 3 games
  themselves on one evening; its value is the sum of these games (R13.2).
  Gross = net + HDC (R13.3). Blind and ghost team results never count
  (R13.4).
- **Ties** share the place; the following places are skipped (R13.5). With
  fewer than 3 results the remaining places stay empty (R13.6). Every game
  and every series counts on its own, so a player can hold several places;
  if the same player reached the same value several times, they appear on
  that place only once.
- Honours can be calculated at any time (R13.7) but are **public only after
  the season's end date** (R13.8, R13.10); before that only admins see them
  as a preview (R13.9, R28.14).
- Implementation: `Domain/Honours`, `HonoursAggregator`,
  `HonoursPreviewController`.

## R14 – Season selection and matchday list

- For the chosen season all matchdays are listed, future ones included, in
  chronological order with date and fixtures (R14.1–R14.3).
- A played matchday links to its detail view (R14.4); an unplayed one is not
  clickable and explains this in a tooltip (R14.5, R14.6). A season without
  matchdays shows a message (R14.7).
- **Next matchday:** the matchday page opens with the tab "Nächster
  Spieltag" – date, all fixtures and their lane pairs, sorted by lane –,
  followed by "Alle Spieltage"; the home page links to it. If the fixtures
  are not fixed yet (positionday before the previous matchday, lot draw), the
  page says why (R14.8).
- Implementation: `PublicDataReader::nextMatchday`,
  `PublicView::renderMatchdayList`.

## R15 – Matchday detail view

- A played matchday shows the average lists of its teams, the day table and
  the original score sheets (R15.1), public and without login (R15.2);
  without public player data only the team values ([R40](#r40--public-player-data)).
- The **day table** has the structure of the league table, limited to this
  matchday, with rank and total row (R15.3).
- Without an original score sheet a note says so (R15.4); an unplayed
  matchday shows a message instead of the view (R15.5).
- **The original score sheet opens on the page:** a photo in an overlay with
  a close button; Escape, a click next to it and the back button or swipe of
  the phone close it, a click on the photo switches to its original size. A
  PDF opens in a new window, which the link says. Without JavaScript the link
  opens the file itself. The same applies in the admin area (R15.6,
  `Presentation/Lightbox`, `lightbox.js`).

## R16 – League table and day table

- Per team: points, pins net and gross, average net and gross, games played
  (R16.1), computed from all played matchdays (R16.6); without any, all teams
  with zeros (R16.7).
- **Order:** points, then gross pins, then net pins (R16.2–R16.4); teams
  equal in all three share the rank and the following ranks are skipped
  (R16.5). A rank column starts at 1 (R16.8); a total row sums pins and games
  and gives the overall averages (R16.9).
- The **ghost team** has its own row, sorted like any team and included in
  the total row; net and gross are equal since it has no HDC. The points of
  real teams against it count normally (R16.10, R16.11).
- Implementation: `Application/Aggregation/TableAggregator`,
  `TableRawDataRepository`.

## R17 – Points

- **Game points** per single game: the team gross of the 4 positions
  (including blind positions and ghost players) is compared with the
  opponent's; win/draw/loss give the configured game points, default 2/1/0,
  each 0–10 with win ≥ draw ≥ loss (R17.1–R17.3). Maximum per evening: 3 × win (R17.4).
- **Bonus points** by the team's gross of the evening (sum of the three
  games, R17.5): up to 2100 → 1 (R17.6); from 2101 → 2 plus one per further
  full 100 pins (R17.7), i.e. 2101–2200 → 2, … 2401–2500 → 5; from 2501 at
  most 6 (R17.8). These are the defaults of the scale: threshold for 1 point
  (0–9999), pins per further point (1–1000) and maximum (0–20, 0 = no bonus
  points) are configurable.
- **Total points** = game points + bonus points; maximum 3 × win + bonus
  maximum (R17.9). The ghost team gets points by the same rules (R17.10).
- **Per season** (R17.11): game points and bonus scale belong to the season
  configuration ([R8](#r8--seasons-matchdays-and-season-configuration)), a
  new season takes them over from the previous one, and they can only be
  changed before the season starts. Points are always computed with the
  rules of the fixture's season – in the league and day table, on the
  virtual and the public score sheet, in the team entry and in the bonus
  legend of the printed score sheet (lower half of the levels under the home
  team, upper half under the away team).
- Implementation: `Domain/Points/PointsService`, `BonusPointsScale`,
  `PointsRules`, `Application/SeasonConfiguration::pointsRules()`; on the score sheet
  `Presentation/ScoreSheetCalculator` and `public/assets/js/score-sheet.js`
  (always change both); standings `TableAggregator`.

## R18 – Lane assignment

- Every fixture of a matchday gets exactly one lane pair (two neighbouring
  lanes) (R18.1); a lane pair carries at most one fixture per matchday
  (R18.3). A fixture against the ghost team occupies a pair like any other
  (R18.8).
- **Even distribution:** if possible, the number of matchdays a team plays
  on each lane pair differs by at most one (R18.2). If not, the assignment
  with the smallest maximum spread is chosen and a warning lists the usage
  per team and lane pair (R18.9, R18.10). Example where spread 1 is
  impossible: 4 teams on 2 lane pairs over 40 matchdays.
- Fixtures fixed later (positionday) are assigned as soon as they are fixed
  (R18.7). Without a lane count or with too few lane pairs nothing is
  assigned and an error is shown (R18.5, R18.6). The assignment is saved
  with the plan (R18.4).
- **Algorithm:** a deterministic heuristic (several starting distributions,
  local improvement on the sum of squared usages, reproducible random
  improvement with a fixed seed), then an exact depth-first search with a
  node budget per level. "nicht erreichbar" is shown only where the exact
  search proved it. (`Domain/Lane/LaneAssignmentService`)

## R19 – Print score sheet

- The admin prints a matchday as one PDF (A4), one fixture per page, sorted
  by lane (R19.4).
- Header: lane numbers – none for a lot draw (R19.1) –, date, entry fee and
  blind player fee (R19.7). Per team: team name and every roster player with
  fields for the games (R19.2), AVG, number of games G and HDC as of
  **before** the matchday – the frozen HDC or the one that would be frozen
  (R19.3); empty if the player has none (R19.6).
- Regular matchdays and positiondays without a lane assignment cannot be
  printed; a lot draw is printed without lanes (R19.5).
- The ghost team is printed prefilled: 4 ghost players, ghost score per game,
  HDC 0. With team entry switched on, every team's half carries its QR code
  ([R37.2](#r37--result-entry-by-the-teams)).
- Layout after the league's paper sheet: both teams with the same number of
  rows (the larger roster, at least 7, at most 9), evaluation fields below
  the games, bonus point legend.
- Language: the sheet is read by the players, so it is printed in the
  **season's language** (`season.score_sheet_language`; a new season takes
  over the previous one's, without one the installation's default), not in
  the administrator's. The print form is preselected with it and can choose
  another language for a single print. Team and player names stay as stored.
- Implementation: `Domain/Printing/PrintScoreSheetService` (mPDF, texts via
  `PrintTexts`), `PrintController`, `PrintScoreSheetData`.

## R20 – Ghost team

- Every ghost player scores the season's **ghost score** in every game
  (default 190, independent of the target score) (R20.1); gross = net, no HDC
  (R20.2). The ghost team plays with exactly 4 ghost players (R21.7).
- The ghost team gets game and bonus points by the normal rules and can win
  (R20.3, R20.7); it has its own row in the league and day table (R20.8).
- Its results are excluded only from player averages, handicaps and honours
  (R20.4, R20.5, R20.9); the real opponent's games count normally for its
  players (R20.6).
- Implementation: `Domain/GhostTeam/GhostTeamService`; the virtual score
  sheet and the team entry add the ghost results automatically.

## R21 – Roster and lineup

- A team's roster per season can have any number of players (R21.1).
- In every single game exactly 4 positions play, at most 4 real players at
  the same time (R21.2). Players are substituted only between games, never
  during one, and a substituted player does not come back that evening; so 5
  or 6 players can take part in an evening. Every game counts with the HDC
  of the player who played it. The lineup follows from the entered results;
  there is no separate lineup step (`LineupManagement` is kept but not
  wired).
- Missing positions are filled by blind players ([R27](#r27--blind-players))
  (R21.3). More than 4 results in a game or a player outside the roster are
  rejected (R21.4, R21.5).
- A team without a single real player loses the evening with 0 points and
  gets no blind players (R21.6, R27.3).
- Implementation: `Application/ResultEntry/TeamSheetRules`.

## R22 – Manual result entry

- The admin enters the net results per player and single game in the
  virtual score sheet ([R30](#r30--result-entry-overview-and-virtual-score-sheet))
  (R22.1); every net is a whole number 0–300, anything else is rejected
  (R22.4, R22.5).
- **Blind games** are entered in the player's own row with the "Blind" box
  per game. The value entered is stored (R22.2, R22.3, R27.10); the derived
  reference ([R27](#r27--blind-players)) is shown next to it, prefilled when
  the box is ticked, and a deviation is highlighted.
- A change saves the new nets as raw data and invalidates the derived values;
  frozen handicaps stay unless the chain is recalculated (R22.6). Confirming
  saves and reports success (R22.7).
- Every changed value is logged in the fixture's history
  ([R37.11](#r37--result-entry-by-the-teams)).
- Implementation: `Application/ResultEntry/ResultRecording`,
  `ResultEntryController`.

## R23 – Admin accounts and two-factor login

- Several admin accounts with unique usernames (R23.1, R23.2); the last one
  cannot be deleted (R23.4, R23.5). Passwords are stored only salted and
  hashed (Argon2id, fallback bcrypt) and have a minimum length everywhere
  (R23.3).
- **Two-factor login (TOTP, RFC 6238)** is set up under "Mein Konto": QR code
  plus key; it becomes active only after a valid code from the app (R23.6).
  Login is two-step: password first, then the code on its own page within 5
  minutes (R23.7). A wrong code counts as a failed attempt, a missing one
  does not (R23.8); every code is valid only once. Switching 2FA off needs
  password and code; there is no reset of other accounts' 2FA.
- The TOTP secret is stored encrypted (libsodium, key `totp_key` in the
  configuration) and shown only during setup (R23.9).
- Implementation: `Security/AuthModule`, `AdminAccountManagement`,
  `/admin/account`.

## R24 – Played matchday

- A matchday counts as **played** only once **all** its fixtures are complete
  (R24.1). Drafts or partial results never make it played, even after its
  date (R24.2).
- Tables, the clickable matchday list and honours use only played matchdays
  (R24.3).
- If a fixture of a played matchday falls back to a draft or its results are
  removed, the matchday is no longer played (R24.4).
- Implementation: `ResultRecording` with `FixtureStatusStore::areAllComplete()`.

## R25 – Data minimisation

- Only name and gender are stored about a player (R25.1, R25.2); the name may
  be a pseudonym (R25.4). Removing a player permanently deletes their personal
  data (R25.3).

## R26 – Privacy policy and legal notice (open)

- Optional: privacy policy and legal notice as public pages, linked from
  every page and editable by the admin (R26.1–R26.4). The tables
  (`privacy_policy`, `legal_notice`) exist; the pages are not built yet (open
  point).

## R27 – Blind players

- **When:** if fewer than 4 real players play a game, the missing positions
  are filled with blind values (R27.1, R21.3). At least 1 real player, so at
  most 3 blind positions (R27.2); without any real player see R27.3 / R21.6.
- **Per game:** the blind mark belongs to a single game (R27.5). A player who
  played and then drops out without a substitute keeps their position with
  their own blind games; a blind game between two own games is not allowed
  (that would be a re-entry). A substitute who actually plays fills the
  position instead – blind values only fill positions no real player takes
  (R27.17).
- **Who serves as blind player** (R27.4, R27.14, R27.15): the roster player
  who does not play that evening and has the most games played **before**
  the matchday (the same number as column G of the score sheet), then the
  next one; each at most once per matchday, marked blind in their own row.
  Ties: any of them. Another choice is rejected when confirming, naming the
  player to be taken. A correction that keeps the stored blind players is not
  checked again.
- **Derived blind value:** net = average − 10, gross = average − 10 + HDC,
  each limited to 0–300 (R27.6–R27.9). For a player dropping out it is their
  own average and HDC; for a blind player from the roster it is theirs.
- **Admin's score sheet:** the stored blind net is the value entered (R27.10).
  The derived value is the reference; only a deviation is highlighted for
  review, a matching value is saved without further confirmation (R27.11).
  If the player has no average yet on their very first evening, there is no
  reference and the entered value counts (R27.16).
- **Counting:** blind games count towards team pins and points (R27.12), never
  towards average, handicap basis or honours; the same player's real games of
  that evening count normally (R27.13).
- **Roster used up and new players** (R27.18, decisions of 2026-10-25):
  - Every roster player who does not play serves as blind player before
    anything else. A new player without an average plays with the season's
    ghost score and HDC 0.
  - Only if the roster has **fewer than 4 players**, a position left free
    becomes a **blind slot** without a player of its own. It plays like a
    ghost player: ghost score, HDC 0 – independent of the team's players, so
    a strong player cannot lift it. It is stored like a ghost team row
    (lineup row without player, `is_blind = 1`).
  - A **new player who drops out** gets the average of their own games of this
    evening − 10; this value follows the entered results until the end, like
    their first HDC (R12.9).
  - In the **team entry** ([R37](#r37--result-entry-by-the-teams)) every blind
    value is derived this way and never entered. In the admin's score sheet
    it is prefilled and stays editable: ticking "Blind" enters average − 10
    (or the ghost score for a new player); blind slots appear for a roster
    under 4, prefilled with the ghost score.
- Implementation: `Domain/BlindPlayerService` (derived values, clamping),
  `Application/ResultEntry/BlindPlayerOrder` (order, check, port
  `PlayedGamesReader`), `Application/ResultEntry/BlindFilling` (automatic
  filling: own blind games, substitutes, blind slots; port
  `PlayerAverageReader`), `EnteredGame::blindSlot`, `BlindPosition`;
  admin score sheet `ResultEntryController::slotRows`, `score-sheet.js`.

## R28 – Navigation and season choice

- **Public navigation:** home page (season selection and posts), matchdays,
  league table, honours, rules – all without login (R28.1–R28.6, R28.15).
  Average lists, day table and score sheets exist only inside the matchday
  detail view (R28.3, R28.4). Privacy policy and legal notice once they exist
  (R28.7).
- **Admin area** only after login; otherwise the login form (R28.8, R28.9).
  Areas: results, season (master data, configuration, planning, lot draws,
  printing), content (home page posts, rules), accounts, honours preview
  (R28.10–R28.17), league name ([R38](#r38--league-name)).
- **Season choice** (R28.18): without a choice – also on the first visit –
  the running season (today between start and end date), in the summer
  break the last finished one, before the first season the next one. A
  chosen season stays on all pages: publicly via the URL parameter `saison`
  on all links (the matchday detail and team pages via their own season), in
  the admin area via a selection below the admin bar that holds for the
  login session, with a visible note if it is not the current season.
- Implementation: `PublicNavigation`, `Domain/CurrentSeason`,
  `AdminView::SESSION_SEASON`.

## R29 – Previous season records

- Per player the number of games and the sum of net pins of the previous
  season, used like previous season games ([R11.4](#r11--player-average),
  [R12.8](#r12--handicap)) (R29.1). For the first season of an installation,
  or when the seasons in between are missing from the database.
- A record belongs to the season it opens; a player has at most one record
  per season (R29.11). A record of the season takes precedence over the
  player's games of the preceding season; without one those games count
  (R29.2).
- Both values are whole numbers ≥ 0, and the resulting average must lie in
  0.00–300.00 (R29.3–R29.7). Saving reports success (R29.8); the values can
  be changed until their season starts (R29.9). A player with neither a
  record nor games in the preceding season has no previous season (R29.10).
- Entered through the CSV import into a season ([R6](#r6--players)); a screen
  for editing single records is an open point.
- Implementation: table `previous_season_record` with `season_id`,
  `PreviousSeasonRecordManagement`,
  read in `HandicapFreezingRepository::averageInput`.

## R30 – Result entry overview and virtual score sheet

- The overview lists all fixtures of the season with their status "offen",
  "in Bearbeitung" or "vollständig" (R30.1–R30.4); fixtures still to be
  filled are recognisable (R30.19). The next open matchday is shown first as
  tiles, all matchdays below to expand.
- Selecting a fixture opens its **virtual score sheet** (R30.5): per team one
  row per roster player with three net fields and a blind box per game,
  blind slots if needed ([R27.18](#r27--blind-players)), the ghost team with
  fixed values; gross, sums and points are calculated live as on the paper
  sheet. Empty for a new fixture (R30.6).
- Values are saved only on explicit action (R30.14): **draft** (status
  "in Bearbeitung", R30.15; restored when opened again, R30.17) or
  **confirm** (status "vollständig", handicaps frozen, matchday played once
  all its fixtures are complete, R30.18). Nets 0–300 (R30.13).
- Before "vollständig" nothing is public or counted (R30.16).
- **Order** (decision of 2026-11-08): a fixture can only be confirmed once
  every earlier matchday of the season is complete – all its fixtures fixed
  and "vollständig"; a matchday without fixtures (e.g. a positionday not yet
  formed) counts as incomplete. Otherwise the handicaps would be frozen from
  an incomplete average and never corrected by themselves (R12.12). A draft
  stays possible, and correcting a fixture that is already complete is not
  blocked. In the result entry by the teams the confirmation is rejected with
  a note; the sent entries stay, and the admin takes them over and confirms
  later (R30.20).
- A blind value deviating from its reference is highlighted with a note
  (R30.10, R30.11). R30.12 (only a warning for the blind player order) is
  **superseded** by the rejection in R27.15.
- **Deferred:** prefilling from OCR and marking unclear recognised values
  (R30.7–R30.9), see [R3](#r3--original-score-sheets-ocr-deferred).
- Correcting a complete fixture can trigger the handicap chain preview
  ([R12.18](#r12--handicap)); the card "Verlauf" shows the fixture's history
  to admins ([R37.11](#r37--result-entry-by-the-teams)).
- Implementation: `ResultEntryController`, `ResultEntryView`,
  `VirtualScoreSheetService`, `ResultRecording`, `score-sheet.js`.

## R31 – Positionday

- Every n-th matchday is a positionday (setting "Positionday alle …
  Spieltage", default 10, 0 = none) (R31.1).
- Once **all previous matchdays are complete**, its fixtures are formed from
  the league table: 1st against 2nd, 3rd against 4th and so on, in the order
  of [R16](#r16--league-table-and-day-table) (R31.2, R31.3); before that a
  note explains why (R31.4).
- Lanes are assigned as soon as the fixtures are fixed (R31.5). A positionday
  counts like a regular matchday (R31.6); until it is complete the admin can
  correct its fixtures (R31.7).
- Implementation: `Domain/Positionday`, `PositiondayManagement`.

## R32 – Lot draw

- Chosen per matchday by the admin in the planning preview, never
  automatically (R32.1); it counts like a regular matchday (R32.2).
- **Fixtures and lanes are drawn on site.** The matchday is planned without
  fixtures. Afterwards the admin enters the drawn fixtures under "Saison" –
  every team exactly once including the ghost team, without lanes – as long
  as no results exist.
- Before the draw the system prints one half-prefilled sheet per team (own
  team on the left, right half empty for the opponent, ghost team last);
  after entering the fixtures the normal print score sheet without lane
  numbers (R32.3).
- **Deferred:** merging score sheets cut in half by OCR (R32.4).
- Implementation: `Application/LotDraw/*`, `LotDrawRepository`,
  `PrintScoreSheetData::lotDrawSheets`.

## R33 – Home page posts

- Admins create, edit and delete posts with a title (1–200 characters) and a
  text (1–5000 characters) (R33.1–R33.6); the home page shows them read-only
  next to the season selection, or only the selection if there are none
  (R33.7–R33.9). Writing requires login (R33.10).
- The text is cleaned of executable code when saved (HTML Purifier
  whitelist) (R33.11).

## R34 – Rules page

- Public read-only page "Spielregeln" (R34.1, R34.2); admins edit its text
  (0–20000 characters) (R34.3–R34.5); without a text a note is shown
  (R34.6). Writing requires login and the text is cleaned like the posts
  (R34.7, R34.8).
- With a readable handicap formula the page has a second tab with the
  handicap table of the current season ([R12](#r12--handicap)).

## R35 – Raw data and derived values

- **Raw data** is the only source of truth (R35.1): net results of the
  single games, lineups and blind marks, configuration values, master data of
  seasons and matchdays, previous season records and the **frozen
  handicaps**.
- **Derived values** – averages, pins, team gross, game, bonus and total
  points, league and day table, average lists, honours – can be calculated
  from the raw data at any time with the same result (R35.2). Storing them is
  allowed only as a cache (R35.3); a change of raw data invalidates the
  affected cache entries (R35.4).
- The **frozen handicap** is raw data, not a derived value, because it keeps
  the state before its matchday and cannot be rebuilt from the other data
  alone (R35.5). It changes only through the confirmed chain recalculation.
- Current state: the public pages compute directly; result entry
  invalidates the cache tables, nothing reads them yet. See
  [architecture.md](architecture.md#raw-data-aggregation-and-cache).

## R36 – Mobile use

- Every page has the viewport meta tag (R36.1). From 320 CSS pixels wide all
  public and admin pages work without horizontal scrolling of the page
  (R36.2, R36.3); wide tables get a mobile form without cutting values off
  (R36.4).
- Interactive elements are at least 44 × 44 CSS pixels (R36.5). The score
  sheet upload allows taking a photo directly (R36.6). Net fields open a
  numeric keyboard (R36.7).
- Only standard HTML/CSS, no external services; fonts are served locally
  (R36.8).

## R37 – Result entry by the teams

- **Switch:** per season, by an admin (R37.1). Fixtures of a lot draw exist
  only after the draw; against the ghost team see R37.10.
- **Access** (R37.2): every team has its own code for exactly its fixture and
  side, printed as QR code on its half of the print score sheet
  (`/entry?code=…`). It is valid on the matchday until 12:00 of the next
  day, cannot be guessed (96-bit HMAC derived from `totp_key`, nothing secret
  stored, so it can be printed again; only the exact issued spelling is
  accepted) and is invalidated by "Neue Codes erzeugen". The code replaces
  the login for this fixture only.
- **Guided entry** (decision of 2026-10-25; before, each team entered the
  opponent's nets): steps game 1, 2, 3, overview, confirmation. Each team
  enters game by game its **own** lineup – "spielt", "fällt aus (blind)",
  "spielt nicht" – and its **own** nets 0–300, as long as it has not sent its
  entry (R37.3, R37.4). A game not entered yet takes over the lineup of the
  game before. The team cannot change the opponent's values. Free positions
  are filled automatically with derived blind values
  ([R27.18](#r27--blind-players)); the teams never enter blind values.
- **Standing:** once both teams have entered a game completely, both see its
  gross and game points (R37.4).
- **Checks** (R37.5): "Weiter" checks the game – at least one real player,
  every net entered, no re-entry, "blind" only for a player who played the
  game before, every position filled – and stays on the game with a message.
  "Eingabe abschicken" after the overview checks all three games and the
  rules of a complete score sheet (4 per game, order of the blind players).
  Until the opponent has confirmed it, the team can withdraw its entry and
  change it.
- **Confirming** (R37.6): each team confirms the opponent's sent entry, also
  before sending its own. Once both have sent and confirmed, the results are
  saved like an admin confirmation (handicaps frozen, fixture complete,
  matchday played once all fixtures are complete) and the team entry ends.
- **Dispute** (R37.7, R37.8): a team can send the opponent's entry back with a
  reason; it reopens and the reason is shown to both teams and the admin. The
  admin sees the state per side and the running entries of the season and
  can take the values, including the derived blind values, into the virtual
  score sheet as a draft at any time; that ends the team entry.
- **Nothing public** before both confirmations; every change, sending,
  withdrawing, confirming and sending back is logged with time and side
  (R37.9).
- **Against the ghost team** (R37.10): only the real team gets a code and
  enters like any team; the ghost team's results are fixed. Sending is the
  confirmation – the results are saved at once; there is no confirmation
  step.
- **History** (R37.11): every entry of the teams (lineup mark and net per
  player and game with value before → after, sending, confirming, sending
  back with reason) and every change of an admin in the virtual score sheet
  (with the account name) is logged with time. Only logged-in admins see it,
  as the card "Verlauf" below the virtual score sheet – never the teams or
  the public.
- Implementation: `Application/TeamEntry/TeamResultEntry` (`saveGame`,
  `checkGame`, `submit`, `withdraw`, `confirm`, `reject`, `takeOver`),
  `TeamEntrySheet`, `Security/TeamEntryCodes`,
  `Presentation/TeamEntryController`/`TeamEntryView` (steps via `step`,
  notices via `notice`, redirect after every action),
  `public/assets/js/team-entry.js` (hides the net field of players who do not
  play, refreshes only the confirmation step), history `FixtureHistory`,
  `FixtureLogRepository`. `team_entry.confirmed_at` of a side means "this
  side has confirmed the opponent's entry". Architecture:
  [architecture.md](architecture.md#result-entry-by-the-teams).

## R38 – League name

- The league name applies to the whole installation, not per season, and is
  stored in the database (R38.1). A new installation starts with
  "Bowlingliga" (R38.2).
- Admins change it in the admin area "Liga"; writing requires login, and the
  name has 1–100 characters after trimming (R38.3).
- It appears in the header and footer of every page, as heading and title of
  the home page, in the title of the printed score sheet and as issuer of the
  two-factor login (R38.4). Authenticator apps set up before keep the old
  name; seasons show the current name, also older ones (R38.5).
- Implementation: `Application/League/LeagueSettings`, table
  `league_setting`, `LeagueSettingsRepository`,
  `Presentation/LeagueSettingsController`/`LeagueSettingsView`; the name is
  read once per request in `public/index.php` and passed to `Layout`,
  `PublicView`, `PrintScoreSheetService` and `AccountManagement`.

## R39 – Player page

- Every player has a page, public at `/player/{id}` and in the admin area at
  `/admin/master-data/player?id={id}` with the same content. The names in the
  average lists of the team page and the matchday detail view lead there in
  the season of the list, the names in the admin player list and in the
  rosters lead to the admin page (R39.1).
- The page shows one season at a time: the chosen one (`?season=`), otherwise
  the current one; if the player has neither played nor is on a roster
  there, their latest season. With more than one season a selection switches
  between them (R39.2).
- **Handicap history:** the frozen handicap of every played matchday of the
  season as steps; on a matchday the player missed the line continues with
  the last frozen handicap, since it does not change without games – the
  value table shows no HDC there (R39.3).
- **Average history:** the player average after every played matchday
  ([R11](#r11--player-average)), so up to the season setting "Vorjahr im
  Schnitt bis Spieltag" including the previous season, plus the mean of the
  games of the evening as dots. The history starts with the first matchday
  on which the player has an average: the first matchday they played, or –
  if a previous season record or previous season games count – already the
  first matchday of the season with that value. Without either it starts
  with their first game (R39.4). A table below lists matchday, date, HDC,
  games, evening and average.
- **Teams:** one row per season in which the player is on a roster or
  played for a team (not the ghost team), newest first: season, team,
  matchdays and games played, average after the last played matchday and the
  last frozen handicap (R39.5). With legacy data the roster wins over the
  lineup.
- A matchday counts as played as soon as one of its fixtures is complete;
  blind games and ghost team results do not count (R39.6, R11.1). The page
  only reads raw data (R35) and stores nothing about the player (R25).
- Implementation: `Domain/PlayerProfile/PlayerHistoryService`,
  `Application/PlayerProfile/PlayerProfiles`, `PlayerProfileRepository`,
  `Presentation/PlayerProfileView` with `SvgLineChart` (inline SVG without
  script, colours from the stylesheet).

## R40 – Public player data

- A league setting decides whether visitors without login see player names
  and everything about players; it applies to the whole installation and is
  changed in the admin area "Liga" (R40.1). A new installation starts with
  "off"; migration 003 switches it on for an installation that already had
  seasons, so that it keeps showing what it showed before. The demo data
  (`bin/seed-demo.php`) switches it on.
- **Off** (R40.2): public pages show team values only.
  - Matchday detail view: per fixture the result with the team sums per game
    and the points, and the day table; no score sheet, no original score
    sheet, no average lists, a note instead.
  - Team page: a note in place of the average list; the team statistics
    stay visible ([R41](#r41--team-page)).
  - Honours: a note, since every honour names a player.
  - Player page (`/player/{id}`) and original score sheet (`/score-sheet/{id}`):
    404.
  - Unchanged: home page (results without players), matchday list, league
    table.
- Logged-in admins see the public pages in full (R40.3). Teams have no login;
  they see their players only in their result entry
  ([R37](#r37--result-entry-by-the-teams)), which the setting does not
  affect. The check starts no session for visitors without a session cookie.
- Implementation: column `league_setting.players_public`,
  `LeagueSettings::playersPublic()`/`save()`, `LeagueSettingsView` (checkbox),
  `$playersVisible` in `public/index.php`, `$withPlayers` of
  `PublicController`/`PublicView`.

## R41 – Team page

- Every team has a page per season, public at `/team/{id}` (id of the team
  season) and in the admin area at `/admin/master-data/team?id={id}` with the
  same content. The league and day table link to the public page, the team
  names in the master data to the admin page, the opponents on the page to
  their own page. The ghost team has no page (R41.1).
- **Seasons:** a team keeps its identity (team id) across seasons whatever
  its name; with more than one season a selection switches between them
  (R41.2). Creating a team for a new season still makes a new team; carrying
  a team over into the next season is open.
- **Key figures** of the season (R41.3): rank of the league table, points
  with the bonus points among them, team average net and gross like the
  table (R16.1), the average without blind games (R41.5), gross pins, single
  games won, drawn and lost (R17.3), best team game and best series (gross
  of an evening), blind games and matchdays played.
- **Charts** after every played matchday (R41.4): league position (rank 1 on
  top) from the league table of all matchdays so far, points next to those
  of the leader, and the team average net with and without blind games plus
  the evening's average as dots. A matchday without a fixture of the team
  still moves the standing.
- **Without blind games** (R41.5): blind games leave out both their pins and
  their count, so a blind value does not pull the average; the line with
  blind games matches the league table (R27.12).
- **Games of the season** (R41.6): per matchday the opponent, the outcome of
  the three games, points for and against, bonus points, gross pins and the
  rank after the matchday; cancelled matchdays and matchdays without a
  fixture of the team are listed but count for nothing.
- **Average list** (R41.7): the team's current average list
  ([R2](#r2--average-list-of-a-team)) follows; without public player data a
  note replaces it ([R40](#r40--public-player-data)), the team statistics
  stay. The admin page always shows it (R41.8).
- The page computes everything from the raw data of the table (R35) and
  stores nothing.
- Implementation: `Application/TeamProfile/TeamProfiles` (uses
  `TableAggregator::tableFrom()` per matchday), `TeamProfileRepository`,
  `TableRawDataRepository` (blind games and matchday per fixture),
  `Presentation/TeamProfileView` with `SvgLineChart`.
