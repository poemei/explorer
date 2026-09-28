ALTER TABLE `explorer_transactions`
 ADD COLUMN IF NOT EXISTS `schema_version` VARCHAR(64) NULL AFTER `id`;

CREATE TABLE IF NOT EXISTS `explorer_settings` (
 `id` TINYINT UNSIGNED NOT NULL,
 `primary_ip` VARCHAR(45) NOT NULL,
 `primary_port` SMALLINT UNSIGNED NOT NULL,
 `secondary_ip` VARCHAR(45) NULL,
 `secondary_port` SMALLINT UNSIGNED NULL,
 PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `explorer_transactions` (`id`, `schema_version`)
 VALUES (1, '1.1.0')
 ON DUPLICATE KEY UPDATE `schema_version` = VALUES(`schema_version`);
