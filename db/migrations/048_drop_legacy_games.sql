-- Исторические игры теперь хранятся как настоящие games/game_seats (вечера),
-- отдельное хранилище legacy_games больше не нужно (elo_recompute читает games).
DROP TABLE IF EXISTS legacy_game_seats;
DROP TABLE IF EXISTS legacy_games;
