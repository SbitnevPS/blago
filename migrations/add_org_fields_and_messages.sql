-- Добавляем поля организации в таблицу users
ALTER TABLE users ADD COLUMN organization_region VARCHAR(100) AFTER surname;
ALTER TABLE users ADD COLUMN organization_name VARCHAR(255) AFTER organization_region;
ALTER TABLE users ADD COLUMN organization_address TEXT AFTER organization_name;

-- Создаём таблицу сообщений
CREATE TABLE IF NOT EXISTS admin_messages (
 id INT AUTO_INCREMENT PRIMARY KEY,
 user_id INT NOT NULL,
 admin_id INT NOT NULL,
 subject VARCHAR(255) NOT NULL,
 message TEXT NOT NULL,
 priority ENUM('normal', 'important', 'critical') DEFAULT 'normal',
 is_read TINYINT(1) DEFAULT0,
 created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_user_id (user_id),
 INDEX idx_is_read (is_read)
);
