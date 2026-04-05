-- Миграция: проверка рисунков и комментарии по исправлениям

ALTER TABLE participants
    ADD COLUMN drawing_compliant TINYINT(1) DEFAULT 1 AFTER drawing_file,
    ADD COLUMN drawing_comment TEXT NULL AFTER drawing_compliant;
