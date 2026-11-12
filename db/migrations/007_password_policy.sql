-- =============================================================================
-- Migration 007: system admin, password policies and password history
-- =============================================================================
-- The permission "manage admin accounts" becomes the role system admin
-- (R23.10): whoever held it keeps it under its new key.
--
-- password_policy holds one policy per kind of account (R23.15): minimum
-- strength in bits, maximum age in days (-1 = unlimited) and how many
-- earlier passwords may not be used again. The defaults of a new
-- installation are only inserted where no row exists yet.
--
-- admin_account remembers when its password was set, how strong it was
-- and whether its owner has to replace it at the next login (R23.17).
-- Existing passwords count as set now, so a maximum age starts with this
-- update.
--
-- password_history keeps the hashes of earlier passwords (R23.18).
--
-- Repeatable.

UPDATE `account_permission` SET `permission` = 'system.admin' WHERE `permission` = 'accounts.manage';

CREATE TABLE IF NOT EXISTS `password_policy` (
  `account_kind` varchar(20) NOT NULL,
  `min_bits` smallint(5) unsigned NOT NULL,
  `max_age_days` smallint(6) NOT NULL,
  `history_size` tinyint(3) unsigned NOT NULL,
  PRIMARY KEY (`account_kind`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `password_policy` (`account_kind`, `min_bits`, `max_age_days`, `history_size`) VALUES
  ('admin', 70, -1, 5),
  ('user', 50, -1, 5);

ALTER TABLE `admin_account`
  ADD COLUMN IF NOT EXISTS `password_changed_at` datetime DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `password_bits` smallint(5) unsigned DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `must_change_password` tinyint(1) NOT NULL DEFAULT 0;

UPDATE `admin_account` SET `password_changed_at` = NOW() WHERE `password_changed_at` IS NULL;

CREATE TABLE IF NOT EXISTS `password_history` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `account_id` int(10) unsigned NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_password_history_account` (`account_id`, `id`),
  CONSTRAINT `fk_password_history_account` FOREIGN KEY (`account_id`) REFERENCES `admin_account` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
