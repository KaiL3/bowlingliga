---
name: db-query
description: Run SQL against the local MariaDB of the league website (demo or test database), read-only by default. Use when data, ids or the current state (seasons, matchdays, fixtures, results, accounts) need to be checked – there is no mysql client.
---

# Database query

There is no `mysql` client. Queries run through `query.php` in this folder;
it reads the credentials from `config/database.php` (or the file named by
`BOWLINGLIGA_CONFIG`).

```bash
PHP=${PHP:-php}   # set PHP to the full path if php is not on the PATH
$PHP .claude/skills/db-query/query.php "SELECT id, name FROM season"
$PHP .claude/skills/db-query/query.php --db=bowlingliga_test "SHOW TABLES"
$PHP .claude/skills/db-query/query.php --file=<tmp>/query.sql   # several statements, each with ; at the end of a line
```

## Rules

- **READ ONLY is the default.** Writing statements fail as long as
  `--write` is missing.
- The database from `config/database.php` may hold real league data and
  may be **in use at the same time**. Ids and state can change at any
  moment – query afresh before every statement about the data.
- `--write` only on the user's **explicit request**. First show with a
  read query which rows are affected. The script runs all statements in one
  transaction and rolls back on any error.
- Write longer SQL to a temporary file and pass it via `--file=` (long
  shell heredocs have broken off before).

## Important tables

Raw data (schema in `db/migrations/001_schema.sql`): `season`,
`season_config`, `team`, `team_season` (`is_ghost_team`), `player`, `roster`,
`matchday` (`matchday_type`, `is_played`), `fixture` (`completion_status`),
`lane_pair`, `lineup` (`is_blind`), `game_result`, `frozen_handicap`,
`previous_season_record`, `original_score_sheet` (metadata; the files lie in
`score_sheet_dir`), `team_entry`, `team_entry_game`, `fixture_log`,
`admin_account`, `login_attempt`, `home_page_post`, `rules`, `privacy_policy`,
`legal_notice`, `league_setting`. Caches:
`cache_*` (performance only). Columns and relations:
[docs/data-model.md](../../../docs/data-model.md).
