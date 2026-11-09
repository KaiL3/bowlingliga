-- =============================================================================
-- Migration 004: cancelled matchdays
-- =============================================================================
-- The league management can cancel a whole matchday (R8.20): it stays in the
-- plan with its number, its fixtures are dropped without replacement and it
-- does not count. Cancelling is reversible, so the fixtures are kept.
--
-- Repeatable.

ALTER TABLE `matchday`
  ADD COLUMN IF NOT EXISTS `is_cancelled` tinyint(1) NOT NULL DEFAULT 0 AFTER `is_played`;
