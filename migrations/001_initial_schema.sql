-- Project Alpha – Initial Database Schema
-- Run via: php migrations/install.php  (or import manually)
-- Table prefix: alpha_

SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET foreign_key_checks = 0;

-- ── Users ─────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `alpha_users` (
    `id`               INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `username`         VARCHAR(30)     NOT NULL,
    `email`            VARCHAR(255)    NOT NULL,
    `password`         VARCHAR(255)    NOT NULL,
    `display_name`     VARCHAR(100)    NOT NULL DEFAULT '',
    `role`             VARCHAR(30)     NOT NULL DEFAULT 'starter_member',
    `status`           ENUM('active','pending','banned') NOT NULL DEFAULT 'active',
    `avatar`           VARCHAR(500)    DEFAULT NULL,
    `dark_mode`        ENUM('auto','light','dark') NOT NULL DEFAULT 'auto',
    `email_token`      VARCHAR(64)     DEFAULT NULL,
    `remember_token`   VARCHAR(64)     DEFAULT NULL,
    `remember_expires` DATETIME        DEFAULT NULL,
    `reset_token`      VARCHAR(64)     DEFAULT NULL,
    `reset_expires`    DATETIME        DEFAULT NULL,
    `last_login`       DATETIME        DEFAULT NULL,
    `created_at`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_username` (`username`),
    UNIQUE KEY `uniq_email`    (`email`),
    KEY `idx_role`   (`role`),
    KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Manga ─────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `alpha_manga` (
    `id`           INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `title`        VARCHAR(500)     NOT NULL,
    `slug`         VARCHAR(500)     NOT NULL,
    `alt_names`    TEXT             DEFAULT NULL,
    `author`       VARCHAR(200)     DEFAULT NULL,
    `artist`       VARCHAR(200)     DEFAULT NULL,
    `synopsis`     LONGTEXT         DEFAULT NULL,
    `cover`        VARCHAR(1000)    DEFAULT NULL,
    `type`         VARCHAR(30)      NOT NULL DEFAULT 'manga',
    `status`       VARCHAR(30)      NOT NULL DEFAULT 'ongoing',
    `release_year` SMALLINT UNSIGNED DEFAULT NULL,
    `adult`        TINYINT(1)       NOT NULL DEFAULT 0,
    `badge`        VARCHAR(20)      DEFAULT NULL COMMENT 'hot|new|trending',
    `views`        BIGINT UNSIGNED  NOT NULL DEFAULT 0,
    `rating_avg`   DECIMAL(3,2)     NOT NULL DEFAULT 0.00,
    `rating_count` INT UNSIGNED     NOT NULL DEFAULT 0,
    `uploader_id`  INT UNSIGNED     DEFAULT NULL,
    `created_at`   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_slug` (`slug`(191)),
    KEY `idx_type`       (`type`),
    KEY `idx_status`     (`status`),
    KEY `idx_views`      (`views`),
    KEY `idx_updated`    (`updated_at`),
    FULLTEXT KEY `ft_search` (`title`, `alt_names`, `author`, `artist`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Genres ────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `alpha_genres` (
    `id`   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(100) NOT NULL,
    `slug` VARCHAR(100) NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `alpha_manga_genre_map` (
    `manga_id` INT UNSIGNED NOT NULL,
    `genre_id` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`manga_id`, `genre_id`),
    KEY `idx_genre_id` (`genre_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Chapters ──────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `alpha_chapters` (
    `id`             INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `manga_id`       INT UNSIGNED     NOT NULL,
    `chapter_number` DECIMAL(8,1)     NOT NULL,
    `title`          VARCHAR(500)     DEFAULT NULL,
    `chapter_type`   ENUM('image','text','video') NOT NULL DEFAULT 'image',
    `chapter_data`   LONGTEXT         NOT NULL COMMENT 'JSON: images array | text content | video url',
    `status`         VARCHAR(20)      NOT NULL DEFAULT 'publish',
    `is_premium`     TINYINT(1)       NOT NULL DEFAULT 0,
    `coin_price`     INT UNSIGNED     NOT NULL DEFAULT 0,
    `views`          BIGINT UNSIGNED  NOT NULL DEFAULT 0,
    `order_index`    INT UNSIGNED     NOT NULL DEFAULT 0,
    `created_at`     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_manga_id`   (`manga_id`),
    KEY `idx_status`     (`status`),
    KEY `idx_chapter_num`(`manga_id`, `chapter_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Ratings ───────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `alpha_ratings` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `manga_id`   INT UNSIGNED NOT NULL,
    `user_id`    INT UNSIGNED NOT NULL,
    `rating`     TINYINT UNSIGNED NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_user_manga` (`user_id`, `manga_id`),
    KEY `idx_manga_id` (`manga_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Bookmarks ─────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `alpha_bookmarks` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    INT UNSIGNED NOT NULL,
    `manga_id`   INT UNSIGNED NOT NULL,
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_user_manga` (`user_id`, `manga_id`),
    KEY `idx_manga_id` (`manga_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Reading History ───────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `alpha_history` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    INT UNSIGNED NOT NULL,
    `manga_id`   INT UNSIGNED NOT NULL,
    `chapter_id` INT UNSIGNED NOT NULL,
    `page`       INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_user_manga` (`user_id`, `manga_id`),
    KEY `idx_updated` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Views log ─────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `alpha_views_log` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `manga_id`   INT UNSIGNED    DEFAULT NULL,
    `chapter_id` INT UNSIGNED    NOT NULL,
    `user_id`    INT UNSIGNED    DEFAULT NULL,
    `ip_address` VARCHAR(45)     NOT NULL,
    `viewed_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_chapter_id` (`chapter_id`),
    KEY `idx_manga_id`   (`manga_id`),
    KEY `idx_viewed_at`  (`viewed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Coin system ───────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `alpha_user_coins` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    INT UNSIGNED NOT NULL,
    `balance`    BIGINT       NOT NULL DEFAULT 0,
    `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `alpha_coin_transactions` (
    `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`          INT UNSIGNED    NOT NULL,
    `amount`           BIGINT          NOT NULL COMMENT 'Positive=credit, negative=debit',
    `transaction_type` VARCHAR(20)     NOT NULL,
    `reference_id`     INT UNSIGNED    DEFAULT 0,
    `description`      VARCHAR(500)    DEFAULT '',
    `created_at`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_type`    (`transaction_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `alpha_chapter_unlocks` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    INT UNSIGNED NOT NULL,
    `chapter_id` INT UNSIGNED NOT NULL,
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_user_chapter` (`user_id`, `chapter_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Withdrawals ───────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `alpha_withdrawals` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`         INT UNSIGNED NOT NULL,
    `amount`          BIGINT       NOT NULL,
    `payment_method`  VARCHAR(50)  NOT NULL,
    `payment_details` TEXT         DEFAULT NULL,
    `status`          ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    `processed_at`    DATETIME     DEFAULT NULL,
    `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_status`  (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Path map (obfuscation) ────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `alpha_path_map` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `hash`       VARCHAR(64)  NOT NULL,
    `real_path`  VARCHAR(1000) NOT NULL,
    `expires_at` DATETIME     DEFAULT NULL,
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_hash` (`hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Transients (rate-limiting, caching) ───────────────────────────────────────

CREATE TABLE IF NOT EXISTS `alpha_transients` (
    `key`        VARCHAR(191) NOT NULL,
    `value`      MEDIUMTEXT   NOT NULL,
    `expires_at` DATETIME     DEFAULT NULL,
    PRIMARY KEY (`key`),
    KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Settings ──────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `alpha_settings` (
    `key`        VARCHAR(100) NOT NULL,
    `value`      MEDIUMTEXT   NOT NULL,
    `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Seed: Common genres ───────────────────────────────────────────────────────

INSERT IGNORE INTO `alpha_genres` (`name`, `slug`) VALUES
('Action', 'action'), ('Adventure', 'adventure'), ('Comedy', 'comedy'),
('Drama', 'drama'), ('Fantasy', 'fantasy'), ('Horror', 'horror'),
('Isekai', 'isekai'), ('Magic', 'magic'), ('Martial Arts', 'martial-arts'),
('Mecha', 'mecha'), ('Mystery', 'mystery'), ('Psychological', 'psychological'),
('Romance', 'romance'), ('School Life', 'school-life'), ('Sci-Fi', 'sci-fi'),
('Seinen', 'seinen'), ('Shoujo', 'shoujo'), ('Shounen', 'shounen'),
('Slice of Life', 'slice-of-life'), ('Sports', 'sports'),
('Supernatural', 'supernatural'), ('Thriller', 'thriller'),
('Tragedy', 'tragedy'), ('Yaoi', 'yaoi'), ('Yuri', 'yuri');

SET foreign_key_checks = 1;
