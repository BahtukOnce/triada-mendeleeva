ALTER TABLE users ADD COLUMN is_judge TINYINT NOT NULL DEFAULT 0;
ALTER TABLE users ADD COLUMN is_photographer TINYINT NOT NULL DEFAULT 0;
UPDATE users SET is_judge = 1 WHERE role = 'judge';
UPDATE users SET role = 'player' WHERE role = 'judge'
