-- Добавляем поле patronymic в таблицу users
ALTER TABLE users ADD COLUMN patronymic VARCHAR(100) AFTER name;
