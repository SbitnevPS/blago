-- Миграция: временный пароль для восстановления и откат через 1 час

SET @database_name = DATABASE();

SET @has_password := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @database_name
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'password'
);
SET @sql := IF(@has_password = 0,
    'ALTER TABLE users ADD COLUMN password VARCHAR(255) NULL AFTER email',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_recovery_old_password_hash := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @database_name
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'recovery_old_password_hash'
);
SET @sql := IF(@has_recovery_old_password_hash = 0,
    'ALTER TABLE users ADD COLUMN recovery_old_password_hash VARCHAR(255) NULL AFTER password',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_recovery_expires_at := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @database_name
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'recovery_expires_at'
);
SET @sql := IF(@has_recovery_expires_at = 0,
    'ALTER TABLE users ADD COLUMN recovery_expires_at DATETIME NULL AFTER recovery_old_password_hash',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    email VARCHAR(255) NOT NULL,
    token VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_password_resets_token (token),
    INDEX idx_password_resets_user (user_id),
    INDEX idx_password_resets_email (email),
    CONSTRAINT fk_password_resets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
