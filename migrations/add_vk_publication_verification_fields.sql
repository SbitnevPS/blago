ALTER TABLE vk_publication_task_items
    ADD COLUMN verification_status VARCHAR(64) NULL AFTER response_payload_json,
    ADD COLUMN verification_message TEXT NULL AFTER verification_status,
    ADD COLUMN detected_mode VARCHAR(64) NULL AFTER verification_message,
    ADD COLUMN detected_features_json LONGTEXT NULL AFTER detected_mode,
    ADD COLUMN vk_post_readback_json LONGTEXT NULL AFTER detected_features_json;
