USE `print_tracking_db`;

CREATE TABLE IF NOT EXISTS `devices` (
    `device_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `hostname` VARCHAR(255) NOT NULL,
    PRIMARY KEY (`device_id`),
    UNIQUE KEY `uq_devices_hostname` (`hostname`)
) ENGINE=InnoDB
  DEFAULT CHARACTER SET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `activity_logs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `hostname` VARCHAR(255) NOT NULL,
    `interval_start` DATETIME(6) NOT NULL,
    `interval_end` DATETIME(6) NOT NULL,
    `interval_minutes` SMALLINT UNSIGNED NOT NULL,
    `mouse_movement_count` BIGINT UNSIGNED NOT NULL,
    `keyboard_stroke_count` BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_activity_host_interval` (`hostname`, `interval_start`, `interval_minutes`),
    KEY `idx_activity_interval_start` (`interval_start`)
) ENGINE=InnoDB
  DEFAULT CHARACTER SET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `devices` (`hostname`)
SELECT `hostname` FROM `print_logs`
UNION
SELECT `hostname` FROM `activity_logs`;

ALTER TABLE `print_logs`
    ADD COLUMN `device_id` BIGINT UNSIGNED NULL AFTER `id`;

UPDATE `print_logs` AS p
JOIN `devices` AS d ON d.`hostname` = p.`hostname`
SET p.`device_id` = d.`device_id`;

ALTER TABLE `print_logs`
    MODIFY COLUMN `device_id` BIGINT UNSIGNED NOT NULL,
    DROP INDEX `idx_print_logs_hostname_timestamp`,
    ADD KEY `idx_print_logs_device_timestamp` (`device_id`, `print_timestamp`),
    ADD CONSTRAINT `fk_print_logs_device`
        FOREIGN KEY (`device_id`) REFERENCES `devices` (`device_id`),
    DROP COLUMN `hostname`;

ALTER TABLE `activity_logs`
    ADD COLUMN `device_id` BIGINT UNSIGNED NULL AFTER `id`;

UPDATE `activity_logs` AS a
JOIN `devices` AS d ON d.`hostname` = a.`hostname`
SET a.`device_id` = d.`device_id`;

ALTER TABLE `activity_logs`
    MODIFY COLUMN `device_id` BIGINT UNSIGNED NOT NULL,
    DROP INDEX `uq_activity_host_interval`,
    ADD UNIQUE KEY `uq_activity_device_interval` (`device_id`, `interval_start`, `interval_minutes`),
    ADD CONSTRAINT `fk_activity_logs_device`
        FOREIGN KEY (`device_id`) REFERENCES `devices` (`device_id`),
    DROP COLUMN `hostname`;
