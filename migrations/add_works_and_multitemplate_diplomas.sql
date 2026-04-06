-- Работы, статусы работ и мультишаблонные дипломы

CREATE TABLE IF NOT EXISTS works (
    id INT AUTO_INCREMENT PRIMARY KEY,
    contest_id INT NOT NULL,
    application_id INT NOT NULL,
    participant_id INT NOT NULL,
    title VARCHAR(255) DEFAULT NULL,
    image_path VARCHAR(255) DEFAULT NULL,
    status ENUM('pending','accepted','reviewed') NOT NULL DEFAULT 'pending',
    reviewed_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_participant_work (participant_id),
    KEY idx_application (application_id),
    KEY idx_status (status),
    CONSTRAINT fk_works_contest FOREIGN KEY (contest_id) REFERENCES contests(id) ON DELETE CASCADE,
    CONSTRAINT fk_works_application FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE,
    CONSTRAINT fk_works_participant FOREIGN KEY (participant_id) REFERENCES participants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS diploma_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    contest_id INT NULL,
    template_type VARCHAR(64) NOT NULL,
    title VARCHAR(255) NOT NULL,
    subtitle VARCHAR(255) DEFAULT NULL,
    body_text TEXT,
    award_text TEXT,
    contest_name_text VARCHAR(255) DEFAULT NULL,
    easter_text TEXT,
    signature_1 VARCHAR(255) DEFAULT NULL,
    signature_2 VARCHAR(255) DEFAULT NULL,
    position_1 VARCHAR(255) DEFAULT NULL,
    position_2 VARCHAR(255) DEFAULT NULL,
    footer_text TEXT,
    city VARCHAR(120) DEFAULT NULL,
    issue_date DATE DEFAULT NULL,
    diploma_prefix VARCHAR(32) DEFAULT 'DIPL',
    show_date TINYINT(1) NOT NULL DEFAULT 1,
    show_number TINYINT(1) NOT NULL DEFAULT 1,
    show_signatures TINYINT(1) NOT NULL DEFAULT 1,
    show_background TINYINT(1) NOT NULL DEFAULT 1,
    show_frame TINYINT(1) NOT NULL DEFAULT 1,
    layout_json LONGTEXT,
    styles_json LONGTEXT,
    assets_json LONGTEXT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_template_type (template_type),
    KEY idx_contest (contest_id),
    UNIQUE KEY uniq_contest_type (contest_id, template_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE participant_diplomas
    ADD COLUMN IF NOT EXISTS work_id INT NULL AFTER participant_id,
    ADD COLUMN IF NOT EXISTS diploma_type VARCHAR(64) NOT NULL DEFAULT 'contest_participant' AFTER user_id,
    ADD COLUMN IF NOT EXISTS template_id INT NULL AFTER diploma_type;

CREATE INDEX idx_work ON participant_diplomas(work_id);
CREATE INDEX idx_diploma_type ON participant_diplomas(diploma_type);

UPDATE participant_diplomas SET diploma_type = 'contest_participant' WHERE diploma_type IS NULL OR diploma_type = '';
