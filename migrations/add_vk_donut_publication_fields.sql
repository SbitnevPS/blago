ALTER TABLE vk_publication_tasks
    ADD COLUMN IF NOT EXISTS vk_donut_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER vk_group_id,
    ADD COLUMN IF NOT EXISTS vk_donut_paid_duration INT NULL AFTER vk_donut_enabled,
    ADD COLUMN IF NOT EXISTS vk_donut_can_publish_free_copy TINYINT(1) NOT NULL DEFAULT 0 AFTER vk_donut_paid_duration,
    ADD COLUMN IF NOT EXISTS vk_donut_settings_snapshot LONGTEXT NULL AFTER vk_donut_can_publish_free_copy,
    ADD COLUMN IF NOT EXISTS vk_post_url VARCHAR(255) NULL AFTER vk_donut_settings_snapshot;

ALTER TABLE vk_publication_task_items
    ADD COLUMN IF NOT EXISTS vk_donut_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER vk_post_url,
    ADD COLUMN IF NOT EXISTS vk_donut_paid_duration INT NULL AFTER vk_donut_enabled,
    ADD COLUMN IF NOT EXISTS vk_donut_can_publish_free_copy TINYINT(1) NOT NULL DEFAULT 0 AFTER vk_donut_paid_duration,
    ADD COLUMN IF NOT EXISTS vk_donut_settings_snapshot LONGTEXT NULL AFTER vk_donut_can_publish_free_copy;
