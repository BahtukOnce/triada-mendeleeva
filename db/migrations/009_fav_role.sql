ALTER TABLE players ADD COLUMN fav_role ENUM('civ','maf','sheriff','don') NULL;
ALTER TABLE players ADD COLUMN is_rhtu TINYINT NOT NULL DEFAULT 0
