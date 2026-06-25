-- Привязка новости к посту Telegram-канала (автоимпорт, дедупликация и правки).
ALTER TABLE news ADD COLUMN tg_msg_id BIGINT NULL;
ALTER TABLE news ADD UNIQUE KEY uq_news_tg (tg_msg_id)
