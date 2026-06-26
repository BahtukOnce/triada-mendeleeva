UPDATE tournament_participants tp JOIN players p ON p.id = tp.player_id SET tp.notified = 1 WHERE tp.state = 'invited' AND p.tg_user_id IS NOT NULL;
