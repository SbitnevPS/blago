-- Миграция: добавление поля password в таблицу users
-- Выполнить этот файл для добавления возможности регистрации по email

ALTER TABLE users ADD COLUMN password VARCHAR(255) AFTER email;

-- Обновляем пароль администратора (admin123)
UPDATE users SET password = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi' WHERE email = 'admin@kids-contests.ru';
