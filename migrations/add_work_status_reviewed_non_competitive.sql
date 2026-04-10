ALTER TABLE works
    MODIFY COLUMN status ENUM('pending','accepted','reviewed','reviewed_non_competitive') NOT NULL DEFAULT 'pending';
