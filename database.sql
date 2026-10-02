-- Fresh-install schema for Print Tracker.
-- For an existing installation, back up the database and use migrate_normalize_devices.sql instead.
CREATE DATABASE IF NOT EXISTS `print_tracking_db`
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE `print_tracking_db`;

CREATE TABLE IF NOT EXISTS `devices` (
    `device_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `hostname` VARCHAR(255) NOT NULL,
    PRIMARY KEY (`device_id`),
    UNIQUE KEY `uq_devices_hostname` (`hostname`)
) ENGINE=InnoDB
  DEFAULT CHARACTER SET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `print_logs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `device_id` BIGINT UNSIGNED NOT NULL,
    `document_name` TEXT NULL,
    `copies` INT NULL,
    `total_pages` INT NULL,
    `print_timestamp` DATETIME(6) NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_print_logs_timestamp` (`print_timestamp`),
    KEY `idx_print_logs_device_timestamp` (`device_id`, `print_timestamp`),
    CONSTRAINT `fk_print_logs_device`
        FOREIGN KEY (`device_id`) REFERENCES `devices` (`device_id`)
) ENGINE=InnoDB
  DEFAULT CHARACTER SET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `activity_logs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `device_id` BIGINT UNSIGNED NOT NULL,
    `interval_start` DATETIME(6) NOT NULL,
    `interval_end` DATETIME(6) NOT NULL,
    `interval_minutes` SMALLINT UNSIGNED NOT NULL,
    `mouse_movement_count` BIGINT UNSIGNED NOT NULL,
    `keyboard_stroke_count` BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_activity_device_interval` (`device_id`, `interval_start`, `interval_minutes`),
    KEY `idx_activity_interval_start` (`interval_start`),
    CONSTRAINT `fk_activity_logs_device`
        FOREIGN KEY (`device_id`) REFERENCES `devices` (`device_id`)
) ENGINE=InnoDB
  DEFAULT CHARACTER SET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;