ALTER TABLE vk_publication_tasks
  ADD COLUMN publication_type VARCHAR(32) NOT NULL DEFAULT 'standard',
  ADD COLUMN resolved_mode VARCHAR(32) NULL,
  ADD COLUMN capability_status VARCHAR(32) NOT NULL DEFAULT 'not_checked',
  ADD COLUMN failure_stage VARCHAR(64) NULL,
  ADD COLUMN response_payload_json LONGTEXT NULL;

ALTER TABLE vk_publication_task_items
  ADD COLUMN publication_type VARCHAR(32) NOT NULL DEFAULT 'standard',
  ADD COLUMN resolved_mode VARCHAR(32) NULL,
  ADD COLUMN capability_status VARCHAR(32) NOT NULL DEFAULT 'not_checked',
  ADD COLUMN failure_stage VARCHAR(64) NULL,
  ADD COLUMN request_payload_json LONGTEXT NULL,
  ADD COLUMN response_payload_json LONGTEXT NULL,
  ADD COLUMN verification_status VARCHAR(64) NULL,
  ADD COLUMN verification_message TEXT NULL,
  ADD COLUMN detected_mode VARCHAR(64) NULL,
  ADD COLUMN detected_features_json LONGTEXT NULL,
  ADD COLUMN vk_post_readback_json LONGTEXT NULL;
