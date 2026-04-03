-- Миграция: Добавление полей для пользователя
-- Выполнить этот файл для обновления базы данных

-- Проверяем и добавляем недостающие поля
-- Таблица уже содержит: organization_region, organization_name, organization_address

-- Добавляем поле patronymic если его нет
-- ALTER TABLE users ADD COLUMN patronymic VARCHAR(100) AFTER surname;

-- Таблица сообщений от администратора (если еще не создана)
CREATE TABLE IF NOT EXISTS admin_messages (
 id INT AUTO_INCREMENT PRIMARY KEY,
 user_id INT NOT NULL,
 admin_id INT NOT NULL,
 subject VARCHAR(255) NOT NULL,
 message TEXT NOT NULL,
 priority ENUM('normal', 'important', 'critical') DEFAULT 'normal',
 is_read TINYINT(1) DEFAULT 0,
 created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
 FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE CASCADE,
 INDEX idx_user (user_id),
 INDEX idx_read (is_read)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
