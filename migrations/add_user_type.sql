-- Миграция: тип пользователя для регистрации (родитель/куратор)
ALTER TABLE users
    ADD COLUMN user_type ENUM('parent', 'curator') NOT NULL DEFAULT 'parent' AFTER is_admin;
