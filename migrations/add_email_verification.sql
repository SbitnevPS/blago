ALTER TABLE users
    ADD COLUMN email_verified TINYINT(1) NOT NULL DEFAULT 0 AFTER email,
    ADD COLUMN email_verified_at DATETIME NULL AFTER email_verified,
    ADD COLUMN email_verification_token VARCHAR(255) NULL AFTER email_verified_at,
    ADD COLUMN email_verification_sent_at DATETIME NULL AFTER email_verification_token;

CREATE INDEX idx_users_email_verification_token ON users (email_verification_token);
