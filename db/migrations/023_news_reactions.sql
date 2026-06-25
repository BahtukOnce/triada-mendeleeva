-- Реакции к новостям: один игрок — одна реакция на пост (можно сменить/снять).
CREATE TABLE IF NOT EXISTS news_reactions (
    news_id INT NOT NULL,
    user_id INT NOT NULL,
    emoji VARCHAR(16) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (news_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
