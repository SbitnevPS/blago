CREATE TABLE IF NOT EXISTS email_blacklist (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    reason VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mailing_campaigns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    subject VARCHAR(255) NOT NULL,
    body LONGTEXT NULL,
    filters_json LONGTEXT NULL,
    include_blacklist TINYINT(1) NOT NULL DEFAULT 0,
    contest_id INT NULL,
    min_participants INT NOT NULL DEFAULT 0,
    selection_mode ENUM('all','none') NOT NULL DEFAULT 'all',
    exclusions_json LONGTEXT NULL,
    inclusions_json LONGTEXT NULL,
    total_recipients INT NOT NULL DEFAULT 0,
    sent_count INT NOT NULL DEFAULT 0,
    failed_count INT NOT NULL DEFAULT 0,
    status ENUM('draft','saved','running','stopped','completed') NOT NULL DEFAULT 'draft',
    created_by INT NOT NULL,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_status (status),
    KEY idx_created_by (created_by),
    KEY idx_created_at (created_at),
    CONSTRAINT fk_mailing_campaigns_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mailing_attachments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    mailing_id INT NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(120) NULL,
    file_size INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_mailing_id (mailing_id),
    CONSTRAINT fk_mailing_attachments_campaign FOREIGN KEY (mailing_id) REFERENCES mailing_campaigns(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mailing_send_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    mailing_id INT NOT NULL,
    user_id INT NOT NULL,
    email VARCHAR(255) NOT NULL,
    send_status ENUM('sent','failed','skipped') NOT NULL,
    error_message TEXT NULL,
    sent_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_mailing_user (mailing_id, user_id),
    KEY idx_mailing_status (mailing_id, send_status),
    CONSTRAINT fk_mailing_send_logs_campaign FOREIGN KEY (mailing_id) REFERENCES mailing_campaigns(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
