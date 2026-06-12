-- Пары ников, которые НЕ предлагать к слиянию (крестик в «Похожие ники»).
CREATE TABLE IF NOT EXISTS merge_ignores (
    a_id INT NOT NULL,
    b_id INT NOT NULL,
    PRIMARY KEY (a_id, b_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
