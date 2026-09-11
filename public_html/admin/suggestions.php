<?php
require dirname(__DIR__, 2) . '/inc/bootstrap.php';
require_once ROOT . '/inc/bot_lib.php';   // уведомляем автора идеи в личку бота
$u = require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int)($_POST['id'] ?? 0);
    $form = (string)($_POST['form'] ?? '');
    if ($form === 'update') {
        $status = (string)($_POST['status'] ?? 'new');
        $status = in_array($status, ['new', 'planned', 'done', 'declined'], true) ? $status : 'new';
        $note = trim((string)($_POST['admin_note'] ?? '')) ?: null;

        // Состояние ДО правки — чтобы дёргать автора только при реальном изменении,
        // а не на каждое нажатие «Сохранить».
        $prev = db()->prepare('SELECT user_id, nickname, body, status, admin_note FROM suggestions WHERE id = ?');
        $prev->execute([$id]);
        $before = $prev->fetch() ?: null;

        db()->prepare('UPDATE suggestions SET status = ?, admin_note = ? WHERE id = ?')
            ->execute([$status, $note, $id]);
        log_action((int)$u['id'], 'suggestion_update', ['id' => $id, 'status' => $status]);

        // Автор узнаёт судьбу своей идеи: колокольчик на сайте + личка бота.
        $changed = $before && ((string)$before['status'] !== $status
            || (string)($before['admin_note'] ?? '') !== (string)($note ?? ''));
        if ($changed && $before && (int)($before['user_id'] ?? 0) > 0) {
            $authorId = (int)$before['user_id'];
            $head = [
                'done'     => '✅ Ваше предложение выполнено',
                'planned'  => '📌 Ваше предложение взято в планы',
                'declined' => '🚫 Ваше предложение отклонено',
                'new'      => '💡 Ваше предложение снова на рассмотрении',
            ][$status] ?? '💡 Статус вашего предложения обновлён';
            $short = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)$before['body'])), 0, 90);
            try {
                app_notify($authorId, $head . ': «' . $short . '…»', '/suggest.php');
                if (bot_token() !== '') {
                    $tg = db()->prepare('SELECT tg_user_id FROM users WHERE id = ? AND tg_user_id IS NOT NULL');
                    $tg->execute([$authorId]);
                    $tgId = (int)($tg->fetchColumn() ?: 0);
                    if ($tgId > 0) {
                        $msg = '<b>' . bot_esc($head) . "</b>\n\n"
                            . '«' . bot_esc($short) . "…»\n"
                            . ($note !== null ? "\n💬 Ответ: " . bot_esc($note) . "\n" : '')
                            . "\nСпасибо, что помогаете сайту становиться лучше.";
                        bot_send($tgId, $msg);
                    }
                }
            } catch (Throwable $e) {
            }
        }
        flash_set('ok', $changed ? 'Обновлено, автор уведомлён' : 'Обновлено');
    } elseif ($form === 'delete') {
        db()->prepare('DELETE FROM suggestions WHERE id = ?')->execute([$id]);
        log_action((int)$u['id'], 'suggestion_delete', ['id' => $id]);
        flash_set('ok', 'Удалено');
    }
    redirect('/admin/suggestions.php');
}

$list = db_ready() ? db()->query('SELECT * FROM suggestions ORDER BY FIELD(status,\'new\',\'planned\',\'done\',\'declined\'), created_at DESC LIMIT 200')->fetchAll() : [];
// «новое» вместо «на рассмотрении»: именно этот статус считает значок в админке, и
// подпись должна отвечать на вопрос «почему горит» — оно новое, пока не разобрано.
$statusLabel = ['new' => 'новое', 'planned' => 'в планах', 'done' => 'сделано', 'declined' => 'отклонено'];
// Цвет бейджа статуса в списке: новое — акцент (горит), сделано — зелёный, остальное приглушённо.
$statusColor = ['new' => 'var(--ac)', 'planned' => 'var(--tx)', 'done' => 'var(--ok)', 'declined' => 'var(--tx3)'];

