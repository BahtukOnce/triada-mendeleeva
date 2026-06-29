-- Новый статус турнира «сверка результатов» между «идёт» и «завершён».
ALTER TABLE tournaments MODIFY COLUMN status
    ENUM('draft','announced','reg_open','live','review','finished') NOT NULL DEFAULT 'draft';
