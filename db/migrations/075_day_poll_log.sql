-- Журнал голосования «Когда играем?»: каждое действие с временем, ничего не удаляется.
-- vote = проголосовал за день, unvote = передумал (снял голос).
CREATE TABLE IF NOT EXISTS day_poll_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    poll_id INT NOT NULL,
    option_id INT NOT NULL,
    player_id INT NOT NULL,
    action ENUM('vote','unvote') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_dpl_poll (poll_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
