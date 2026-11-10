# Data Model

Schema in [db/migrations/](../db/migrations/). Applied with
`php bin/migrate.php`; the table `schema_migration` records which
migrations have run. All migrations are repeatable
(`IF NOT EXISTS`). InnoDB, utf8mb4.

| Migration | Content |
|---|---|
| `001_schema.sql` | The complete schema: all raw data tables, the cache tables (`cache_*`, JSON payload + hash, can be discarded at any time) and the settings row `league_setting` with the league name "Bowlingliga" |
| `002_points_rules.sql` | Points rules per season (game points, bonus points scale) in `season_config` |
| `003_players_public.sql` | `league_setting.players_public` (R40): off for a new installation, on for one that already has seasons |
| `004_matchday_cancelled.sql` | `matchday.is_cancelled` (R8.20): a cancelled matchday, reversible |
| `005_trusted_device.sql` | `trusted_device` (R5.10): trusted browsers of an account, exempt from the account lock |

Create new schema changes as `002_…sql`, `003_…sql` and so on, and add them
to the `applySchema()` lists of the integration tests.

## Relationships

```
season 1─1 season_config            (lane_count, entry_fee, blind_fee, max_handicap,
  │                                  handicap_formula, target_score, ghost_score,
  │                                  win/draw/loss_points, bonus_threshold/_step/_maximum, …)
  ├─n team_season ─n─1 team         (team_name per season, is_ghost_team)
  │     └─n roster ─n─1 player      (player: name, gender)
  ├─n lane_pair                     (lane_a, lane_b)
  └─n matchday                      (matchday_number, date, matchday_type, is_played, is_cancelled)
        ├─n fixture                 (home/away → team_season, lane_pair, completion_status)
        │     ├─n lineup            (team_season, sheet_row 1..n, player_id NULL = ghost team
        │     │     │                or blind slot, is_blind = player serves as blind player
        │     │     │                or blind slot)
        │     │     └─n game_result (game_number 1–3, played games only; net 0–300, is_blind)
        │     └─n original_score_sheet (content_hash → file in score_sheet_dir,
        │                               size_bytes, mime_type, uploaded_at)
        └─n frozen_handicap         (player_id, handicap)

player 1─n previous_season_record   (season_id, game_count, net_pins_total – previous season of a season
                                     whose previous season is not in the database)
admin_account ─n login_attempt      (password_hash, totp_secret_encrypted,
                                     totp_last_time_step, locked_until, failed_attempts,
                                     session_version)
  └─n trusted_device                (token_hash = SHA-256 of the cookie token, session_version,
                                     failed_attempts, last_used_at, expires_at; column
                                     account_id, meant for every kind of account)
home_page_post, rules, privacy_policy, legal_notice   (CMS)
league_setting                       (name of the league, players_public; one row)

fixture 1─2 team_entry               (side home/away, code_version, submitted_at, confirmed_at,
  │                                    rejection_note, closed_at)
  ├─n team_entry_game                (team_season, player, game_number, is_blind, own net – until
  │                                    both teams have confirmed)
  └─n fixture_log                    (side home/away/NULL = admin, actor, action, player_id,
                                       game_number, value_before, value_after, detail, created_at)
```

## Important fields and their meaning

- **Home and away team differ (R8.6):** enforced in the application layer
  (`Security\Validator::checkDifferentTeams`), not as a CHECK constraint.
  MariaDB refuses (error 1901) a column that is both in a foreign key with a
  referential action (`ON DELETE SET NULL`) and in a CHECK clause, and the
  foreign keys are to be kept.

- **`matchday.matchday_type`:** `regular`, `positionday` (fixtures are only
  formed from the standings) or `lot_draw` (printed without lane numbers).
- **`matchday.is_played`:** "played matchday". Only set when **all** fixtures
  of the matchday are `complete` (R24.1). Only such matchdays count towards
  standings, averages and honours and are clickable on the public site.
- **`matchday.is_cancelled`:** the league management cancelled the whole
  matchday (R8.20). It keeps number, date and fixtures (so the cancellation
  can be taken back) but takes no results and does not block later
  matchdays. Postponing (R8.21) only changes `matchday.date` and
  `season.end_date`.
- **`fixture.completion_status`:** `open` → `in_progress` (draft)
  → `complete` (confirmed, 24 single games = 2 teams × 3 games × exactly
  4 results).
- **`lineup`:** one row per row of the score sheet:
  a roster player with the games they played (a substituted player has only
  1–2 `game_result` rows; a player who serves as blind player has only blind
  games and `is_blind = 1`) or a ghost team row. `ResultRepository` rewrites
  the rows when the results are saved; removing the results also deletes the
  rows.
- **Ghost team:** one `team_season` row with `is_ghost_team = 1` per season
  with an odd number of teams. Lineup without players
  (`player_id IS NULL`), results automatically = ghost score.
- **Blind:** `lineup.is_blind = 1` marks the row of a roster player who does
  not play that evening and serves as blind player (most games played,
  R27.4). `game_result.is_blind` marks single games. Blind games do not count
  towards average, handicap basis and honours.
- **`frozen_handicap`:** HDC per player and matchday, set on confirmation and
  never overwritten automatically afterwards. It is raw data and the basis for
  gross and points of this matchday.
- **`original_score_sheet.content_hash`:** SHA-256 of the uploaded file, which
  lies unchanged under this name in the score sheet directory (see
  [architecture.md](architecture.md#score-sheet-files)). Several records may
  share a hash (the same file uploaded twice); the file is deleted with the
  last of them (R3.19).
- **`season_config.handicap_formula`:** text such as
  `HDC = (Zielwert - Durchschnitt) * 0.8` (stored in German, as entered in the
  UI) or `standard`. With `NULL` there is no handicap. Parsed in
  `HandicapFreezingRepository::readFormula()`.
- **`season_config.win_points`, `draw_points`, `loss_points`,
  `bonus_threshold`, `bonus_step`, `bonus_maximum`** (migration 002): points
  rules of the season (R17.11), defaults 2/1/0 and 2100/100/6; read via
  `SeasonConfiguration::pointsRulesFromRow()`.

## Access without a mysql client

Locally there is no `mysql` client on the PATH. Queries run via small PHP
scripts with PDO (`new PDO('mysql:host=127.0.0.1;dbname=…', 'root', '')`),
see the `db-query` skill.
