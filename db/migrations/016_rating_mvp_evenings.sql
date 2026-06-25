-- MVP вечера: сколько раз игрок был лучшим по сумме итогов за один игровой вечер.
-- Заполняется при пересчёте рейтинга (rating_recompute). Бэкфилл: /migrate.php?key=...&rating=1
ALTER TABLE rating_cache ADD COLUMN mvp_evenings INT NOT NULL DEFAULT 0
