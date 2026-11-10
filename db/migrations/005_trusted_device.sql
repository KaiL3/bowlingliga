-- =============================================================================
-- Migration 005: trusted devices
-- =============================================================================
-- A browser in which an account logged in completely is remembered as a
-- trusted device of that account for 90 days (R5.10). The account lock after
-- failed attempts (R5.6) only applies to other devices, so somebody who knows
-- a username cannot keep its owner out by locking the account on purpose.
--
-- The browser keeps a random token in a cookie, the table only its SHA-256
-- hash. One browser can be trusted for several accounts (one row each). The
-- column is called account_id, not admin_account_id, because the same rule
-- is meant for other kinds of accounts later.
--
-- Repeatable.

CREATE TABLE IF NOT EXISTS `trusted_device` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `account_id` int(10) unsigned NOT NULL,
  `token_hash` char(64) NOT NULL,
  `session_version` int(10) unsigned NOT NULL,
  `failed_attempts` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL,
  `last_used_at` datetime NOT NULL,
  `expires_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_trusted_device_token_account` (`token_hash`, `account_id`),
  KEY `fk_trusted_device_account` (`account_id`),
  CONSTRAINT `fk_trusted_device_account` FOREIGN KEY (`account_id`) REFERENCES `admin_account` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
