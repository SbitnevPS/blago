-- Добавляет поле "Название работы" в таблицу works, если его нет

SET @db_name := DATABASE();

SELECT COUNT(*) INTO @has_work_title
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = @db_name
  AND TABLE_NAME = 'works'
  AND COLUMN_NAME = 'title';

SET @sql := IF(
  @has_work_title = 0,
  'ALTER TABLE works ADD COLUMN title VARCHAR(255) NULL AFTER participant_id',
  'SELECT "Column works.title already exists" AS info'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
