-- Совместимая миграция для MySQL/MariaDB без `ADD COLUMN IF NOT EXISTS`

SET @db_name := DATABASE();

DROP PROCEDURE IF EXISTS add_contest_participant_duplicates_flag_if_missing;

DELIMITER $$
CREATE PROCEDURE add_contest_participant_duplicates_flag_if_missing()
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = @db_name
          AND TABLE_NAME = 'contests'
          AND COLUMN_NAME = 'allow_participant_duplicates'
    ) THEN
        ALTER TABLE contests
            ADD COLUMN allow_participant_duplicates TINYINT(1) NOT NULL DEFAULT 0 AFTER requires_payment_receipt;
    END IF;
END$$
DELIMITER ;

CALL add_contest_participant_duplicates_flag_if_missing();
DROP PROCEDURE add_contest_participant_duplicates_flag_if_missing;
