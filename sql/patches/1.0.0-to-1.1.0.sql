CREATE TABLE IF NOT EXISTS `explorer_settings` (
 `id` TINYINT UNSIGNED NOT NULL,
 `primary_ip` VARCHAR(45) NOT NULL,
 `primary_port` SMALLINT UNSIGNED NOT NULL,
 `secondary_ip` VARCHAR(45) NULL,
 `secondary_port` SMALLINT UNSIGNED NULL,
 PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE `explorer_transactions` SET `schema_version` = '1.1.0' WHERE `id` = 1;
