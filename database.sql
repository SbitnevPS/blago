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
    avatar_url TEXT,
    is_admin TINYINT(1) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_vk_id (vk_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Таблица конкурсов
CREATE TABLE IF NOT EXISTS contests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    document_file VARCHAR(255),
    is_published TINYINT(1) DEFAULT 0,
    date_from DATE,
    date_to DATE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_published (is_published),
    INDEX idx_dates (date_from, date_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Таблица заявок
CREATE TABLE IF NOT EXISTS applications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    contest_id INT NOT NULL,
    status ENUM('draft', 'submitted', 'approved', 'rejected') DEFAULT 'draft',
    parent_fio VARCHAR(255),
    source_info VARCHAR(255),
    colleagues_info VARCHAR(255),
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
    fio VARCHAR(255) NOT NULL,
    age INT,
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
INSERT INTO users (vk_id, name, surname, email, is_admin) 
VALUES ('admin', 'Администратор', '', 'admin@kids-contests.ru', 1);
