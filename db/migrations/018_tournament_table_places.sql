-- Места (площадки) столов турнира: JSON-массив строк, индекс = номер стола минус 1.
ALTER TABLE tournaments ADD COLUMN table_places TEXT NULL
