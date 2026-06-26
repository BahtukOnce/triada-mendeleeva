CREATE TABLE IF NOT EXISTS tournament_participants (
    tournament_id INT NOT NULL,
    player_id INT NOT NULL,
    state ENUM('confirmed','invited','declined') NOT NULL DEFAULT 'confirmed',
    source VARCHAR(20) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (tournament_id, player_id),
    KEY idx_tp_player (player_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
