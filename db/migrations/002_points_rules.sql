-- =============================================================================
-- Migration 002: points rules per season
-- =============================================================================
-- Game points per single game (win/draw/loss) and the bonus points scale
-- (threshold for 1 point, pins per further point, maximum) become part of the
-- season configuration (R17.1, R17.11). Existing seasons get the rules that
-- applied so far: 2/1/0 and 2100/100/6. A new season inherits the values of
-- the previous one (R8.15).
--
-- Repeatable: the column-level checks are only added together with their
-- column.

ALTER TABLE `season_config`
  ADD COLUMN IF NOT EXISTS `win_points` tinyint(3) unsigned NOT NULL DEFAULT 2 CHECK (`win_points` <= 10),
  ADD COLUMN IF NOT EXISTS `draw_points` tinyint(3) unsigned NOT NULL DEFAULT 1 CHECK (`draw_points` <= 10),
  ADD COLUMN IF NOT EXISTS `loss_points` tinyint(3) unsigned NOT NULL DEFAULT 0 CHECK (`loss_points` <= 10),
  ADD COLUMN IF NOT EXISTS `bonus_threshold` smallint(5) unsigned NOT NULL DEFAULT 2100 CHECK (`bonus_threshold` <= 9999),
  ADD COLUMN IF NOT EXISTS `bonus_step` smallint(5) unsigned NOT NULL DEFAULT 100 CHECK (`bonus_step` between 1 and 1000),
  ADD COLUMN IF NOT EXISTS `bonus_maximum` tinyint(3) unsigned NOT NULL DEFAULT 6 CHECK (`bonus_maximum` <= 20);
