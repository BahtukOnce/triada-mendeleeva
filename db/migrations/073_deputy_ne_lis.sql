-- Назначение зама руководителя: аккаунт НЕ_ЛИС (решение владельца, 2026-07-15).
UPDATE users SET role = 'deputy' WHERE nickname = 'НЕ_ЛИС' AND role <> 'owner';
