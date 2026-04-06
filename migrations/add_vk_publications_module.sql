-- Модуль публикаций работ в VK

CREATE TABLE IF NOT EXISTS vk_publication_tasks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    contest_id INT NULL,
    created_by INT NOT NULL,
    task_status ENUM('draft','ready','publishing','published','partially_failed','failed','archived') NOT NULL DEFAULT 'draft',
    publication_mode ENUM('manual','immediate') NOT NULL DEFAULT 'manual',
    filters_json LONGTEXT NULL,
    summary_json LONGTEXT NULL,
    total_items INT NOT NULL DEFAULT 0,
    ready_items INT NOT NULL DEFAULT 0,
    published_items INT NOT NULL DEFAULT 0,
    failed_items INT NOT NULL DEFAULT 0,
    skipped_items INT NOT NULL DEFAULT 0,
    vk_group_id VARCHAR(64) NULL,
    published_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_status (task_status),
    KEY idx_contest (contest_id),
    KEY idx_created_by (created_by),
    KEY idx_created_at (created_at),
    CONSTRAINT fk_vk_tasks_contest FOREIGN KEY (contest_id) REFERENCES contests(id) ON DELETE SET NULL,
    CONSTRAINT fk_vk_tasks_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS vk_publication_task_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    task_id INT NOT NULL,
    work_id INT NOT NULL,
    application_id INT NOT NULL,
    participant_id INT NOT NULL,
    contest_id INT NOT NULL,
    work_image_path VARCHAR(255) NULL,
    post_text TEXT NULL,
    item_status ENUM('pending','ready','published','failed','skipped') NOT NULL DEFAULT 'pending',
    skip_reason VARCHAR(255) NULL,
    vk_post_id VARCHAR(64) NULL,
    vk_post_url VARCHAR(255) NULL,
    error_message TEXT NULL,
    published_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_task_work (task_id, work_id),
    KEY idx_task (task_id),
    KEY idx_work (work_id),
    KEY idx_status (item_status),
    KEY idx_vk_post (vk_post_id),
    CONSTRAINT fk_vk_items_task FOREIGN KEY (task_id) REFERENCES vk_publication_tasks(id) ON DELETE CASCADE,
    CONSTRAINT fk_vk_items_work FOREIGN KEY (work_id) REFERENCES works(id) ON DELETE CASCADE,
    CONSTRAINT fk_vk_items_application FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE,
    CONSTRAINT fk_vk_items_participant FOREIGN KEY (participant_id) REFERENCES participants(id) ON DELETE CASCADE,
    CONSTRAINT fk_vk_items_contest FOREIGN KEY (contest_id) REFERENCES contests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE works
    ADD COLUMN IF NOT EXISTS vk_published_at DATETIME NULL,
    ADD COLUMN IF NOT EXISTS vk_post_id VARCHAR(64) NULL,
    ADD COLUMN IF NOT EXISTS vk_post_url VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS vk_publish_error TEXT NULL;

CREATE INDEX idx_works_vk_published_at ON works(vk_published_at);
