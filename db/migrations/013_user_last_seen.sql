-- «В сети»: отметка времени последнего запроса пользователя.
ALTER TABLE users ADD COLUMN last_seen TIMESTAMP NULL
