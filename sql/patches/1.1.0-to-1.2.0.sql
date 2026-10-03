ALTER TABLE `explorer_transactions`
 ADD COLUMN `transaction_id` CHAR(64) NULL AFTER `schema_version`,
 ADD COLUMN `block_id` CHAR(64) NULL AFTER `transaction_id`,
 ADD COLUMN `block_height` BIGINT UNSIGNED NULL AFTER `block_id`,
 ADD COLUMN `transaction_position` INT UNSIGNED NULL AFTER `block_height`,
 ADD COLUMN `transaction_type` SMALLINT UNSIGNED NULL AFTER `transaction_position`,
 ADD UNIQUE KEY `uq_explorer_transactions_transaction_id` (`transaction_id`),
 ADD KEY `idx_explorer_transactions_height_position` (`block_height`, `transaction_position`);

UPDATE `explorer_transactions`
SET `schema_version` = '1.2.0'
WHERE `id` = 1;
