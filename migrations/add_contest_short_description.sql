ALTER TABLE contests
    ADD COLUMN IF NOT EXISTS short_description VARCHAR(400) NULL AFTER description;
