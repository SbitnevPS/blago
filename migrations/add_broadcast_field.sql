ALTER TABLE admin_messages ADD COLUMN is_broadcast TINYINT(1) DEFAULT0 AFTER is_read;
ALTER TABLE admin_messages MODIFY COLUMN user_id INT NULL;
UPDATE admin_messages SET is_broadcast =1 WHERE user_id =0 OR user_id IS NULL;
ALTER TABLE admin_messages ADD INDEX idx_is_broadcast (is_broadcast);
