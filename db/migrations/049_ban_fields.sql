-- Бан-лист: причина и кто забанил (banned_at уже есть в players).
ALTER TABLE players ADD COLUMN ban_reason VARCHAR(255) NULL;
ALTER TABLE players ADD COLUMN banned_by INT NULL;
