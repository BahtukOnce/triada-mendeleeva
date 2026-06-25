-- Судьи турнира: главный судья и судья на каждый стол (JSON-массив id, индекс = стол − 1).
ALTER TABLE tournaments ADD COLUMN main_judge_player_id INT NULL;
ALTER TABLE tournaments ADD COLUMN table_judges TEXT NULL
