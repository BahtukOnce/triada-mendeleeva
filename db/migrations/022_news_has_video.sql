-- Признак: в посте есть нативное видео Telegram (файл недоступен в превью, ведём в Telegram).
ALTER TABLE news ADD COLUMN has_video TINYINT NOT NULL DEFAULT 0
