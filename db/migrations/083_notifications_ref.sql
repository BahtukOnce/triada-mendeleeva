-- Уведомления о заявках и предложениях пропадают у всех, как только их обработали (решение
-- руководителя: «если заявка уже обработана, пусть она не висит в уведомлениях больше»).
--
-- Колокольчик — отдельная строка на каждого админа с текстом и общей ссылкой; связи с конкретной
-- заявкой не было, поэтому разобранные заявки неделями висели. Теперь уведомление помнит, к чему
-- относится (ref: «app:<id>» — заявка, «sugg:<id>» — предложение), и при обработке удаляется у всех:
-- app_notify_clear() в admin/applications.php (принята, отклонена, дубль, удалена) и
-- admin/suggestions.php (статус сменён с «новое» или удалено).
--
-- Старые уведомления ref не имеют. Сопоставляем их по тексту (форматы join.php,
-- import_applications.php, suggest.php) и оставляем только те, где заявка ещё «новая» или
-- предложение ещё «новое»; остальные — уже разобранные или удалённые — удаляем. У предложений текст
-- одинаков для всех идей автора, поэтому ещё и по времени создания (±2 минуты).
-- Строки разных таблиц сравниваем через CONVERT … COLLATE utf8mb4_general_ci с обеих сторон: без
-- ошибки «Illegal mix of collations», даже если таблицы создавались при разных настройках сервера
-- (упавшая миграция заблокировала бы все следующие).
-- Повторный прогон безопасен: ADD COLUMN/KEY раннер пропускает (1060/1061), UPDATE трогает только
-- ref IS NULL, DELETE — только несопоставленные.

ALTER TABLE notifications ADD COLUMN ref VARCHAR(40) NULL;

ALTER TABLE notifications ADD KEY idx_notif_ref (ref);

UPDATE notifications n
JOIN club_applications a ON a.state = 'new'
    AND CONVERT(n.text USING utf8mb4) COLLATE utf8mb4_general_ci IN (
        CONVERT(CONCAT('🆕 Новая заявка в клуб: ', a.nickname, ' (', a.full_name, ')') USING utf8mb4) COLLATE utf8mb4_general_ci,
        CONVERT(CONCAT('🆕 Новая заявка в клуб (Google-форма): ', a.nickname, ' (', a.full_name, ')') USING utf8mb4) COLLATE utf8mb4_general_ci)
SET n.ref = CONCAT('app:', a.id)
WHERE n.ref IS NULL AND n.text LIKE '%Новая заявка в клуб%';

DELETE FROM notifications WHERE ref IS NULL AND text LIKE '%Новая заявка в клуб%';

UPDATE notifications n
JOIN suggestions s ON s.status = 'new'
    AND CONVERT(n.text USING utf8mb4) COLLATE utf8mb4_general_ci
        = CONVERT(CONCAT('💡 Новое предложение от ', s.nickname) USING utf8mb4) COLLATE utf8mb4_general_ci
    AND ABS(TIMESTAMPDIFF(SECOND, s.created_at, n.created_at)) <= 120
SET n.ref = CONCAT('sugg:', s.id)
WHERE n.ref IS NULL AND n.text LIKE '%Новое предложение от %';

DELETE FROM notifications WHERE ref IS NULL AND text LIKE '%Новое предложение от %';
