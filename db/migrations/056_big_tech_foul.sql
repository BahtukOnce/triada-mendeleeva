-- Большой технический фол: −0.6 за каждый, максимум 2 (отдельно от обычного тех.фола −0.3).
ALTER TABLE game_seats ADD COLUMN big_tech TINYINT NOT NULL DEFAULT 0;
