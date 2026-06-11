-- Удаление дублей рейтингов (оставляем строку с минимальным id;
-- каскад чистит их пустые rating_days/rating_cache)
DELETE r1 FROM ratings r1 JOIN ratings r2 ON r1.title = r2.title AND r1.id > r2.id
