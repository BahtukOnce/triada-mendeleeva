-- Обложка новости: путь к скачанной картинке поста Telegram-канала (импорт).
ALTER TABLE news ADD COLUMN image VARCHAR(255) NULL
