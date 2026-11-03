-- =============================================================================
-- Migration 001: schema of Bowlingliga
-- =============================================================================
-- The complete schema of a new installation.
--
-- Raw data is the single source of truth (R35): standings, averages, points
-- and honours are calculated from it; the cache_* tables can be emptied at any
-- time. Frozen handicaps are raw data. Tables, columns and their meaning:
-- docs/data-model.md.
--
-- Requires MariaDB 10.11 or newer, InnoDB and utf8mb4. Repeatable
-- (IF NOT EXISTS, INSERT IGNORE); new schema changes follow as 002_….

SET NAMES utf8mb4;

-- admin_account: admin accounts with password hash and encrypted TOTP secret (R23)
CREATE TABLE IF NOT EXISTS `admin_account` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `totp_secret_encrypted` varbinary(512) DEFAULT NULL,
  `totp_last_time_step` bigint(20) unsigned DEFAULT NULL,
  `locked_until` datetime DEFAULT NULL,
  `failed_attempts` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `session_version` int(10) unsigned NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_admin_account_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- login_attempt: failed logins per account and source, for the throttling (R5.6)
CREATE TABLE IF NOT EXISTS `login_attempt` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `admin_account_id` int(10) unsigned NOT NULL,
  `attempted_at` datetime NOT NULL,
  `successful` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `fk_login_attempt_admin_account` (`admin_account_id`),
  CONSTRAINT `fk_login_attempt_admin_account` FOREIGN KEY (`admin_account_id`) REFERENCES `admin_account` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- season: seasons with period and switches (R8)
