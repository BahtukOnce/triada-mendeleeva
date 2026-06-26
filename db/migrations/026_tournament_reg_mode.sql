ALTER TABLE tournaments ADD COLUMN reg_mode ENUM('open','closed') NOT NULL DEFAULT 'open';
