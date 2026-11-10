-- =============================================================================
-- Migration 006: permissions of the accounts and their log
-- =============================================================================
-- An account holds permissions (R23.10). The permission keys are fixed in
-- the code (enum Security\Permission); this table only records who holds
-- which. The column is called account_id, because the same rule is meant for
-- other kinds of accounts later.
--
-- The existing accounts keep everything they could do so far, i.e. they get
-- all permissions that exist at this point. The grant only runs while the
-- table is still empty, so running the migration again never undoes a
-- revocation.
--
-- account_log keeps the changes to accounts and permissions (R23.14). Names
-- are stored as text, so an entry stays readable after the account is gone;
-- a missing actor means the command line.
--
-- Repeatable.

CREATE TABLE IF NOT EXISTS `account_permission` (
  `account_id` int(10) unsigned NOT NULL,
  `permission` varchar(50) NOT NULL,
  PRIMARY KEY (`account_id`, `permission`),
  CONSTRAINT `fk_account_permission_account` FOREIGN KEY (`account_id`) REFERENCES `admin_account` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `account_permission` (`account_id`, `permission`)
SELECT `id`, 'accounts.manage' FROM `admin_account`
WHERE NOT EXISTS (SELECT 1 FROM `account_permission`);

CREATE TABLE IF NOT EXISTS `account_log` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `created_at` datetime NOT NULL,
  `actor_account_id` int(10) unsigned DEFAULT NULL,
  `actor_name` varchar(100) DEFAULT NULL,
  `target_name` varchar(100) NOT NULL,
  `action` varchar(20) NOT NULL,
  `permission` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_account_log_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
