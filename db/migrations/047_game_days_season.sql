-- Метка сезона у игрового вечера: NULL = текущий сезон сайта,
-- иначе исторический сезон (для фильтра и чтобы не путать с актуальным рейтингом).
ALTER TABLE game_days ADD COLUMN season VARCHAR(24) NULL;
ALTER TABLE game_days ADD KEY idx_gd_season (season);
