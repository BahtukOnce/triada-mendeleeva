-- Счётчик просмотров новости.
ALTER TABLE news ADD COLUMN views INT NOT NULL DEFAULT 0
