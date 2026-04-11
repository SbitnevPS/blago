-- Совместимая миграция для MySQL/MariaDB без `ADD COLUMN IF NOT EXISTS`

SET @db_name := DATABASE();

DROP PROCEDURE IF EXISTS add_contest_payment_receipt_requirement_if_missing;

DELIMITER $$
CREATE PROCEDURE add_contest_payment_receipt_requirement_if_missing()
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = @db_name
          AND TABLE_NAME = 'contests'
          AND COLUMN_NAME = 'requires_payment_receipt'
    ) THEN
        ALTER TABLE contests
            ADD COLUMN requires_payment_receipt TINYINT(1) NOT NULL DEFAULT 0 AFTER document_file;
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = @db_name
          AND TABLE_NAME = 'applications'
          AND COLUMN_NAME = 'payment_receipt'
    ) THEN
        ALTER TABLE applications
            ADD COLUMN payment_receipt VARCHAR(255) NULL AFTER recommendations_wishes;
    END IF;
END$$
DELIMITER ;

CALL add_contest_payment_receipt_requirement_if_missing();
DROP PROCEDURE add_contest_payment_receipt_requirement_if_missing;
