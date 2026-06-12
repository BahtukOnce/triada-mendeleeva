<?php
require dirname(__DIR__, 2) . '/inc/bootstrap.php';
require ROOT . '/inc/rating.php';
require ROOT . '/inc/elo.php';
$u = require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'merge') {
    csrf_check();
    $srcId = (int)($_POST['src'] ?? 0);
    $dstId = (int)($_POST['dst'] ?? 0);
    if ($srcId === $dstId || !$srcId || !$dstId) {
        flash_set('err', 'Выберите два разных ника');
        redirect('/admin/merge.php');
    }
    $st = db()->prepare('SELECT * FROM players WHERE id IN (?, ?)');
    $st->execute([$srcId, $dstId]);
    $found = [];
    foreach ($st->fetchAll() as $p) {
        $found[(int)$p['id']] = $p;
    }
    if (count($found) !== 2) {
        flash_set('err', 'Игрок не найден');
        redirect('/admin/merge.php');
    }
    $src = $found[$srcId];
    $dst = $found[$dstId];
    // Если у обоих есть аккаунт — аккаунт источника отвяжется (источник удаляется),
    // аккаунт цели остаётся. Сообщим об этом.
    $bothAccts = $src['user_id'] && $dst['user_id'];
    if ($bothAccts) {
        // снимаем привязку аккаунта с источника заранее (на всякий случай)
        $pdo = db();
        $pdo->prepare('UPDATE players SET user_id = NULL WHERE id = ?')->execute([$srcId]);
        $src['user_id'] = null;
    }

    $pdo = db();
    $pdo->beginTransaction();
    // Игровые данные
    $pdo->prepare('UPDATE game_seats SET player_id = ? WHERE player_id = ?')->execute([$dstId, $srcId]);
    $pdo->prepare('UPDATE games SET judge_player_id = ? WHERE judge_player_id = ?')->execute([$dstId, $srcId]);
    // Записи на вечера: дубликаты по уникальному ключу убираем у источника
    $pdo->prepare('DELETE r1 FROM day_registrations r1
        JOIN day_registrations r2 ON r2.day_id = r1.day_id AND r2.player_id = ?
        WHERE r1.player_id = ?')->execute([$dstId, $srcId]);
    $pdo->prepare('UPDATE day_registrations SET player_id = ? WHERE player_id = ?')->execute([$dstId, $srcId]);
    $pdo->prepare('DELETE r1 FROM tournament_regs r1
        JOIN tournament_regs r2 ON r2.tournament_id = r1.tournament_id AND r2.player_id = ?
        WHERE r1.player_id = ?')->execute([$dstId, $srcId]);
    $pdo->prepare('UPDATE tournament_regs SET player_id = ? WHERE player_id = ?')->execute([$dstId, $srcId]);
    $pdo->prepare('UPDATE player_avatars SET player_id = ? WHERE player_id = ?')->execute([$dstId, $srcId]);
    // Аккаунт и анкета: переносим, если у цели пусто
    if ($src['user_id'] && !$dst['user_id']) {
        $pdo->prepare('UPDATE players SET user_id = NULL WHERE id = ?')->execute([$srcId]);
        $pdo->prepare('UPDATE players SET user_id = ? WHERE id = ?')->execute([(int)$src['user_id'], $dstId]);
    }
    foreach (['real_name', 'tg', 'vk', 'faculty', 'study_group', 'birth_date', 'avatar', 'fav_role', 'flair'] as $col) {
        if (empty($dst[$col]) && !empty($src[$col])) {
            $pdo->prepare("UPDATE players SET $col = ? WHERE id = ?")->execute([$src[$col], $dstId]);
        }
    }
    // Если различие было только в эмодзи в нике (напр. «НЕ_ЛИС🦊» → «НЕ_ЛИС») —
    // сохраняем эмодзи источника как «висюльку» цели.
    if (empty($dst['flair']) && empty($src['flair'])) {
        $fromNick = flair_clean((string)$src['nickname']);
        if ($fromNick !== '') {
            $pdo->prepare('UPDATE players SET flair = ? WHERE id = ?')->execute([$fromNick, $dstId]);
        }
    }
    $pdo->prepare('DELETE FROM players WHERE id = ?')->execute([$srcId]);
    $pdo->commit();

    rating_recompute_all();
    elo_recompute();
    log_action((int)$u['id'], 'players_merge', ['src' => $src['nickname'], 'dst' => $dst['nickname']]);
    flash_set('ok', 'Слито: «' . $src['nickname'] . '» → «' . $dst['nickname'] . '». Рейтинг и ELO пересчитаны.'
        . ($bothAccts ? ' Аккаунт ника-источника отвязан (при необходимости удалите его в «Пользователи»).' : ''));
    redirect('/admin/merge.php');
}

// Переименование ника игрока
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'rename') {
    csrf_check();
    $pidR = (int)($_POST['player_id'] ?? 0);
    $newNick = nickname_clean((string)($_POST['new_nick'] ?? ''));
    if (!$pidR || $newNick === '') {
        flash_set('err', 'Выберите игрока и укажите новый ник (без эмодзи)');
        redirect('/admin/merge.php');
    }
    $c = db()->prepare('SELECT id FROM players WHERE LOWER(nickname) = LOWER(?) AND id <> ?');
    $c->execute([$newNick, $pidR]);
    if ($c->fetch()) {
        flash_set('err', 'Ник «' . $newNick . '» уже занят другим игроком — используйте слияние');
        redirect('/admin/merge.php');
    }
    $old = db()->prepare('SELECT nickname FROM players WHERE id = ?');
    $old->execute([$pidR]);
    $oldNick = (string)$old->fetchColumn();
    db()->prepare('UPDATE players SET nickname = ? WHERE id = ?')->execute([$newNick, $pidR]);
    log_action((int)$u['id'], 'player_rename', ['id' => $pidR, 'from' => $oldNick, 'to' => $newNick]);
    flash_set('ok', 'Ник изменён: «' . $oldNick . '» → «' . $newNick . '»');
    redirect('/admin/merge.php');
}

$players = db()->query('SELECT id, nickname,
        (SELECT COUNT(*) FROM game_seats gs WHERE gs.player_id = players.id) AS games,
        user_id
    FROM players ORDER BY nickname')->fetchAll();

// Похожие пары (подсказки)
$norm = function (string $n): string {
    $n = mb_strtolower(trim($n));
    $n = (string)preg_replace('/[^\p{L}\p{N}]/u', '', $n);
    return $n;
};
$pairs = [];
$keys = [];
foreach ($players as $p) {
    $keys[(int)$p['id']] = $norm($p['nickname']);
}
$n = count($players);
for ($i = 0; $i < $n; $i++) {
    for ($j = $i + 1; $j < $n; $j++) {
        $a = $keys[(int)$players[$i]['id']];
        $b = $keys[(int)$players[$j]['id']];
        if ($a === '' || $b === '') {
            continue;
        }
        $lev = levenshtein($a, $b);
        if ($lev <= 1 || str_starts_with($a, $b) || str_starts_with($b, $a)) {
            $pairs[] = [$players[$i], $players[$j]];
        }
    }
}

page_head('Админка — слияние ников', '');
echo '<p><a href="/admin/">← Админка</a></p><h1>Слияние дублей-ников</h1>';
echo '<p style="color:var(--tx2);font-size:14px;margin-top:-6px;">Все игры, судейство, записи и анкета источника переносятся в целевой ник, источник удаляется. Рейтинг и ELO пересчитываются автоматически.</p>';

echo '<div class="card"><h2 style="margin-top:0;">Слить вручную</h2>';
echo '<form method="post" action="/admin/merge.php" onsubmit="return confirm(\'Слить ники? Действие необратимо.\');">' . csrf_field();
echo '<input type="hidden" name="form" value="merge">';
echo '<div style="display:flex;gap:12px;flex-wrap:wrap;align-items:end;">';
$sel = function (string $name, string $label) use ($players) {
    $h = '<div class="field" style="margin:0;flex:1;min-width:220px;"><label>' . $label . '</label>'
        . '<select name="' . $name . '" required style="width:100%;background:var(--sf2);color:var(--tx);border:1px solid var(--bd);border-radius:8px;padding:10px 12px;">'
        . '<option value="">— выбрать —</option>';
    foreach ($players as $p) {
        $h .= '<option value="' . (int)$p['id'] . '">' . esc($p['nickname']) . ' (' . (int)$p['games'] . ' игр'
            . ($p['user_id'] ? ', аккаунт' : '') . ')</option>';
    }
    return $h . '</select></div>';
};
echo $sel('src', 'Источник (будет удалён)');
echo $sel('dst', 'Цель (останется)');
echo '<button class="btn" type="submit">Слить</button>';
echo '</div></form></div>';

// ── Переименование ника ──
echo '<div class="card"><h2 style="margin-top:0;">Переименовать ник игрока</h2>';
echo '<form method="post" action="/admin/merge.php" style="display:flex;gap:12px;flex-wrap:wrap;align-items:end;">' . csrf_field();
echo '<input type="hidden" name="form" value="rename">';
echo '<div class="field" style="margin:0;flex:1;min-width:220px;"><label>Игрок</label>'
    . '<select name="player_id" required style="width:100%;background:var(--sf2);color:var(--tx);border:1px solid var(--bd);border-radius:8px;padding:10px 12px;">'
    . '<option value="">— выбрать —</option>';
foreach ($players as $p) {
    echo '<option value="' . (int)$p['id'] . '">' . esc($p['nickname']) . ' (' . (int)$p['games'] . ' игр)</option>';
}
echo '</select></div>';
echo '<div class="field" style="margin:0;flex:1;min-width:180px;"><label>Новый ник (без эмодзи)</label><input type="text" name="new_nick" required></div>';
echo '<button class="btn" type="submit">Переименовать</button>';
echo '</form></div>';

if ($pairs) {
    echo '<div class="card"><h2 style="margin-top:0;">Похожие ники (' . count($pairs) . ')</h2>';
    echo '<table class="tbl"><tr><th>Ник А</th><th>Ник Б</th><th></th></tr>';
    foreach (array_slice($pairs, 0, 40) as [$a, $b]) {
        echo '<tr><td>' . esc($a['nickname']) . ' <span style="color:var(--tx3);font-size:12px;">(' . (int)$a['games'] . ' игр' . ($a['user_id'] ? ', аккаунт' : '') . ')</span></td>';
        echo '<td>' . esc($b['nickname']) . ' <span style="color:var(--tx3);font-size:12px;">(' . (int)$b['games'] . ' игр' . ($b['user_id'] ? ', аккаунт' : '') . ')</span></td>';
        // Меньший по играм — источник по умолчанию
        [$srcP, $dstP] = ((int)$a['games'] <= (int)$b['games']) ? [$a, $b] : [$b, $a];
        echo '<td><form method="post" action="/admin/merge.php" style="display:inline;" onsubmit="return confirm(\'Слить «' . esc($srcP['nickname']) . '» в «' . esc($dstP['nickname']) . '»?\');">' . csrf_field();
        echo '<input type="hidden" name="form" value="merge"><input type="hidden" name="src" value="' . (int)$srcP['id'] . '"><input type="hidden" name="dst" value="' . (int)$dstP['id'] . '">';
        echo '<button class="btn btn-ghost" style="padding:4px 12px;font-size:12px;" type="submit">«' . esc($srcP['nickname']) . '» → «' . esc($dstP['nickname']) . '»</button></form></td></tr>';
    }
    echo '</table></div>';
} else {
    echo '<p style="color:var(--tx2);">Подозрительно похожих ников не найдено.</p>';
}
page_foot();
