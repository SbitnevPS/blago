ALTER TABLE vk_publication_tasks
    ADD COLUMN IF NOT EXISTS donation_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER vk_post_url,
    ADD COLUMN IF NOT EXISTS donation_goal_id INT NULL AFTER donation_enabled,
    ADD COLUMN IF NOT EXISTS vk_donate_id VARCHAR(64) NULL AFTER donation_goal_id,
    ADD INDEX idx_donation_goal_id (donation_goal_id),
    ADD INDEX idx_vk_donate_id (vk_donate_id);

ALTER TABLE vk_publication_task_items
    ADD COLUMN IF NOT EXISTS donation_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER vk_donut_settings_snapshot,
    ADD COLUMN IF NOT EXISTS donation_goal_id INT NULL AFTER donation_enabled,
    ADD COLUMN IF NOT EXISTS vk_donate_id VARCHAR(64) NULL AFTER donation_goal_id,
    ADD INDEX idx_donation_goal_id (donation_goal_id),
    ADD INDEX idx_vk_donate_id (vk_donate_id);
