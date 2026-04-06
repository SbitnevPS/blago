-- Блокировка чатов оспаривания по заявкам
ALTER TABLE applications
    ADD COLUMN dispute_chat_closed TINYINT(1) NOT NULL DEFAULT 0 AFTER allow_edit;

ALTER TABLE applications
    ADD INDEX idx_dispute_chat_closed (dispute_chat_closed);
