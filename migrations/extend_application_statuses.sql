-- Расширяем статусы заявок для повторной проверки и отмены
ALTER TABLE applications
    MODIFY COLUMN status ENUM('draft', 'submitted', 'approved', 'rejected', 'cancelled', 'corrected') DEFAULT 'draft';
