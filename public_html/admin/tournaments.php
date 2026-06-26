<?php
require dirname(__DIR__, 2) . '/inc/bootstrap.php';
require_once ROOT . '/inc/bot_lib.php';
$u = require_judge();

// Право редактировать турнир: владелец/админ — всегда; судья — только если он
// главный судья этого турнира (его игрок == судья стола 1 == main_judge_player_id).
$isAdmin = role_level($u['role']) >= 3;
$myPid = 0;
if (db_ready()) {
    $mp = db()->prepare('SELECT id FROM players WHERE user_id = ? LIMIT 1');
    $mp->execute([(int)$u['id']]);
    $myPid = (int)($mp->fetchColumn() ?: 0);
}
$canEditT = function (?array $t) use ($isAdmin, $myPid): bool {
    if ($isAdmin) {
        return true;
    }
    if (!$t) {
        return true; // новый турнир может создать любой судья
    }
    return $myPid > 0 && (int)($t['main_judge_player_id'] ?? 0) === $myPid;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $form = (string)($_POST['form'] ?? '');
    $id = (int)($_POST['id'] ?? 0);

    // Любые операции над существующим турниром — только тому, кто вправе его редактировать
    if ($id) {
        $gate = db()->prepare('SELECT main_judge_player_id FROM tournaments WHERE id = ?');
        $gate->execute([$id]);
        $gateRow = $gate->fetch();
        if ($gateRow && !$canEditT($gateRow)) {
            flash_set('err', 'Редактировать этот турнир может только его главный судья');
            redirect('/tournament.php?id=' . $id);
        }
    }

    if ($form === 'save') {
        $title = trim((string)($_POST['title'] ?? ''));
        if ($title === '') {
            flash_set('err', 'Название пустое');
            redirect('/admin/tournaments.php' . ($id ? '?edit=' . $id : ''));
        }
        $df = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['date_from'] ?? '')) ? $_POST['date_from'] : null;
        $dt = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['date_to'] ?? '')) ? $_POST['date_to'] : null;
        $locSel = (string)($_POST['location_sel'] ?? '');
        $loc = $locSel === '__other' ? trim((string)($_POST['location_other'] ?? '')) : trim($locSel);
        $loc = $loc !== '' ? $loc : null;
        $desc = trim((string)($_POST['description'] ?? '')) ?: null;
        $status = in_array($_POST['status'] ?? '', ['draft', 'announced', 'reg_open', 'live', 'finished'], true) ? $_POST['status'] : 'draft';
        $tables = max(1, min(6, (int)($_POST['tables_count'] ?? 1)));
        $regMode = (($_POST['reg_mode'] ?? 'open') === 'closed') ? 'closed' : 'open';

        // Места столов: позиционный массив длиной tables_count (индекс = стол − 1)
        $tp = (array)($_POST['table_places'] ?? []);
        $places = [];
        for ($i = 0; $i < $tables; $i++) {
            $places[] = trim((string)($tp[$i] ?? ''));
        }
        $placesJson = array_filter($places) ? json_encode($places, JSON_UNESCAPED_UNICODE) : null;

        // Судьи столов (позиционный массив id, индекс = стол − 1); судья стола 1 = главный
        $tj = (array)($_POST['table_judges'] ?? []);
        $judges = [];
        for ($i = 0; $i < $tables; $i++) {
            $judges[] = (int)($tj[$i] ?? 0) ?: null;
        }
        $mainJudge = $judges[0] ?? null;
        $judgesJson = array_filter($judges) ? json_encode($judges) : null;

        // судьи, назначенные ДО сохранения — чтобы уведомить только новоназначенных
        $oldJudgeSet = [];
        if ($id) {
            $oj = db()->prepare('SELECT table_judges FROM tournaments WHERE id = ?');
            $oj->execute([$id]);
            $ojv = $oj->fetchColumn();
            if ($ojv) {
                $dec = json_decode((string)$ojv, true);
                if (is_array($dec)) {
                    $oldJudgeSet = array_map('intval', array_filter($dec));
                }
            }
        }

        if ($id) {
            db()->prepare('UPDATE tournaments SET title=?, date_from=?, date_to=?, location=?, description=?, status=?, tables_count=?, table_places=?, main_judge_player_id=?, table_judges=?, reg_mode=? WHERE id=?')
                ->execute([$title, $df, $dt, $loc, $desc, $status, $tables, $placesJson, $mainJudge, $judgesJson, $regMode, $id]);
        } else {
            db()->prepare('INSERT INTO tournaments (title, date_from, date_to, location, description, status, tables_count, table_places, main_judge_player_id, table_judges, reg_mode) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$title, $df, $dt, $loc, $desc, $status, $tables, $placesJson, $mainJudge, $judgesJson, $regMode]);
            $id = (int)db()->lastInsertId();
        }
        $cropped = (string)($_POST['logo_cropped'] ?? '');
        if (preg_match('#^data:image/(jpeg|png);base64,#', $cropped)) {
            // Логотип из мини-редактора (обрезка под круг)
            $bin = base64_decode(substr($cropped, strpos($cropped, ',') + 1), true);
            if ($bin !== false && strlen($bin) > 100 && strlen($bin) < 8 * 1024 * 1024) {
                $dir = ROOT . '/public_html/uploads/tournaments';
                if (!is_dir($dir)) {
                    @mkdir($dir, 0775, true);
                }
                $base = 't' . $id . '_' . time();
                $rel = '/uploads/tournaments/' . $base . '.jpg';
                if (@file_put_contents($dir . '/' . $base . '.jpg', $bin) !== false) {
                    db()->prepare('UPDATE tournaments SET logo = ? WHERE id = ?')->execute([$rel, $id]);
                } else {
                    flash_set('err', 'Лого: не удалось сохранить');
                }
            } else {
                flash_set('err', 'Лого: картинка не распозналась');
            }
        } elseif (!empty($_FILES['logo']['name'])) {
            $res = save_image_upload($_FILES['logo'], 'tournaments', 't' . $id, 400);
            if (is_string($res) && str_starts_with($res, '/uploads/')) {
                db()->prepare('UPDATE tournaments SET logo = ? WHERE id = ?')->execute([$res, $id]);
            } else {
                flash_set('err', 'Лого: ' . $res);
            }
        }
        // Уведомить новоназначенных судей (бот + сайт) — раньше не были в судейской панели
        foreach ($judges as $ji => $jid) {
            if (!$jid || in_array((int)$jid, $oldJudgeSet, true)) {
                continue;
            }
            try {
                bot_tournament_judge_notify($id, (int)$jid, $ji + 1);
                app_notify_player((int)$jid, '⚖ Тебя назначили судьёй на турнир «' . $title . '» — ' . ($ji === 0 ? 'главный судья' : 'стол ' . ($ji + 1)), '/tournament.php?id=' . $id);
            } catch (Throwable $e) {
            }
        }
        log_action((int)$u['id'], 'tournament_save', ['id' => $id]);
        flash_set('ok', 'Турнир сохранён');
        redirect('/admin/tournaments.php?edit=' . $id);
    }

    if ($form === 'delete' && $id) {
        db()->prepare('DELETE FROM tournaments WHERE id = ?')->execute([$id]);
        rating_recompute_all_safe();
        log_action((int)$u['id'], 'tournament_delete', ['id' => $id]);
        flash_set('ok', 'Турнир удалён');
        redirect('/admin/tournaments.php');
    }

    if (in_array($form, ['roster_add', 'roster_invite', 'roster_confirm', 'roster_remove'], true) && $id) {
        $pid = (int)($_POST['player_id'] ?? 0);
        if ($pid) {
            if ($form === 'roster_remove') {
                db()->prepare('DELETE FROM tournament_participants WHERE tournament_id=? AND player_id=?')->execute([$id, $pid]);
                flash_set('ok', 'Игрок убран из состава');
            } elseif ($form === 'roster_invite') {
                db()->prepare("INSERT INTO tournament_participants (tournament_id, player_id, state, source) VALUES (?,?,'invited','admin')
                    ON DUPLICATE KEY UPDATE state='invited'")->execute([$id, $pid]);
                $sent = bot_tournament_invite($id, $pid);
                $tt = db()->prepare('SELECT title FROM tournaments WHERE id = ?');
                $tt->execute([$id]);
                app_notify_player($pid, '🎟 Тебя пригласили на турнир «' . (string)($tt->fetchColumn() ?: 'турнир') . '»', '/tournament.php?id=' . $id);
                flash_set('ok', $sent ? 'Приглашение отправлено в Telegram' : 'Добавлен как приглашённый (в Telegram не ушло — игрок не привязал бота)');
            } else { // roster_add / roster_confirm
                db()->prepare("INSERT INTO tournament_participants (tournament_id, player_id, state, source) VALUES (?,?,'confirmed','admin')
                    ON DUPLICATE KEY UPDATE state='confirmed'")->execute([$id, $pid]);
                flash_set('ok', 'Игрок в составе');
            }
        }
        redirect('/admin/tournaments.php?edit=' . $id);
    }
    redirect('/admin/tournaments.php');
}

function rating_recompute_all_safe(): void
{
    require_once ROOT . '/inc/rating.php';
    rating_recompute_all();
}

$editId = (int)($_GET['edit'] ?? 0);
$edit = null;
if ($editId) {
    $st = db()->prepare('SELECT * FROM tournaments WHERE id = ?');
    $st->execute([$editId]);
    $edit = $st->fetch() ?: null;
}
if ($edit && !$canEditT($edit)) {
    flash_set('err', 'Этот турнир редактирует только его главный судья — открыл просмотр.');
    redirect('/tournament.php?id=' . (int)$edit['id']);
}
$list = db_ready() ? db()->query('SELECT * FROM tournaments ORDER BY date_from DESC, id DESC')->fetchAll() : [];
$statusLabel = ['draft' => 'черновик', 'announced' => 'анонс', 'reg_open' => 'регистрация', 'live' => 'идёт', 'finished' => 'завершён'];

page_head('Админка — турниры', '');
echo '<p><a href="/admin/">← Админка</a></p><h1>Турниры</h1>';

echo '<div class="card"><h2 style="margin-top:0;">' . ($edit ? 'Редактировать: ' . esc($edit['title']) : 'Новый турнир') . '</h2>';
echo '<form method="post" action="/admin/tournaments.php" enctype="multipart/form-data">' . csrf_field();
echo '<input type="hidden" name="form" value="save"><input type="hidden" name="id" value="' . (int)($edit['id'] ?? 0) . '">';
echo '<div class="field"><label>Название</label><input type="text" name="title" required value="' . esc($edit['title'] ?? '') . '"></div>';
echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">';
echo '<div class="field"><label>Дата с</label><input type="date" name="date_from" value="' . esc($edit['date_from'] ?? '') . '"></div>';
echo '<div class="field"><label>Дата по</label><input type="date" name="date_to" value="' . esc($edit['date_to'] ?? '') . '"></div>';
$loc = (string)($edit['location'] ?? '');
$locPresets = ['Тушино', 'Миусы'];
$locOther = $loc !== '' && !in_array($loc, $locPresets, true);
echo '<div class="field"><label>Место</label>';
echo '<select name="location_sel" id="loc-sel">';
echo '<option value=""' . ($loc === '' ? ' selected' : '') . '>— не указано —</option>';
foreach ($locPresets as $lp) {
    echo '<option value="' . esc($lp) . '"' . ($loc === $lp ? ' selected' : '') . '>' . esc($lp) . '</option>';
}
echo '<option value="__other"' . ($locOther ? ' selected' : '') . '>другое…</option>';
echo '</select>';
echo '<input type="text" name="location_other" id="loc-other" placeholder="Своё место" value="' . ($locOther ? esc($loc) : '') . '" style="margin-top:8px;' . ($locOther ? '' : 'display:none;') . '">';
echo '<script>(function(){var s=document.getElementById("loc-sel"),o=document.getElementById("loc-other");if(!s||!o)return;s.addEventListener("change",function(){o.style.display=s.value==="__other"?"":"none";});})();</script>';
echo '</div>';
echo '<div class="field"><label>Столов</label><input type="number" name="tables_count" min="1" max="6" value="' . (int)($edit['tables_count'] ?? 1) . '"></div>';
echo '</div>';

$rmode = (string)($edit['reg_mode'] ?? 'open');
echo '<div class="field"><label>Запись участников</label><select name="reg_mode">';
echo '<option value="open"' . ($rmode === 'open' ? ' selected' : '') . '>открытая — игроки записываются сами</option>';
echo '<option value="closed"' . ($rmode === 'closed' ? ' selected' : '') . '>закрытая — состав ведут админы (приглашения через бота)</option>';
echo '</select></div>';

// Места столов: по одному полю на стол (по числу «Столов»)
$tplaces = [];
if (!empty($edit['table_places'])) {
    $dec = json_decode((string)$edit['table_places'], true);
    if (is_array($dec)) {
        $tplaces = $dec;
    }
}
$tcount = max(1, (int)($edit['tables_count'] ?? 1));
echo '<div class="field"><label>Места столов</label>';
echo '<div style="display:grid;grid-template-columns:repeat(2,1fr);gap:8px;">';
for ($i = 0; $i < $tcount; $i++) {
    echo '<input type="text" name="table_places[]" placeholder="Стол ' . ($i + 1) . ' — место" value="' . esc($tplaces[$i] ?? '') . '">';
}
echo '</div>';
echo '<p style="color:var(--tx3);font-size:12px;margin:6px 0 0;">Полей столько же, сколько «Столов». Изменишь число — сохрани и снова открой турнир, поля обновятся.</p></div>';

// Судьи турнира: главный + по столам
$tjudges = [];
if (!empty($edit['table_judges'])) {
    $decj = json_decode((string)$edit['table_judges'], true);
    if (is_array($decj)) {
        $tjudges = $decj;
    }
}
$allPlayers = db_ready() ? db()->query('SELECT id, nickname FROM players ORDER BY nickname')->fetchAll() : [];
$judgeSelect = function (string $name, int $sel) use ($allPlayers): string {
    $h = '<select name="' . $name . '" data-search="Поиск судьи…"><option value="0">— не назначен —</option>';
    foreach ($allPlayers as $p) {
        $h .= '<option value="' . (int)$p['id'] . '"' . ((int)$p['id'] === $sel ? ' selected' : '') . '>' . esc($p['nickname']) . '</option>';
    }
    return $h . '</select>';
};
echo '<div class="field"><label>Судьи столов <span style="color:var(--tx3);font-weight:400;">(кто на столе 1 — тот и главный судья)</span></label>';
echo '<div style="display:grid;grid-template-columns:repeat(2,1fr);gap:10px;">';
for ($i = 0; $i < $tcount; $i++) {
    echo '<div><div style="font-size:12px;color:var(--tx2);margin-bottom:3px;">Стол ' . ($i + 1) . ($i === 0 ? ' · главный' : '') . '</div>'
        . $judgeSelect('table_judges[' . $i . ']', (int)($tjudges[$i] ?? 0)) . '</div>';
}
echo '</div></div>';

echo '<div class="field"><label>Статус</label><select name="status">';
foreach ($statusLabel as $sk => $sl) {
    echo '<option value="' . $sk . '" ' . (($edit['status'] ?? 'draft') === $sk ? 'selected' : '') . '>' . $sl . '</option>';
}
echo '</select></div>';
echo '<div class="field"><label>Описание</label><textarea name="description" rows="3">' . esc($edit['description'] ?? '') . '</textarea></div>';
echo '<div class="field"><label>Логотип турнира (PNG/JPG) — откроется мини-редактор, кадрируй под круг</label>';
$curLogo = !empty($edit['logo']) ? esc($edit['logo']) : '';
echo '<div style="display:flex;align-items:center;gap:14px;">';
echo '<img id="logo-preview" src="' . $curLogo . '" alt="" style="width:84px;height:84px;border-radius:50%;object-fit:cover;border:2px solid var(--bd);background:var(--sf2);' . ($curLogo === '' ? 'display:none;' : '') . '">';
echo '<div style="flex:1;min-width:0;">';
echo '<input type="file" name="logo" id="logo-file" accept="image/*">';
echo '<input type="hidden" name="logo_cropped" id="logo-cropped">';
echo '<div id="logo-hint" style="color:var(--tx3);font-size:12px;margin-top:6px;">Выбери файл — откроется обрезка, увидишь, что именно загрузилось.</div>';
echo '</div></div></div>';
echo '<div style="display:flex;gap:10px;"><button class="btn" type="submit">Сохранить</button>';
if ($edit) {
    echo '<a class="btn btn-ghost" href="/admin/tournaments.php">Отмена</a>';
}
echo '</div></form></div>';

// ── Состав участников (редактор) — доступен у сохранённого турнира ──
if ($edit) {
    $tid = (int)$edit['id'];
    $rq = db()->prepare("SELECT tp.player_id, tp.state, p.nickname, p.avatar, p.tg_user_id
        FROM tournament_participants tp JOIN players p ON p.id = tp.player_id
        WHERE tp.tournament_id = ? ORDER BY FIELD(tp.state,'confirmed','invited','declined'), p.nickname");
    $rq->execute([$tid]);
    $rows = $rq->fetchAll();
    $inRoster = array_map('intval', array_column($rows, 'player_id'));
    $cap = max(1, (int)($edit['tables_count'] ?? 1)) * 10; // 10 игроков на стол
    $filled = count(array_filter($rows, fn($r) => $r['state'] !== 'declined'));
    $confirmedN = count(array_filter($rows, fn($r) => $r['state'] === 'confirmed'));
    $stLabel = ['confirmed' => 'в составе', 'invited' => 'приглашён', 'declined' => 'отказался'];
    $stColor = ['confirmed' => 'var(--ok)', 'invited' => 'var(--tx2)', 'declined' => 'var(--ac)'];

    echo '<div class="card"><h2 style="margin-top:0;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">Состав участников '
        . '<span style="color:' . ($filled >= $cap ? 'var(--ok)' : 'var(--ac)') . ';font-weight:700;font-size:16px;">' . $filled . '/' . $cap . '</span>'
        . '<span style="display:inline-block;background:var(--oksf);color:var(--ok);font-size:12px;font-weight:700;padding:3px 10px;border-radius:20px;white-space:nowrap;">✓ подтвердили: ' . $confirmedN . '</span></h2>';
    echo '<form method="post" action="/admin/tournaments.php" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;margin-bottom:14px;">' . csrf_field();
    echo '<input type="hidden" name="id" value="' . $tid . '">';
    echo '<div class="field" style="margin:0;flex:1;min-width:200px;"><label>Добавить игрока</label><select name="player_id" required data-search="Поиск игрока…"><option value="">— выбери игрока —</option>';
    foreach ($allPlayers as $p) {
        if (in_array((int)$p['id'], $inRoster, true)) {
            continue;
        }
        echo '<option value="' . (int)$p['id'] . '">' . esc($p['nickname']) . '</option>';
    }
    echo '</select></div>';
    echo '<button class="btn" type="submit" name="form" value="roster_add">В состав</button>';
    echo '<button class="btn btn-ghost" type="submit" name="form" value="roster_invite">Пригласить через бота</button>';
    echo '</form>';

    if ($rows) {
        echo '<div style="display:flex;flex-direction:column;gap:6px;">';
        $num = 0;
        foreach ($rows as $r) {
            $isDecl = $r['state'] === 'declined';
            if (!$isDecl) {
                $num++;
            }
            echo '<div style="display:flex;align-items:center;gap:10px;padding:7px 10px;background:var(--sf2);border-radius:9px;' . ($isDecl ? 'opacity:.5;' : '') . '">';
            echo '<span style="width:26px;text-align:right;color:var(--tx3);font-variant-numeric:tabular-nums;flex:none;font-size:13px;">' . ($isDecl ? '—' : $num . '.') . '</span>';
            echo !empty($r['avatar'])
                ? '<img src="' . esc($r['avatar']) . '" alt="" style="width:28px;height:28px;border-radius:50%;object-fit:cover;flex:none;">'
                : '<span style="width:28px;height:28px;border-radius:50%;background:var(--bd);flex:none;"></span>';
            echo '<b style="flex:1;min-width:0;">' . esc($r['nickname']) . '</b>';
            $lbl = $stLabel[$r['state']];
            $clr = $stColor[$r['state']];
            if ($r['state'] === 'invited') {
                if (!empty($r['tg_user_id'])) {
                    $lbl = '📨 ушло в Telegram';
                    $clr = 'var(--ok)';
                } else {
                    $lbl = '⚠ не в боте';
                    $clr = 'var(--ac)';
                }
            }
            echo '<span style="font-size:12px;color:' . $clr . ';white-space:nowrap;">' . $lbl . '</span>';
            if ($r['state'] !== 'confirmed') {
                echo '<form method="post" action="/admin/tournaments.php" style="display:inline;">' . csrf_field()
                    . '<input type="hidden" name="id" value="' . $tid . '"><input type="hidden" name="player_id" value="' . (int)$r['player_id'] . '">'
                    . '<button class="btn btn-ghost" style="padding:3px 9px;font-size:12px;" type="submit" name="form" value="roster_confirm">В состав</button></form>';
            }
            echo '<form method="post" action="/admin/tournaments.php" style="display:inline;">' . csrf_field()
                . '<input type="hidden" name="id" value="' . $tid . '"><input type="hidden" name="player_id" value="' . (int)$r['player_id'] . '">'
                . '<button class="btn btn-ghost" style="padding:3px 9px;font-size:12px;color:var(--ac);" type="submit" name="form" value="roster_remove" title="Убрать">✕</button></form>';
            echo '</div>';
        }
        echo '</div>';
        echo '<p style="color:var(--tx3);font-size:12px;margin:12px 0 0;">📨 <b>ушло в Telegram</b> — игрок получил приглашение с кнопками «Приду / Не смогу» в боте. ⚠ <b>не в боте</b> — игрок не привязал Telegram, сообщение не доставлено: позови вручную или добавь сразу «В состав».</p>';
    } else {
        echo '<p style="color:var(--tx3);margin:0;">Пока никого. Добавь игроков выше' . (($edit['reg_mode'] ?? 'open') === 'open' ? ', либо они запишутся сами на странице турнира.' : '.') . '</p>';
    }
    echo '</div>';
}

if ($list) {
    echo '<div class="card" style="overflow-x:auto;"><table class="tbl">';
    echo '<tr><th>Лого</th><th>Турнир</th><th>Статус</th><th class="num">Столов</th><th></th></tr>';
    foreach ($list as $t) {
        echo '<tr><td>' . (!empty($t['logo']) ? '<img src="' . esc($t['logo']) . '" style="width:32px;height:32px;object-fit:contain;border-radius:6px;">' : '—') . '</td>';
        echo '<td><a href="/tournament.php?id=' . (int)$t['id'] . '">' . esc($t['title']) . '</a></td>';
        echo '<td><span class="tag">' . ($statusLabel[$t['status']] ?? $t['status']) . '</span></td>';
        echo '<td class="num">' . (int)$t['tables_count'] . '</td>';
        echo '<td>';
        if ($canEditT($t)) {
            echo '<a class="btn btn-ghost" style="padding:4px 10px;font-size:12px;" href="/admin/tournaments.php?edit=' . (int)$t['id'] . '">Изменить</a> ';
            echo '<form method="post" action="/admin/tournaments.php" style="display:inline;" onsubmit="return confirm(\'Удалить турнир и все его игры?\');">' . csrf_field();
            echo '<input type="hidden" name="form" value="delete"><input type="hidden" name="id" value="' . (int)$t['id'] . '">';
            echo '<button class="btn btn-ghost" style="padding:4px 10px;font-size:12px;color:var(--ac);" type="submit">Удалить</button></form>';
        } else {
            echo '<a class="btn btn-ghost" style="padding:4px 10px;font-size:12px;" href="/tournament.php?id=' . (int)$t['id'] . '">Открыть</a>';
        }
        echo '</td></tr>';
    }
    echo '</table></div>';
}
?>
<link href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.1/cropper.min.css" rel="stylesheet">
<div id="crop-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.82);z-index:1000;align-items:center;justify-content:center;padding:16px;">
  <div style="background:var(--sf);border:1px solid var(--bd);border-radius:14px;padding:18px;max-width:520px;width:100%;">
    <h3 style="margin:0 0 12px;">Логотип турнира — кадрируй под круг</h3>
    <div style="max-height:60vh;overflow:hidden;"><img id="crop-img" style="max-width:100%;display:block;"></div>
    <div style="display:flex;gap:10px;margin-top:14px;justify-content:flex-end;">
      <button type="button" class="btn btn-ghost" id="crop-cancel">Отмена</button>
      <button type="button" class="btn" id="crop-ok">Применить</button>
    </div>
  </div>
</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.1/cropper.min.js"></script>
<script>
(function () {
  var fileInput = document.getElementById('logo-file'),
      modal = document.getElementById('crop-modal'),
      img = document.getElementById('crop-img'), cropper = null;
  if (!fileInput || !modal) return;
  fileInput.addEventListener('change', function () {
    var f = fileInput.files[0]; if (!f) return;
    var rd = new FileReader();
    rd.onload = function (e) {
      img.src = e.target.result; modal.style.display = 'flex';
      if (cropper) cropper.destroy();
      cropper = new Cropper(img, { aspectRatio: 1, viewMode: 1, autoCropArea: 1, background: false });
    };
    rd.readAsDataURL(f);
  });
  document.getElementById('crop-cancel').addEventListener('click', function () {
    modal.style.display = 'none'; if (cropper) { cropper.destroy(); cropper = null; } fileInput.value = '';
  });
  document.getElementById('crop-ok').addEventListener('click', function () {
    if (!cropper) return;
    var canvas = cropper.getCroppedCanvas({ width: 512, height: 512, imageSmoothingQuality: 'high' });
    var data = canvas.toDataURL('image/jpeg', 0.9);
    document.getElementById('logo-cropped').value = data;
    var pv = document.getElementById('logo-preview'); if (pv) { pv.src = data; pv.style.display = ''; }
    var h = document.getElementById('logo-hint'); if (h) { h.textContent = 'Обрезано ✓ — нажми «Сохранить».'; }
    modal.style.display = 'none'; if (cropper) { cropper.destroy(); cropper = null; }
  });
})();
</script>
<?php
page_foot();
