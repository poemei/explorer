CREATE TABLE IF NOT EXISTS `explorer_blocks` (
 `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 `block_id` CHAR(64) NOT NULL,
 `height` BIGINT UNSIGNED NOT NULL,
 `block_timestamp` BIGINT UNSIGNED NOT NULL,
 `transaction_count` INT UNSIGNED NOT NULL,
 `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY (`id`),
 UNIQUE KEY `uq_explorer_blocks_block_id` (`block_id`),
 UNIQUE KEY `uq_explorer_blocks_height` (`height`),
 KEY `idx_explorer_blocks_timestamp` (`block_timestamp`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `explorer_state` (
 `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `explorer_transactions`
 ADD COLUMN IF NOT EXISTS `schema_version` VARCHAR(64) NULL AFTER `id`;

CREATE TABLE IF NOT EXISTS `explorer_settings` (
 `id` TINYINT UNSIGNED NOT NULL,
 `primary_ip` VARCHAR(45) NOT NULL DEFAULT '',
 `primary_port` SMALLINT UNSIGNED NOT NULL DEFAULT 18473,
 `secondary_ip` VARCHAR(45) NULL,
 `secondary_port` SMALLINT UNSIGNED NULL,
 `stratum_host` VARCHAR(255) NULL,
 `stratum_port` SMALLINT UNSIGNED NULL,
 PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `explorer_settings`
 ADD COLUMN IF NOT EXISTS `stratum_host` VARCHAR(255) NULL AFTER `secondary_port`,
 ADD COLUMN IF NOT EXISTS `stratum_port` SMALLINT UNSIGNED NULL AFTER `stratum_host`;

INSERT INTO `explorer_settings`
 (`id`, `primary_ip`, `primary_port`)
VALUES
 (1, '', 18473)
ON DUPLICATE KEY UPDATE
 `id` = VALUES(`id`);

INSERT INTO `explorer_transactions` (`id`, `schema_version`)
 VALUES (1, '1.1.0')
 ON DUPLICATE KEY UPDATE `schema_version` = VALUES(`schema_version`);
