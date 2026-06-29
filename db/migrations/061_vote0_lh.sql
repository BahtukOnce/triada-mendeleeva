-- Второй ЛХ — заголосованного на нулевом круге (одиночное голосование): называет 3 места.
-- Независим от ЛХ первоубиенного ночью (bm_seat1..3). Чёрные с ЛХ ничего не получают.
ALTER TABLE games ADD COLUMN vote0_seat TINYINT NULL;
ALTER TABLE games ADD COLUMN vote0_bm1 TINYINT NULL;
ALTER TABLE games ADD COLUMN vote0_bm2 TINYINT NULL;
ALTER TABLE games ADD COLUMN vote0_bm3 TINYINT NULL;
