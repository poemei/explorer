CREATE TABLE IF NOT EXISTS `explorer_schema` (
 `id` TINYINT UNSIGNED NOT NULL,
 `schema_version` VARCHAR(64) NOT NULL,
 `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `explorer_transactions` (
 `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE IF NOT EXISTS `explorer_settings` (
 `id` TINYINT UNSIGNED NOT NULL,
 `primary_ip` VARCHAR(45) NOT NULL,
 `primary_port` SMALLINT UNSIGNED NOT NULL,
 `secondary_ip` VARCHAR(45) NULL,
 `secondary_port` SMALLINT UNSIGNED NULL,
 PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `explorer_schema`
 (`id`, `schema_version`)
VALUES
 (1, '1.2.0')
ON DUPLICATE KEY UPDATE
 `schema_version` = VALUES(`schema_version`);