-- Безопасная миграция: добавляет недостающие поля блока "Дополнительная информация"
-- в таблицу applications только если их ещё нет в текущей БД.

SET @db_name := DATABASE();

DROP PROCEDURE IF EXISTS add_application_additional_info_fields_if_missing;

DELIMITER $$
CREATE PROCEDURE add_application_additional_info_fields_if_missing()
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = @db_name
          AND TABLE_NAME = 'applications'
          AND COLUMN_NAME = 'source_info'
    ) THEN
        ALTER TABLE applications
            ADD COLUMN source_info VARCHAR(255) NULL AFTER parent_fio;
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = @db_name
          AND TABLE_NAME = 'applications'
          AND COLUMN_NAME = 'colleagues_info'
    ) THEN
        ALTER TABLE applications
            ADD COLUMN colleagues_info VARCHAR(255) NULL AFTER source_info;
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = @db_name
          AND TABLE_NAME = 'applications'
          AND COLUMN_NAME = 'recommendations_wishes'
    ) THEN
        ALTER TABLE applications
            ADD COLUMN recommendations_wishes TEXT NULL AFTER colleagues_info;
    END IF;
END$$
DELIMITER ;

CALL add_application_additional_info_fields_if_missing();
DROP PROCEDURE add_application_additional_info_fields_if_missing;
