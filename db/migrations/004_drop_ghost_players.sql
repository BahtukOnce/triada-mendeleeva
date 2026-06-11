DELETE FROM players WHERE user_id IS NULL AND id NOT IN (SELECT DISTINCT player_id FROM game_seats) AND id NOT IN (SELECT DISTINCT judge_player_id FROM games WHERE judge_player_id IS NOT NULL)
