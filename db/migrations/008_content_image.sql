-- =============================================================================
-- Migration 008: images of the content
-- =============================================================================
-- Images the admins place in the texts of the website (home page posts,
-- rules, privacy policy, legal notice) (R42). The file itself lies in the
-- image store (directory media_dir of the configuration), named after the
-- SHA-256 hash of its content; this table records what the overview shows.
-- Where an image is used is not stored: it is read from the texts (R35).
--
-- Repeatable.

CREATE TABLE IF NOT EXISTS `content_image` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `content_hash` char(64) NOT NULL,
  `mime_type` varchar(50) NOT NULL,
  `width` smallint(5) unsigned NOT NULL,
  `height` smallint(5) unsigned NOT NULL,
  `size_bytes` int(10) unsigned NOT NULL,
  `original_name` varchar(255) NOT NULL,
  `uploaded_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_content_image_hash` (`content_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
