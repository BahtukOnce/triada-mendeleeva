-- Флаги разовых уведомлений по вечеру:
--   table_ready_at   — «стол собрался» (12+ на 4+ часа) уже разослан записавшимся;
--   reminder_sent_at — напоминание в день игры уже разослано.
ALTER TABLE game_days
    ADD COLUMN table_ready_at TIMESTAMP NULL,
    ADD COLUMN reminder_sent_at TIMESTAMP NULL;
