-- Хронология игры (JSON: круги, голосования, ничьи/подъёмы, ночные отстрелы) и
-- отдельный «ЛХ-мейкер» (lh_seat) — обычно = ПУ (первоубиенный ночью), но при
-- одиночном голосовании на нулевом круге право ЛХ получает заголосованный.
ALTER TABLE games ADD COLUMN chronology TEXT NULL;
ALTER TABLE games ADD COLUMN lh_seat TINYINT NULL;
