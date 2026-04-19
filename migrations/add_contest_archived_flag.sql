ALTER TABLE contests
    ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0 AFTER is_published,
    ADD INDEX idx_is_archived (is_archived);
