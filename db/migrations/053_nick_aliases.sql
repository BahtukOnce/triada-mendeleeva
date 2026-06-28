-- Постоянные алиасы ников: ручные слияния админа должны ПЕРЕЖИВАТЬ переимпорт
-- исторических игр. nick_key() консультирует эту таблицу, merge.php пишет сюда
-- при каждом слиянии (alias_key ника-источника → canonical_key ника-цели).
CREATE TABLE IF NOT EXISTS nick_aliases (
    alias_key VARCHAR(190) NOT NULL,
    canonical_key VARCHAR(190) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (alias_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
