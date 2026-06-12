-- Telegram-бот: привязка tg-аккаунта к игроку (в т.ч. без аккаунта на сайте)
ALTER TABLE players ADD COLUMN tg_user_id BIGINT NULL;
ALTER TABLE players ADD COLUMN tg_username VARCHAR(100) NULL;
ALTER TABLE players ADD COLUMN tg_linked_at TIMESTAMP NULL;
ALTER TABLE players ADD UNIQUE KEY uq_players_tg (tg_user_id);

-- Минимум игр для номинаций (используется и на сайте, и в боте)
INSERT INTO settings (k, v) VALUES ('min_games_nomination', '15') ON DUPLICATE KEY UPDATE k = k;
INSERT INTO settings (k, v) VALUES ('bot_username', '') ON DUPLICATE KEY UPDATE k = k
