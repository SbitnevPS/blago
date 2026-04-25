ALTER TABLE participants
    ADD COLUMN views_count INT NOT NULL DEFAULT 0 AFTER public_number;

UPDATE participants
SET views_count = FLOOR(RAND() * 5001)
WHERE COALESCE(views_count, 0) = 0;
