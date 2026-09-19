-- Черновики протокола вечера (решение руководителя: «если игры внесены неверно, пусть сохраняются
-- в виде черновиков»). Раньше игра с ошибкой (не тот расклад ролей, нет победителя, игрок дважды за
-- столом…) не сохранялась вовсе: введённое жило только в сессии браузера судьи и терялось при уходе
-- со страницы. Теперь оно целиком ложится сюда черновиком вечера и доступно любому судье.
--
-- Черновик — НЕ игра: в рейтинг, ELO и статистику не попадает и новых игроков не заводит — хранятся
-- только сырые поля формы (JSON) и текст ошибок. Исправили и сохранили — черновик удаляется, а игра
-- появляется (или обновляется, если черновик был правкой существующей игры, game_id).

CREATE TABLE IF NOT EXISTS protocol_drafts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    day_id INT NOT NULL,
    game_id INT NULL,
    data MEDIUMTEXT NOT NULL,
    errors TEXT NULL,
    updated_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_pd_day (day_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
