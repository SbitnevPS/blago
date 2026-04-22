ALTER TABLE applications
    ADD COLUMN agreement_declined TINYINT(1) NOT NULL DEFAULT 0 AFTER allow_edit;
