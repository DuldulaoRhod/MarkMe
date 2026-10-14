-- ---------------------------------------------------------------------
-- Feedback management.
--
-- The contact form has been writing to feedback_messages since the storefront
-- was built, but nothing in the back office ever read it: every message a
-- customer sent went into the table and stayed there unseen. This adds the
-- columns a worklist needs - whether a message has been dealt with, by whom,
-- and when - so the admin screen can show what still needs an answer.
--
-- Additive and idempotent. The existing columns are untouched and every
-- message already collected stays exactly as it is, arriving as 'new'.
-- ---------------------------------------------------------------------
USE markme_db;

-- MySQL has no "ADD COLUMN IF NOT EXISTS" before 8.0, and MariaDB's own
-- support varies, so each column is added only when it is missing. Re-running
-- this file is therefore safe.
SET @db := DATABASE();

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = @db AND table_name = 'feedback_messages' AND column_name = 'status') = 0,
    "ALTER TABLE feedback_messages
       ADD COLUMN status ENUM('new','read','archived') NOT NULL DEFAULT 'new' AFTER message",
    'SELECT ''status already present'''
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Who dealt with it. NULL until someone does, and ON DELETE SET NULL so
-- removing a staff account never removes the message itself.
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = @db AND table_name = 'feedback_messages' AND column_name = 'handled_by') = 0,
    'ALTER TABLE feedback_messages
       ADD COLUMN handled_by INT UNSIGNED NULL DEFAULT NULL AFTER status',
    'SELECT ''handled_by already present'''
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = @db AND table_name = 'feedback_messages' AND column_name = 'handled_at') = 0,
    'ALTER TABLE feedback_messages
       ADD COLUMN handled_at TIMESTAMP NULL DEFAULT NULL AFTER handled_by',
    'SELECT ''handled_at already present'''
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- The list is filtered by status and ordered newest first on every page load.
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.statistics
      WHERE table_schema = @db AND table_name = 'feedback_messages' AND index_name = 'idx_feedback_status') = 0,
    'ALTER TABLE feedback_messages
       ADD INDEX idx_feedback_status (status, created_at)',
    'SELECT ''index already present'''
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.key_column_usage
      WHERE table_schema = @db AND table_name = 'feedback_messages'
        AND constraint_name = 'fk_feedback_handled_by') = 0,
    'ALTER TABLE feedback_messages
       ADD CONSTRAINT fk_feedback_handled_by FOREIGN KEY (handled_by)
       REFERENCES users (user_id) ON DELETE SET NULL',
    'SELECT ''foreign key already present'''
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
