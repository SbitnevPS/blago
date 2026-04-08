ALTER TABLE contests
    ADD COLUMN IF NOT EXISTS cover_image VARCHAR(255) NULL AFTER document_file,
    ADD COLUMN IF NOT EXISTS theme_style VARCHAR(50) NOT NULL DEFAULT 'blue' AFTER cover_image;

UPDATE contests
SET theme_style = 'blue'
WHERE theme_style IS NULL OR theme_style = '';
