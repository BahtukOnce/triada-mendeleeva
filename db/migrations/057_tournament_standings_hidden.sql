-- Судья может скрыть итоговую таблицу турнира от игроков (баллы не видны до открытия).
ALTER TABLE tournaments ADD COLUMN standings_hidden TINYINT NOT NULL DEFAULT 0;
