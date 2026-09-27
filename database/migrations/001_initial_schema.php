<?php

/*
 * Initial schema. Each migration returns a list of SQL statements that are
 * executed in order. Never edit a migration that has shipped — add a new
 * numbered file instead and bump App::SCHEMA_VERSION.
 */

return [
    "CREATE TABLE IF NOT EXISTS `users` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `name` VARCHAR(120) NOT NULL,
        `email` VARCHAR(190) NOT NULL,
        `password_hash` VARCHAR(255) NOT NULL,
        `last_login_at` DATETIME NULL,
        `created_at` DATETIME NOT NULL,
        `updated_at` DATETIME NOT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `users_email_unique` (`email`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    "CREATE TABLE IF NOT EXISTS `auth_tokens` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id` INT UNSIGNED NOT NULL,
        `selector` CHAR(24) NOT NULL,
        `validator_hash` CHAR(64) NOT NULL,
        `expires_at` DATETIME NOT NULL,
        `created_at` DATETIME NOT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `auth_tokens_selector_unique` (`selector`),
        KEY `auth_tokens_user` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    "CREATE TABLE IF NOT EXISTS `login_attempts` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `ip` VARCHAR(45) NOT NULL,
        `email` VARCHAR(190) NULL,
        `created_at` DATETIME NOT NULL,
        PRIMARY KEY (`id`),
        KEY `login_attempts_ip_time` (`ip`, `created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    "CREATE TABLE IF NOT EXISTS `settings` (
        `key` VARCHAR(100) NOT NULL,
        `value` MEDIUMTEXT NULL,
        `updated_at` DATETIME NOT NULL,
        PRIMARY KEY (`key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    "CREATE TABLE IF NOT EXISTS `campaigns` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `name` VARCHAR(190) NOT NULL,
        `country` CHAR(2) NOT NULL,
        `source` VARCHAR(60) NOT NULL,
        `keywords` TEXT NOT NULL,
        `locations` TEXT NOT NULL,
        `options` TEXT NOT NULL,
        `status` VARCHAR(20) NOT NULL DEFAULT 'draft',
        `pages_planned` INT UNSIGNED NOT NULL DEFAULT 0,
        `pages_done` INT UNSIGNED NOT NULL DEFAULT 0,
        `listings_found` INT UNSIGNED NOT NULL DEFAULT 0,
        `leads_qualified` INT UNSIGNED NOT NULL DEFAULT 0,
        `leads_rejected` INT UNSIGNED NOT NULL DEFAULT 0,
        `duplicates` INT UNSIGNED NOT NULL DEFAULT 0,
        `websites_crawled` INT UNSIGNED NOT NULL DEFAULT 0,
        `emails_found` INT UNSIGNED NOT NULL DEFAULT 0,
        `requests` INT UNSIGNED NOT NULL DEFAULT 0,
        `errors` INT UNSIGNED NOT NULL DEFAULT 0,
        `blocked_streak` INT UNSIGNED NOT NULL DEFAULT 0,
        `last_error` TEXT NULL,
        `last_activity_at` DATETIME NULL,
        `last_worked_at` DATETIME NULL,
        `started_at` DATETIME NULL,
        `finished_at` DATETIME NULL,
        `created_at` DATETIME NOT NULL,
        `updated_at` DATETIME NOT NULL,
        PRIMARY KEY (`id`),
        KEY `campaigns_status` (`status`),
        KEY `campaigns_created` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    "CREATE TABLE IF NOT EXISTS `tasks` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `campaign_id` INT UNSIGNED NOT NULL,
        `type` VARCHAR(20) NOT NULL,
        `priority` TINYINT NOT NULL DEFAULT 0,
        `payload` TEXT NOT NULL,
        `status` VARCHAR(12) NOT NULL DEFAULT 'pending',
        `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
        `available_at` DATETIME NOT NULL,
        `locked_until` DATETIME NULL,
        `last_error` TEXT NULL,
        `created_at` DATETIME NOT NULL,
        PRIMARY KEY (`id`),
        KEY `tasks_pick` (`status`, `available_at`, `priority`),
        KEY `tasks_campaign` (`campaign_id`, `status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    "CREATE TABLE IF NOT EXISTS `leads` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `campaign_id` INT UNSIGNED NOT NULL,
        `source` VARCHAR(60) NOT NULL,
        `source_ref` VARCHAR(190) NOT NULL,
        `status` VARCHAR(12) NOT NULL DEFAULT 'pending',
        `reject_reason` VARCHAR(255) NULL,
        `name` VARCHAR(255) NOT NULL,
        `category` VARCHAR(190) NULL,
        `phone` VARCHAR(60) NULL,
        `phone_digits` VARCHAR(20) NULL,
        `email` VARCHAR(190) NULL,
        `emails` TEXT NULL,
        `website` VARCHAR(500) NULL,
        `domain` VARCHAR(190) NULL,
        `address` VARCHAR(255) NULL,
        `city` VARCHAR(120) NULL,
        `state` VARCHAR(60) NULL,
        `postcode` VARCHAR(20) NULL,
        `country` CHAR(2) NULL,
        `latitude` DECIMAL(10,7) NULL,
        `longitude` DECIMAL(10,7) NULL,
        `rating` DECIMAL(3,1) NULL,
        `reviews` INT UNSIGNED NULL,
        `socials` TEXT NULL,
        `source_url` VARCHAR(500) NULL,
        `keyword` VARCHAR(190) NULL,
        `search_location` VARCHAR(190) NULL,
        `starred` TINYINT(1) NOT NULL DEFAULT 0,
        `notes` TEXT NULL,
        `meta` TEXT NULL,
        `created_at` DATETIME NOT NULL,
        `updated_at` DATETIME NOT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `leads_campaign_ref` (`campaign_id`, `source`, `source_ref`),
        KEY `leads_status` (`status`, `created_at`),
        KEY `leads_source_ref` (`source`, `source_ref`),
        KEY `leads_phone` (`phone_digits`),
        KEY `leads_domain` (`domain`),
        KEY `leads_email` (`email`),
        KEY `leads_created` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    "CREATE TABLE IF NOT EXISTS `logs` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `campaign_id` INT UNSIGNED NULL,
        `level` VARCHAR(10) NOT NULL,
        `message` VARCHAR(1000) NOT NULL,
        `created_at` DATETIME NOT NULL,
        PRIMARY KEY (`id`),
        KEY `logs_campaign` (`campaign_id`, `id`),
        KEY `logs_created` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    "CREATE TABLE IF NOT EXISTS `throttle` (
        `bucket` VARCHAR(120) NOT NULL,
        `last_at` DOUBLE NOT NULL,
        PRIMARY KEY (`bucket`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];
