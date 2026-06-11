ALTER TABLE players ADD COLUMN elo DECIMAL(7,1) NOT NULL DEFAULT 1000;

CREATE TABLE IF NOT EXISTS elo_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    player_id INT NOT NULL,
    game_id INT NULL,
    gdate DATE NULL,
    elo_after DECIMAL(7,1) NOT NULL,
    delta DECIMAL(6,1) NOT NULL,
    KEY idx_eh_player (player_id, id),
    CONSTRAINT fk_eh_player FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
