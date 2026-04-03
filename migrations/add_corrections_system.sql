-- Миграция: добавление системы корректировок и сообщений

-- Таблица корректировок полей заявки
CREATE TABLE IF NOT EXISTS application_corrections (
 id INT AUTO_INCREMENT PRIMARY KEY,
 application_id INT NOT NULL,
 participant_id INT,
 field_name VARCHAR(100) NOT NULL,
 comment TEXT,
 is_resolved TINYINT(1) DEFAULT0,
 created_by INT NOT NULL,
 created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
 resolved_at DATETIME NULL,
 FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE,
 FOREIGN KEY (participant_id) REFERENCES participants(id) ON DELETE CASCADE,
 FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
 INDEX idx_application (application_id),
 INDEX idx_resolved (is_resolved)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Таблица сообщений
CREATE TABLE IF NOT EXISTS messages (
 id INT AUTO_INCREMENT PRIMARY KEY,
 user_id INT NOT NULL,
 application_id INT,
 title VARCHAR(255) NOT NULL,
 content TEXT NOT NULL,
 is_read TINYINT(1) DEFAULT0,
 created_by INT NOT NULL,
 created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
 FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE SET NULL,
 FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
 INDEX idx_user (user_id),
 INDEX idx_read (is_read),
 INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Добавляем поле allow_edit в applications
ALTER TABLE applications ADD COLUMN allow_edit TINYINT(1) DEFAULT0 AFTER status;

-- Индекс для быстрого поиска
ALTER TABLE applications ADD INDEX idx_allow_edit (allow_edit);
