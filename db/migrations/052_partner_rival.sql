-- Интерактивные связи профиля: напарник (игровой дуэт) и принципиальный соперник.
-- Самовыбор игрока; «взаимно» если напарник выбрал тебя в ответ. Статистика
-- (вместе/личка) считается из game_seats на лету (логика как в vs.php).
ALTER TABLE players
    ADD COLUMN partner_player_id INT NULL,
    ADD COLUMN rival_player_id INT NULL;
