-- База данных для конкурсов детских рисунков

-- Создание базы данных
CREATE DATABASE IF NOT EXISTS kids_contests CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE kids_contests;

-- Таблица пользователей
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vk_id VARCHAR(50) UNIQUE,
    vk_access_token TEXT,
    name VARCHAR(100) NOT NULL,
    surname VARCHAR(100),
    email VARCHAR(255),
    password VARCHAR(255) NULL,
    recovery_old_password_hash VARCHAR(255) NULL,
    recovery_expires_at DATETIME NULL,
    email_verified TINYINT(1) NOT NULL DEFAULT 0,
    email_verified_at DATETIME NULL,
    email_verification_token VARCHAR(255) NULL,
    email_verification_sent_at DATETIME NULL,
    avatar_url TEXT,
    is_admin TINYINT(1) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_vk_id (vk_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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

-- Таблица конкурсов
CREATE TABLE IF NOT EXISTS contests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    document_file VARCHAR(255),
    requires_payment_receipt TINYINT(1) NOT NULL DEFAULT 0,
    cover_image VARCHAR(255) NULL,
    theme_style VARCHAR(50) NOT NULL DEFAULT 'blue',
    is_published TINYINT(1) DEFAULT 0,
    is_archived TINYINT(1) NOT NULL DEFAULT 0,
    date_from DATE,
    date_to DATE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_published (is_published),
    INDEX idx_is_archived (is_archived),
    INDEX idx_dates (date_from, date_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS editor_uploads (
    id INT AUTO_INCREMENT PRIMARY KEY,
    contest_id INT NULL,
    file_name VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NULL,
    file_path VARCHAR(255) NOT NULL,
    file_url VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    file_size INT NOT NULL DEFAULT 0,
    uploaded_by INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (contest_id) REFERENCES contests(id) ON DELETE SET NULL,
    FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_editor_uploads_contest (contest_id),
    INDEX idx_editor_uploads_uploaded_by (uploaded_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Таблица заявок
CREATE TABLE IF NOT EXISTS applications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    contest_id INT NOT NULL,
    status ENUM('draft', 'submitted', 'approved', 'rejected', 'cancelled', 'corrected') DEFAULT 'draft',
    parent_fio VARCHAR(255),
    source_info VARCHAR(255),
    colleagues_info VARCHAR(255),
    recommendations_wishes TEXT,
    payment_receipt VARCHAR(255),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (contest_id) REFERENCES contests(id) ON DELETE CASCADE,
    INDEX idx_user (user_id),
    INDEX idx_contest (contest_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Таблица участников
CREATE TABLE IF NOT EXISTS participants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    application_id INT NOT NULL,
    public_number VARCHAR(32) NULL,
    fio VARCHAR(255) NOT NULL,
    age INT,
    has_ovz TINYINT(1) NOT NULL DEFAULT 0,
    region VARCHAR(255),
    organization_name VARCHAR(255),
    organization_address TEXT,
    organization_email VARCHAR(255),
    leader_fio VARCHAR(255),
    curator_1_fio VARCHAR(255),
    curator_2_fio VARCHAR(255),
    drawing_file VARCHAR(255),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE,
    INDEX idx_application (application_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Создание администратора (пароль: admin123)
INSERT INTO users (vk_id, name, surname, email, password, is_admin) 
VALUES ('admin', 'Администратор', '', 'admin@kids-contests.ru', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 1);
