-- =============================================================================
-- Migration 003: public player data as a league setting
-- =============================================================================
-- Whether visitors without login see player names and everything about
-- players (R40.1). A new installation starts with "off"; an installation
-- that already has seasons keeps showing them ("on"), as before this setting
-- existed.
--
-- Repeatable: the column is added as NULL first, only rows still without a
-- value are filled, then the column becomes NOT NULL.

ALTER TABLE `league_setting`
  ADD COLUMN IF NOT EXISTS `players_public` tinyint(1) DEFAULT NULL;

UPDATE `league_setting`
   SET `players_public` = IF(EXISTS (SELECT 1 FROM `season`), 1, 0)
 WHERE `players_public` IS NULL;

ALTER TABLE `league_setting`
  MODIFY COLUMN `players_public` tinyint(1) NOT NULL DEFAULT 0;