page_head('Админка — предложения', '');
echo '<p><a href="/admin/">← Админка</a></p><h1>Предложения по сайту</h1>';

if (!$list) {
    empty_state('Предложений пока нет', 'Когда участники начнут присылать идеи, они появятся здесь.');
    page_foot();
    exit;
}

foreach ($list as $s) {
    $stColor = $statusColor[$s['status']] ?? 'var(--tx2)';
    echo '<div class="card" style="padding:12px 14px;margin-bottom:10px;">';
    // Шапка одной строкой: ник + дата слева, цветной бейдж статуса справа.
    echo '<div style="display:flex;justify-content:space-between;gap:10px;align-items:baseline;margin-bottom:5px;">';
    echo '<div style="min-width:0;"><b>' . esc($s['nickname'] ?? '—') . '</b>'
        . '<span style="font-size:11.5px;color:var(--tx3);margin-left:8px;">' . date('d.m.Y H:i', strtotime($s['created_at'])) . '</span></div>';
    echo '<span style="font-size:11px;font-weight:700;letter-spacing:.3px;color:' . $stColor . ';white-space:nowrap;">'
        . mb_strtoupper($statusLabel[$s['status']]) . '</span>';
    echo '</div>';
    // Текст идеи.
    echo '<div style="margin-bottom:8px;line-height:1.5;">' . nl2br(esc($s['body'])) . '</div>';
    // Картинки — компактные превью.
    if (!empty($s['images'])) {
        $imgs = json_decode((string)$s['images'], true);
        if (is_array($imgs) && $imgs) {
            echo '<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:8px;">';
            foreach ($imgs as $iu) {
                if (is_string($iu) && strncmp($iu, '/uploads/', 9) === 0) {
                    echo '<a href="' . esc($iu) . '" target="_blank" rel="noopener"><img src="' . esc($iu) . '" alt="" loading="lazy" style="width:72px;height:72px;object-fit:cover;border-radius:6px;border:1px solid var(--bd);"></a>';
                }
            }
            echo '</div>';
        }
    }
    // Управление одной строкой: статус + ответ + Сохранить, и справа отдельной формой — «✕»
    // (удаление). Удаление всё ещё отдельная форма с confirm() — но компактным значком, а не
    // блоком с разделителем: подтверждение и без черты защищает от случайного клика.
    $confirmTxt = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)$s['body'])), 0, 60);
    echo '<div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;">';
    echo '<form method="post" action="/admin/suggestions.php" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;flex:1;min-width:0;margin:0;">' . csrf_field();
    echo '<input type="hidden" name="form" value="update"><input type="hidden" name="id" value="' . (int)$s['id'] . '">';
    echo '<select name="status" style="background:var(--sf2);color:var(--tx);border:1px solid var(--bd);border-radius:7px;padding:5px 8px;font-size:13px;">';
    foreach ($statusLabel as $sk => $sl) {
        echo '<option value="' . $sk . '" ' . ($s['status'] === $sk ? 'selected' : '') . '>' . $sl . '</option>';
    }
    echo '</select>';
    echo '<input type="text" name="admin_note" placeholder="ответ (необязательно)" value="' . esc($s['admin_note']) . '" style="flex:1;min-width:140px;background:var(--sf2);color:var(--tx);border:1px solid var(--bd);border-radius:7px;padding:5px 9px;font-size:13px;">';
    echo '<button class="btn" style="padding:5px 13px;font-size:13px;" type="submit">Сохранить</button>';
    echo '</form>';
    echo '<form method="post" action="/admin/suggestions.php" style="margin:0;" onsubmit="return confirm(\'Удалить предложение безвозвратно?\\n\\n'
        . esc(addslashes($confirmTxt)) . '…\');">' . csrf_field();
    echo '<input type="hidden" name="form" value="delete"><input type="hidden" name="id" value="' . (int)$s['id'] . '">';
    echo '<button class="btn btn-ghost" style="padding:5px 10px;font-size:14px;line-height:1;color:var(--ac);" type="submit" title="Удалить предложение">✕</button></form>';
    echo '</div>';
    echo '</div>';
}
page_foot();
