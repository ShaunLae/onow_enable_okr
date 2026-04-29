-- ONOW Enable — Migration v2
-- Adds created_by column to key_results table
-- Run in phpMyAdmin → select onow_enable_okr → SQL tab

USE onow_enable;

ALTER TABLE key_results
    ADD COLUMN created_by INT NOT NULL AFTER owner_id,
    ADD CONSTRAINT fk_kr_creator
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT;

-- Backfill: set created_by = owner_id for all existing rows
UPDATE key_results SET created_by = owner_id WHERE created_by = 0;

ALTER TABLE objectives
    ADD COLUMN is_public TINYINT(1) NOT NULL DEFAULT 1
    AFTER type;

CREATE INDEX idx_obj_is_public ON objectives(type, is_public);