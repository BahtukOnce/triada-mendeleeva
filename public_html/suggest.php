<?php
require dirname(__DIR__) . '/inc/bootstrap.php';

$u = require_login();
$player = current_player();
$myNick = $player['nickname'] ?? $u['nickname'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $body = trim((string)($_POST['body'] ?? ''));
    if (mb_strlen($body) < 5) {
        flash_set('err', 'Опишите идею чуть подробнее (минимум 5 символов)');
    } elseif (mb_strlen($body) > 2000) {
        flash_set('err', 'Слишком длинно (максимум 2000 символов)');
    } else {
        db()->prepare('INSERT INTO suggestions (user_id, nickname, body) VALUES (?,?,?)')
            ->execute([(int)$u['id'], $myNick, $body]);
        log_action((int)$u['id'], 'suggestion_add');
        flash_set('ok', 'Спасибо! Ваше предложение отправлено администрации клуба.');
        redirect('/suggest.php');
    }
    redirect('/suggest.php');
}

// Мои предложения
$st = db()->prepare('SELECT * FROM suggestions WHERE user_id = ? ORDER BY created_at DESC LIMIT 30');
$st->execute([(int)$u['id']]);
$mine = $st->fetchAll();

$statusLabel = ['new' => 'на рассмотрении', 'planned' => 'в планах', 'done' => 'сделано', 'declined' => 'отклонено'];
$statusTag = ['new' => '', 'planned' => 'tag-open', 'done' => 'tag-ok', 'declined' => ''];

page_head('Предложить идею', '');
echo '<h1>Предложения по сайту</h1>';
echo '<p style="color:var(--tx2);margin-top:-6px;">Есть идея, как улучшить сайт или клуб? Напишите — администрация увидит каждое предложение.</p>';

echo '<div class="card">';
echo '<form method="post" action="/suggest.php">' . csrf_field();
echo '<div class="field"><label>Ваша идея</label><textarea name="body" rows="5" required placeholder="Например: добавить статистику по месяцам, или…"></textarea></div>';
echo '<button class="btn" type="submit">Отправить</button>';
echo '</form></div>';

if ($mine) {
    echo '<div class="card"><h2 style="margin-top:0;">Мои предложения</h2>';
    foreach ($mine as $s) {
        echo '<div style="border-left:2px solid var(--bd);padding:4px 0 4px 12px;margin:10px 0;">';
        echo '<div style="display:flex;justify-content:space-between;gap:10px;align-items:center;">';
        echo '<span style="font-size:12px;color:var(--tx2);">' . date('d.m.Y', strtotime($s['created_at'])) . '</span>';
        echo '<span class="tag ' . $statusTag[$s['status']] . '">' . $statusLabel[$s['status']] . '</span></div>';
        echo '<div style="margin-top:4px;">' . nl2br(esc($s['body'])) . '</div>';
        if ($s['admin_note']) {
            echo '<div style="margin-top:6px;font-size:13px;color:var(--ac);">Ответ: ' . esc($s['admin_note']) . '</div>';
        }
        echo '</div>';
    }
    echo '</div>';
}
page_foot();
