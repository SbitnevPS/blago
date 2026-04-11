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
    INDEX idx_editor_uploads_contest (contest_id),
    INDEX idx_editor_uploads_uploaded_by (uploaded_by),
    CONSTRAINT fk_editor_uploads_contest FOREIGN KEY (contest_id) REFERENCES contests(id) ON DELETE SET NULL,
    CONSTRAINT fk_editor_uploads_user FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
