ALTER TABLE applications
    ADD COLUMN opened_by_admin TINYINT(1) NOT NULL DEFAULT 0 AFTER allow_edit,
    ADD COLUMN dispute_chat_archived TINYINT(1) NOT NULL DEFAULT 0 AFTER dispute_chat_closed;

ALTER TABLE applications
    ADD INDEX idx_opened_by_admin (opened_by_admin),
    ADD INDEX idx_dispute_chat_archived (dispute_chat_archived);
