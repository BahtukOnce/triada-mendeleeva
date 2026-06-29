-- Кол-во кругов турнира: сколько партий сыграет каждый игрок (= партий на каждом столе).
-- Всего игр в турнире = столов × кругов.
ALTER TABLE tournaments ADD COLUMN rounds SMALLINT NOT NULL DEFAULT 1;
