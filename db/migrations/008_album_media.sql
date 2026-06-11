ALTER TABLE photos ADD COLUMN kind ENUM('image','video') NOT NULL DEFAULT 'image'