CREATE TABLE IF NOT EXISTS `season` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `matchday_count` int(10) unsigned DEFAULT NULL,
  `team_entry` tinyint(1) NOT NULL DEFAULT 0,
  `score_sheet_language` char(2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `chk_season_name_length` CHECK (char_length(`name`) between 1 and 100),
  CONSTRAINT `chk_season_date_order` CHECK (`end_date` > `start_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- season_config: rule settings of a season: lanes, fees, handicap, ghost score, previous season, positiondays (R8, R12)
CREATE TABLE IF NOT EXISTS `season_config` (
  `season_id` int(10) unsigned NOT NULL,
  `lane_count` int(10) unsigned NOT NULL,
  `entry_fee` decimal(8,2) NOT NULL DEFAULT 0.00,
  `blind_fee` decimal(8,2) NOT NULL DEFAULT 0.00,
  `max_handicap` smallint(5) unsigned NOT NULL DEFAULT 64,
  `handicap_formula` varchar(255) DEFAULT NULL,
  `target_score` smallint(5) unsigned NOT NULL DEFAULT 200,
  `ghost_score` smallint(5) unsigned NOT NULL DEFAULT 190,
  `previous_season_matchdays` smallint(5) unsigned NOT NULL DEFAULT 5,
  `positionday_interval` smallint(5) unsigned NOT NULL DEFAULT 10,
  PRIMARY KEY (`season_id`),
  CONSTRAINT `fk_season_config_season` FOREIGN KEY (`season_id`) REFERENCES `season` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `chk_season_config_lane_count` CHECK (`lane_count` >= 2 and `lane_count` MOD 2 = 0),
  CONSTRAINT `chk_season_config_entry_fee` CHECK (`entry_fee` >= 0.00),
  CONSTRAINT `chk_season_config_blind_fee` CHECK (`blind_fee` >= 0.00),
  CONSTRAINT `chk_season_config_max_handicap` CHECK (`max_handicap` between 0 and 300),
  CONSTRAINT `chk_season_config_target_score` CHECK (`target_score` between 0 and 300),
  CONSTRAINT `chk_season_config_ghost_score` CHECK (`ghost_score` between 0 and 300),
  CONSTRAINT `chk_season_config_previous_season_matchdays` CHECK (`previous_season_matchdays` between 0 and 99),
  CONSTRAINT `chk_season_config_positionday_interval` CHECK (`positionday_interval` between 0 and 99)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- team: identity of a team across seasons (R7)
CREATE TABLE IF NOT EXISTS `team` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- team_season: a team in one season with its name; the ghost team is flagged (R7, R20)
CREATE TABLE IF NOT EXISTS `team_season` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `team_id` int(10) unsigned NOT NULL,
  `season_id` int(10) unsigned NOT NULL,
  `team_name` varchar(100) NOT NULL,
  `is_ghost_team` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_team_season_team_name` (`season_id`,`team_name`),
  KEY `fk_team_season_team` (`team_id`),
  CONSTRAINT `fk_team_season_season` FOREIGN KEY (`season_id`) REFERENCES `season` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_team_season_team` FOREIGN KEY (`team_id`) REFERENCES `team` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `chk_team_season_name_length` CHECK (char_length(`team_name`) between 1 and 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- player: players (R6)
CREATE TABLE IF NOT EXISTS `player` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `gender` enum('male','female') NOT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `chk_player_name_not_empty` CHECK (char_length(`name`) between 1 and 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- roster: players of a team in a season (R6.6)
CREATE TABLE IF NOT EXISTS `roster` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `team_season_id` int(10) unsigned NOT NULL,
  `player_id` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_roster_player_per_team_season` (`team_season_id`,`player_id`),
  KEY `fk_roster_player` (`player_id`),
  CONSTRAINT `fk_roster_player` FOREIGN KEY (`player_id`) REFERENCES `player` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_roster_team_season` FOREIGN KEY (`team_season_id`) REFERENCES `team_season` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- previous_season_record: games and net pins of the previous season of a season (R29)
CREATE TABLE IF NOT EXISTS `previous_season_record` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `season_id` int(10) unsigned NOT NULL,
  `player_id` int(10) unsigned NOT NULL,
  `game_count` int(10) unsigned NOT NULL DEFAULT 0,
  `net_pins_total` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_previous_season_record_season_player` (`season_id`,`player_id`),
  KEY `ix_previous_season_record_player` (`player_id`),
  CONSTRAINT `fk_previous_season_record_player` FOREIGN KEY (`player_id`) REFERENCES `player` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_previous_season_record_season` FOREIGN KEY (`season_id`) REFERENCES `season` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- lane_pair: lane pairs of a season (R18)
CREATE TABLE IF NOT EXISTS `lane_pair` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `season_id` int(10) unsigned NOT NULL,
  `lane_a` smallint(5) unsigned NOT NULL,
  `lane_b` smallint(5) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_lane_pair_per_season` (`season_id`,`lane_a`,`lane_b`),
  CONSTRAINT `fk_lane_pair_season` FOREIGN KEY (`season_id`) REFERENCES `season` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `chk_lane_pair_lanes` CHECK (`lane_a` >= 1 and `lane_b` >= 1 and `lane_a` <> `lane_b`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- matchday: matchdays: regular, positionday or lot draw; played flag (R8, R24)
CREATE TABLE IF NOT EXISTS `matchday` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `season_id` int(10) unsigned NOT NULL,
  `matchday_number` int(10) unsigned NOT NULL,
  `date` date NOT NULL,
  `matchday_type` enum('regular','positionday','lot_draw') NOT NULL DEFAULT 'regular',
  `is_played` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_matchday_number_per_season` (`season_id`,`matchday_number`),
  CONSTRAINT `fk_matchday_season` FOREIGN KEY (`season_id`) REFERENCES `season` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `chk_matchday_number` CHECK (`matchday_number` >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- fixture: fixtures of a matchday with lane pair and completion status (R9, R22)
CREATE TABLE IF NOT EXISTS `fixture` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `matchday_id` int(10) unsigned NOT NULL,
  `home_team_season_id` int(10) unsigned DEFAULT NULL,
  `away_team_season_id` int(10) unsigned DEFAULT NULL,
  `lane_pair_id` int(10) unsigned DEFAULT NULL,
  `completion_status` enum('open','in_progress','complete') NOT NULL DEFAULT 'open',
  PRIMARY KEY (`id`),
  KEY `fk_fixture_matchday` (`matchday_id`),
  KEY `fk_fixture_home` (`home_team_season_id`),
  KEY `fk_fixture_away` (`away_team_season_id`),
  KEY `fk_fixture_lane_pair` (`lane_pair_id`),
  CONSTRAINT `fk_fixture_away` FOREIGN KEY (`away_team_season_id`) REFERENCES `team_season` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_fixture_home` FOREIGN KEY (`home_team_season_id`) REFERENCES `team_season` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_fixture_lane_pair` FOREIGN KEY (`lane_pair_id`) REFERENCES `lane_pair` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_fixture_matchday` FOREIGN KEY (`matchday_id`) REFERENCES `matchday` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- lineup: one row per row of the score sheet: player, blind flag or ghost team (R21, R27)
CREATE TABLE IF NOT EXISTS `lineup` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `fixture_id` int(10) unsigned NOT NULL,
  `team_season_id` int(10) unsigned NOT NULL,
  `sheet_row` tinyint(3) unsigned NOT NULL,
  `player_id` int(10) unsigned DEFAULT NULL,
  `is_blind` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_lineup_sheet_row` (`fixture_id`,`team_season_id`,`sheet_row`),
  KEY `fk_lineup_team_season` (`team_season_id`),
  KEY `fk_lineup_player` (`player_id`),
  CONSTRAINT `fk_lineup_fixture` FOREIGN KEY (`fixture_id`) REFERENCES `fixture` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_lineup_player` FOREIGN KEY (`player_id`) REFERENCES `player` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_lineup_team_season` FOREIGN KEY (`team_season_id`) REFERENCES `team_season` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `chk_lineup_sheet_row` CHECK (`sheet_row` >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- game_result: net result per lineup row and game (R22)
CREATE TABLE IF NOT EXISTS `game_result` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `lineup_id` int(10) unsigned NOT NULL,
  `game_number` tinyint(3) unsigned NOT NULL,
  `net` smallint(5) unsigned NOT NULL,
  `is_blind` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_game_result_per_lineup` (`lineup_id`,`game_number`),
  CONSTRAINT `fk_game_result_lineup` FOREIGN KEY (`lineup_id`) REFERENCES `lineup` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `chk_game_result_number` CHECK (`game_number` between 1 and 3),
  CONSTRAINT `chk_game_result_net` CHECK (`net` between 0 and 300)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- frozen_handicap: handicap per player and matchday, frozen on confirmation – raw data (R12.12)
CREATE TABLE IF NOT EXISTS `frozen_handicap` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `matchday_id` int(10) unsigned NOT NULL,
  `player_id` int(10) unsigned NOT NULL,
  `handicap` smallint(5) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_frozen_handicap` (`matchday_id`,`player_id`),
  KEY `fk_frozen_handicap_player` (`player_id`),
  CONSTRAINT `fk_frozen_handicap_matchday` FOREIGN KEY (`matchday_id`) REFERENCES `matchday` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_frozen_handicap_player` FOREIGN KEY (`player_id`) REFERENCES `player` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `chk_frozen_handicap_value` CHECK (`handicap` between 0 and 300)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- original_score_sheet: uploaded original score sheets; the files lie in score_sheet_dir (R3)
CREATE TABLE IF NOT EXISTS `original_score_sheet` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `fixture_id` int(10) unsigned NOT NULL,
  `content_hash` char(64) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `size_bytes` int(10) unsigned NOT NULL,
  `mime_type` varchar(100) NOT NULL,
  `uploaded_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_original_score_sheet_fixture` (`fixture_id`),
  KEY `idx_original_score_sheet_hash` (`content_hash`),
  CONSTRAINT `fk_original_score_sheet_fixture` FOREIGN KEY (`fixture_id`) REFERENCES `fixture` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- team_entry: result entry by the teams: state per fixture and side (R37)
CREATE TABLE IF NOT EXISTS `team_entry` (
  `fixture_id` int(10) unsigned NOT NULL,
  `side` enum('home','away') NOT NULL,
  `code_version` smallint(5) unsigned NOT NULL DEFAULT 1,
  `submitted_at` datetime DEFAULT NULL,
  `confirmed_at` datetime DEFAULT NULL,
  `rejection_note` varchar(500) DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`fixture_id`,`side`),
  CONSTRAINT `fk_team_entry_fixture` FOREIGN KEY (`fixture_id`) REFERENCES `fixture` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- team_entry_game: results entered by a team until both have confirmed (R37)
CREATE TABLE IF NOT EXISTS `team_entry_game` (
  `fixture_id` int(10) unsigned NOT NULL,
  `team_season_id` int(10) unsigned NOT NULL,
  `player_id` int(10) unsigned NOT NULL,
  `game_number` tinyint(3) unsigned NOT NULL,
  `is_blind` tinyint(1) NOT NULL DEFAULT 0,
  `net` smallint(5) unsigned DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`fixture_id`,`player_id`,`game_number`),
  KEY `fk_team_entry_game_team_season` (`team_season_id`),
  KEY `fk_team_entry_game_player` (`player_id`),
  CONSTRAINT `fk_team_entry_game_fixture` FOREIGN KEY (`fixture_id`) REFERENCES `fixture` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_team_entry_game_player` FOREIGN KEY (`player_id`) REFERENCES `player` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_team_entry_game_team_season` FOREIGN KEY (`team_season_id`) REFERENCES `team_season` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `chk_team_entry_game_number` CHECK (`game_number` between 1 and 3),
  CONSTRAINT `chk_team_entry_game_net` CHECK (`net` is null or `net` <= 300)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- fixture_log: history of a fixture: entries of the teams and changes of the admin (R37.11)
CREATE TABLE IF NOT EXISTS `fixture_log` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `fixture_id` int(10) unsigned NOT NULL,
  `side` enum('home','away') DEFAULT NULL,
  `actor` varchar(100) DEFAULT NULL,
  `action` varchar(30) NOT NULL,
  `player_id` int(10) unsigned DEFAULT NULL,
  `game_number` tinyint(3) unsigned DEFAULT NULL,
  `value_before` varchar(20) DEFAULT NULL,
  `value_after` varchar(20) DEFAULT NULL,
  `detail` varchar(500) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_team_entry_log_fixture` (`fixture_id`,`id`),
  CONSTRAINT `fk_team_entry_log_fixture` FOREIGN KEY (`fixture_id`) REFERENCES `fixture` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- home_page_post: news posts of the home page (R33)
CREATE TABLE IF NOT EXISTS `home_page_post` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(200) NOT NULL,
  `text` text NOT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `chk_home_page_post_title` CHECK (char_length(`title`) between 1 and 200),
  CONSTRAINT `chk_home_page_post_text` CHECK (char_length(`text`) between 1 and 5000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- rules: text of the rules page (R34)
CREATE TABLE IF NOT EXISTS `rules` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `text` mediumtext NOT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `chk_rules_text` CHECK (char_length(`text`) between 0 and 20000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- privacy_policy: privacy policy (R26)
CREATE TABLE IF NOT EXISTS `privacy_policy` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `text` mediumtext NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- legal_notice: legal notice (R26)
CREATE TABLE IF NOT EXISTS `legal_notice` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `text` mediumtext NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- league_setting: settings of the installation, exactly one row: the league name (R38)
CREATE TABLE IF NOT EXISTS `league_setting` (
  `id` tinyint(3) unsigned NOT NULL,
  `name` varchar(100) NOT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `chk_league_setting_single` CHECK (`id` = 1),
  CONSTRAINT `chk_league_setting_name` CHECK (char_length(`name`) between 1 and 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- cache_average: cache of the average lists – can be emptied at any time (R35)
CREATE TABLE IF NOT EXISTS `cache_average` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `cache_key` varchar(191) NOT NULL,
  `season_id` int(10) unsigned DEFAULT NULL,
  `from_matchday_number` int(10) unsigned DEFAULT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`payload`)),
  `raw_hash` char(64) NOT NULL,
  `computed_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cache_average_key` (`cache_key`),
  KEY `idx_cache_average_invalidation` (`season_id`,`from_matchday_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- cache_league_table: cache of the league tables (R35)
CREATE TABLE IF NOT EXISTS `cache_league_table` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `cache_key` varchar(191) NOT NULL,
  `season_id` int(10) unsigned DEFAULT NULL,
  `from_matchday_number` int(10) unsigned DEFAULT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`payload`)),
  `raw_hash` char(64) NOT NULL,
  `computed_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cache_league_table_key` (`cache_key`),
  KEY `idx_cache_league_table_invalidation` (`season_id`,`from_matchday_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- cache_matchday_table: cache of the day tables (R35)
CREATE TABLE IF NOT EXISTS `cache_matchday_table` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `cache_key` varchar(191) NOT NULL,
  `season_id` int(10) unsigned DEFAULT NULL,
  `from_matchday_number` int(10) unsigned DEFAULT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`payload`)),
  `raw_hash` char(64) NOT NULL,
  `computed_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cache_matchday_table_key` (`cache_key`),
  KEY `idx_cache_matchday_table_invalidation` (`season_id`,`from_matchday_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- cache_honours: cache of the honours (R35)
CREATE TABLE IF NOT EXISTS `cache_honours` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `cache_key` varchar(191) NOT NULL,
  `season_id` int(10) unsigned DEFAULT NULL,
  `from_matchday_number` int(10) unsigned DEFAULT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`payload`)),
  `raw_hash` char(64) NOT NULL,
  `computed_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cache_honours_key` (`cache_key`),
  KEY `idx_cache_honours_invalidation` (`season_id`,`from_matchday_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The league name of a new installation (R38.2).
INSERT IGNORE INTO league_setting (id, name) VALUES (1, 'Bowlingliga');
