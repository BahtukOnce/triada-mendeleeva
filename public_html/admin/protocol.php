<?php
require dirname(__DIR__, 2) . '/inc/bootstrap.php';
require ROOT . '/inc/rating.php';
require ROOT . '/inc/elo.php';
require_once ROOT . '/inc/bot_lib.php'; // уведомление о подходе к 100 играм
$u = require_judge();

$dayId = (int)($_GET['day'] ?? 0);
$st = db()->prepare('SELECT * FROM game_days WHERE id = ?');
$st->execute([$dayId]);
$day = $st->fetch();
if (!$day) {
    page_head('Вести игры', '');
    empty_state('Вечер не найден', 'Создайте вечер в разделе «Игровые вечера».');
    page_foot();
    exit;
}

// Черновик протокола (protocol_drafts, миграция 084): сырые поля формы без служебных — в JSON.
// Правка того же черновика ($draftId) обновляет его, иначе заводится новый. Возвращает id.
function protocol_draft_save(int $dayId, int $gameId, int $draftId, array $post, array $errors, int $userId): int
{
    $keep = [];
    foreach (['judge', 'winner', 'pu', 'bm1', 'bm2', 'bm3', 'comment'] as $k) {
        if (isset($post[$k]) && !is_array($post[$k])) {
            $keep[$k] = mb_substr((string)$post[$k], 0, 500);
        }
    }
    for ($i = 1; $i <= 10; $i++) {
        foreach (['nick', 'role', 'fouls', 'tech', 'bigtech', 'removal', 'plus', 'minus'] as $f) {
            if (isset($post[$f . $i]) && !is_array($post[$f . $i])) {
                $keep[$f . $i] = mb_substr((string)$post[$f . $i], 0, 80);
            }
        }
    }
    $json = json_encode($keep, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    $err = mb_substr(implode('; ', $errors), 0, 1000);
    if ($draftId > 0) {
        $ex = db()->prepare('SELECT id FROM protocol_drafts WHERE id = ? AND day_id = ?');
        $ex->execute([$draftId, $dayId]);
        if ($ex->fetchColumn()) {
            db()->prepare('UPDATE protocol_drafts SET data = ?, errors = ?, game_id = ?, updated_by = ? WHERE id = ?')
                ->execute([$json, $err, $gameId ?: null, $userId, $draftId]);
            return $draftId;
        }
    }
    db()->prepare('INSERT INTO protocol_drafts (day_id, game_id, data, errors, updated_by) VALUES (?,?,?,?,?)')
        ->execute([$dayId, $gameId ?: null, $json, $err, $userId]);
    return (int)db()->lastInsertId();
}

// ── Сохранение игры ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $form = (string)($_POST['form'] ?? '');

    if ($form === 'delete_draft') {
        $did = (int)($_POST['draft_id'] ?? 0);
        try {
            db()->prepare('DELETE FROM protocol_drafts WHERE id = ? AND day_id = ?')->execute([$did, $dayId]);
        } catch (Throwable $e) {
        }
        log_action((int)$u['id'], 'protocol_draft_delete', ['draft_id' => $did, 'day_id' => $dayId]);
        flash_set('ok', 'Черновик удалён');
        redirect('/admin/protocol.php?day=' . $dayId);
    }

    if ($form === 'delete_game') {
        $gid = (int)($_POST['game_id'] ?? 0);
        db()->prepare('DELETE FROM games WHERE id = ? AND day_id = ?')->execute([$gid, $dayId]);
        log_action((int)$u['id'], 'game_delete', ['game_id' => $gid]);
        recompute_all_locked();
        flash_set('ok', 'Игра удалена, рейтинг пересчитан');
        redirect('/admin/protocol.php?day=' . $dayId);
    }

    if ($form === 'save_game') {
        $gid = (int)($_POST['game_id'] ?? 0);
        $judgePid = (int)($_POST['judge'] ?? 0) ?: null;
        $winner = (string)($_POST['winner'] ?? '');
        $winner = in_array($winner, ['red', 'black', 'draw'], true) ? $winner : null;
        $pu = (int)($_POST['pu'] ?? 0);
        $pu = ($pu >= 1 && $pu <= 10) ? $pu : null;
        $bm = [];
        foreach (['bm1', 'bm2', 'bm3'] as $k) {
            $v = (int)($_POST[$k] ?? 0);
            $bm[] = ($v >= 1 && $v <= 10) ? $v : null;
        }

        // Собираем места
        $seats = [];
        $errors = [];
        $usedPlayers = [];
        // Ники, которых ещё нет в базе: место => ник. Раньше такой участник ронял всю игру
        // ошибкой «игрок не найден». Теперь заводим ростер-игрока без аккаунта («пока нет на
        // платформе») — но только после всех проверок и внутри транзакции записи игры, чтобы
        // ошибка в протоколе не оставляла в базе игроков от несохранённой игры.
        $newNicks = [];
        for ($i = 1; $i <= 10; $i++) {
            $nick = trim((string)($_POST["nick$i"] ?? ''));
            if ($nick === '') {
                continue;
            }
            $pid = player_id_by_nick($nick);
            // «Уже за столом»: известных сверяем по id, новых — по нику без регистра.
            $dupKey = $pid ? 'p' . $pid : 'n' . mb_strtolower($nick);
            if (isset($usedPlayers[$dupKey])) {
                $errors[] = "Место $i: игрок «" . $nick . "» уже за столом";   // flash и черновик экранируют сами
                continue;
            }
            $usedPlayers[$dupKey] = true;
            if (!$pid) {
                $newNicks[$i] = $nick;
            }
            $role = (string)($_POST["role$i"] ?? 'civ');
            $role = in_array($role, ['civ', 'maf', 'sheriff', 'don'], true) ? $role : 'civ';
            $seats[$i] = [
                'player_id' => $pid ?: 0,   // новым id проставим внутри транзакции, после проверок
                'role' => $role,
                'fouls' => max(0, min(4, (int)($_POST["fouls$i"] ?? 0))),
                'tech_fouls' => max(0, min(2, (int)($_POST["tech$i"] ?? 0))),
                'big_tech' => max(0, min(2, (int)($_POST["bigtech$i"] ?? 0))),
                'removal' => max(0, min(2, (int)($_POST["removal$i"] ?? 0))),
                // Верхний предел — защита от опечатки (15 вместо 1.5), реальные баллы столько не набирают
                'plus' => min(9.9, max(0, (float)str_replace(',', '.', (string)($_POST["plus$i"] ?? '0')))),
                'minus' => min(9.9, max(0, (float)str_replace(',', '.', (string)($_POST["minus$i"] ?? '0')))),
            ];
        }

        if (count($seats) < 6) {
            $errors[] = 'Слишком мало игроков (нужно хотя бы 6)';
        }
        if (!$winner) {
            $errors[] = 'Не указан победитель';
        }
        // Жёсткая проверка расклада: ровно 1 дон, 1 шериф, 2 мафии, 6 мирных — иначе не сохраняем
        if (count($seats) === 10) {
            $cnt = ['civ' => 0, 'maf' => 0, 'sheriff' => 0, 'don' => 0];
            foreach ($seats as $s) {
                $cnt[$s['role']]++;
            }
            if ($cnt['don'] !== 1 || $cnt['sheriff'] !== 1 || $cnt['maf'] !== 2 || $cnt['civ'] !== 6) {
                $errors[] = 'Неверный расклад ролей: нужно 1 дон, 1 шериф, 2 мафии, 6 мирных (сейчас: дон '
                    . $cnt['don'] . ', шериф ' . $cnt['sheriff'] . ', мафия ' . $cnt['maf'] . ', мирн ' . $cnt['civ'] . ')';
            }
        }

        // Игра, если редактируем, должна принадлежать ЭТОМУ вечеру — иначе последующие
        // DELETE/INSERT game_seats по game_id из POST затрут чужую игру (UPDATE с
        // «AND day_id» молча не сработает, но seats всё равно перезапишутся).
        $foreignGame = false;
        if ($gid) {
            $own = db()->prepare('SELECT 1 FROM games WHERE id = ? AND day_id = ?');
            $own->execute([$gid, $dayId]);
            if (!$own->fetchColumn()) {
                $errors[] = 'Игра не принадлежит этому вечеру';
                $foreignGame = true;
            }
        }

        // Черновик (решение руководителя): игра с ошибками не теряется, а ложится черновиком вечера —
        // его видит и может доделать любой судья, в рейтинг и статистику он не попадает. Кнопкой
        // «📝 В черновик» судья откладывает и игру без ошибок. Подменённую чужую игру не сохраняем.
        $asDraft = !empty($_POST['as_draft']);
        if ($errors || $asDraft) {
            $draftId = 0;
            if (!$foreignGame) {
                try {
                    $draftId = protocol_draft_save($dayId, $gid, (int)($_POST['draft_id'] ?? 0), $_POST, $errors, (int)$u['id']);
                } catch (Throwable $e) {
                    $draftId = 0;   // таблицы черновиков ещё нет — по-старому, через сессию
                }
            }
            if ($draftId > 0) {
                log_action((int)$u['id'], 'protocol_draft_save', ['draft_id' => $draftId, 'day_id' => $dayId, 'game_id' => $gid]);
                if ($asDraft) {
                    flash_set('ok', '📝 Черновик сохранён' . ($errors ? '. Перед сохранением игры поправить: ' . implode('; ', $errors) : ''));
                } else {
                    flash_set('err', '📝 В игре ошибки — она сохранена черновиком и в рейтинг не попала. Поправьте и сохраните: ' . implode('; ', $errors));
                }
                redirect('/admin/protocol.php?day=' . $dayId . '&draft=' . $draftId);
            }
            $_SESSION['protocol_old'] = $_POST; // не терять введённое: восстановим форму после redirect
            flash_set('err', $errors ? implode('; ', $errors) : 'Черновик не сохранился — попробуйте ещё раз');
            redirect('/admin/protocol.php?day=' . $dayId . ($gid ? '&game=' . $gid : ''));
        }

        $pdo = db();
        $pdo->beginTransaction();
        // Заводим ростер-игроков для новых ников ($newNicks) в той же транзакции: упадёт запись
        // игры — откатятся и они.
        foreach ($newNicks as $seatNo => $newNick) {
            $newPid = (int)player_id_by_nick_or_create($newNick);
            if ($newPid <= 0) {
                $pdo->rollBack();
                $_SESSION['protocol_old'] = $_POST;
                flash_set('err', 'Не удалось добавить игрока «' . $newNick . '»');
                redirect('/admin/protocol.php?day=' . $dayId . ($gid ? '&game=' . $gid : ''));
            }
            $seats[$seatNo]['player_id'] = $newPid;
        }
        // «Выб.» (порядок выбывания) из протокола убран по решению руководителя — поле нигде не
        // использовалось. Но уже внесённые значения при правке игры не стираем: переносим их по
        // игроку, а не по месту, чтобы пересадка за столом ничего не перепутала.
        $oldOut = [];
        if ($gid) {
            $oo = $pdo->prepare('SELECT player_id, out_order FROM game_seats WHERE game_id = ? AND out_order IS NOT NULL');
            $oo->execute([$gid]);
            foreach ($oo->fetchAll() as $oRow) {
                $oldOut[(int)$oRow['player_id']] = (int)$oRow['out_order'];
            }
        }
        if ($gid) {
            $pdo->prepare('UPDATE games SET judge_player_id=?, winner=?, first_killed_seat=?,
                bm_seat1=?, bm_seat2=?, bm_seat3=?, comment=?, status=\'finished\', finished_at=NOW()
                WHERE id=? AND day_id=?')
                ->execute([$judgePid, $winner, $pu, $bm[0], $bm[1], $bm[2],
                    trim((string)($_POST['comment'] ?? '')) ?: null, $gid, $dayId]);
            $pdo->prepare('DELETE FROM game_seats WHERE game_id = ?')->execute([$gid]);
        } else {
            $stmt = $pdo->prepare('SELECT COALESCE(MAX(game_no),0)+1 FROM games WHERE day_id = ?');
            $stmt->execute([$dayId]);
            $nextNo = (int)$stmt->fetchColumn();
            $pdo->prepare("INSERT INTO games (context, day_id, table_no, game_no, judge_player_id, winner,
                first_killed_seat, bm_seat1, bm_seat2, bm_seat3, comment, status, finished_at)
                VALUES ('day', ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, 'finished', NOW())")
                ->execute([$dayId, $nextNo, $judgePid, $winner, $pu, $bm[0], $bm[1], $bm[2],
                    trim((string)($_POST['comment'] ?? '')) ?: null]);
            $gid = (int)$pdo->lastInsertId();
        }
        $insS = $pdo->prepare('INSERT INTO game_seats
            (game_id, seat, player_id, role, fouls, tech_fouls, big_tech, removal, plus, minus, out_order)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        foreach ($seats as $seat => $s) {
            $insS->execute([$gid, $seat, $s['player_id'], $s['role'], $s['fouls'],
                $s['tech_fouls'], $s['big_tech'], $s['removal'], $s['plus'], $s['minus'], $oldOut[(int)$s['player_id']] ?? null]);
        }
        $pdo->commit();

        // Игра сохранена из черновика — черновик больше не нужен.
        $postedDraft = (int)($_POST['draft_id'] ?? 0);
        if ($postedDraft > 0) {
            try {
                db()->prepare('DELETE FROM protocol_drafts WHERE id = ? AND day_id = ?')->execute([$postedDraft, $dayId]);
            } catch (Throwable $e) {
            }
        }

        // Страховка для вечеров, созданных до появления автопривязки (или другим путём):
        // без строки в rating_days игра не попала бы в клубный рейтинг, а сообщение
        // ниже обещает обратное.
        day_attach_to_main_rating($dayId);
        recompute_all_locked();

        // Подход к сотой игре за сезон: клуб поздравляет со 100-й, руководству нужен запас
        // времени. Список участников берём из БД — в форме он есть не во всех ветках.
        try {
            $msPl = db()->prepare('SELECT player_id FROM game_seats WHERE game_id = ?');
            $msPl->execute([$gid]);
            bot_notify_milestones(array_map('intval', $msPl->fetchAll(PDO::FETCH_COLUMN)));
        } catch (Throwable $e) {
        }
        log_action((int)$u['id'], 'game_save', ['game_id' => $gid, 'day_id' => $dayId]);
        flash_set('ok', 'Игра сохранена, рейтинг обновлён');
        redirect('/admin/protocol.php?day=' . $dayId);
    }
}

// ── Данные для формы ──
$roster = db()->prepare('SELECT p.id, p.nickname FROM day_registrations r
    JOIN players p ON p.id = r.player_id
    WHERE r.day_id = ? AND r.cancelled_at IS NULL ORDER BY p.nickname');
$roster->execute([$dayId]);
$rosterList = $roster->fetchAll();
$allPlayers = db()->query('SELECT id, nickname, avatar FROM players WHERE banned_at IS NULL ORDER BY nickname')->fetchAll();

// Подсказки ника: не все ~250 игроков базы (в основном легаси, которых никто не помнит), а те,
// кто реально ходит — участники последних 5 игровых вечеров до даты этого вечера. Сверху —
// сегодняшние: записавшиеся и уже сыгравшие в этом вечере. Границу сезона намеренно не ставим:
// в начале сезона вечеров ещё нет и список был бы пустым; через 5 вечеров он сам станет
// полностью текущим сезоном. Остальную базу JS добавляет при вводе от двух букв.
$suggest = [];       // player_id => nickname
$suggestToday = [];  // player_id => true
foreach ($rosterList as $r) {
    $suggest[(int)$r['id']] = (string)$r['nickname'];
    $suggestToday[(int)$r['id']] = true;
}
$sugDays = [$dayId];
$rd = db()->prepare("SELECT d.id FROM game_days d
    WHERE d.id <> ? AND d.date <= ?
      AND EXISTS (SELECT 1 FROM games g WHERE g.day_id = d.id AND g.status = 'finished')
    ORDER BY d.date DESC, d.id DESC LIMIT 5");
$rd->execute([$dayId, (string)$day['date']]);
foreach ($rd->fetchAll(PDO::FETCH_COLUMN) as $rid) {
    $sugDays[] = (int)$rid;
}
$sp = db()->prepare('SELECT DISTINCT p.id, p.nickname, g.day_id FROM game_seats gs
    JOIN games g ON g.id = gs.game_id
    JOIN players p ON p.id = gs.player_id
    WHERE g.day_id IN (' . implode(',', array_fill(0, count($sugDays), '?')) . ') AND p.banned_at IS NULL');
$sp->execute($sugDays);
foreach ($sp->fetchAll() as $r) {
    $suggest[(int)$r['id']] = (string)$r['nickname'];
    if ((int)$r['day_id'] === $dayId) {
        $suggestToday[(int)$r['id']] = true;
    }
}
uksort($suggest, function ($a, $b) use ($suggest, $suggestToday) {
    $byToday = (isset($suggestToday[$a]) ? 0 : 1) <=> (isset($suggestToday[$b]) ? 0 : 1);
    return $byToday ?: strcmp(mb_strtolower($suggest[$a]), mb_strtolower($suggest[$b]));
});
// Для подсказки ника в стиле сайта: аватар (только если файл реально есть — как avatar_html)
// и пометка «сегодня» у записавшихся и уже сыгравших в этом вечере.
$nickAvatars = [];
foreach ($allPlayers as $p) {
    if (!empty($p['avatar']) && is_file(ROOT . '/public_html' . $p['avatar'])) {
        $nickAvatars[(string)$p['nickname']] = (string)$p['avatar'];
    }
}
$todayNicks = array_values(array_intersect_key($suggest, $suggestToday));
$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

// Игры вечера
$gamesSt = db()->prepare("SELECT g.*, jp.nickname AS judge_nick FROM games g
    LEFT JOIN players jp ON jp.id = g.judge_player_id
    WHERE g.day_id = ? ORDER BY g.game_no");
$gamesSt->execute([$dayId]);
$games = $gamesSt->fetchAll();

// Черновики вечера (миграция 084). Пока таблицы нет (миграция не прошла) — просто без черновиков.
$drafts = [];
try {
    $dq = db()->prepare('SELECT d.*, u.nickname AS by_nick FROM protocol_drafts d
        LEFT JOIN users u ON u.id = d.updated_by
        WHERE d.day_id = ? ORDER BY d.updated_at DESC, d.id DESC');
    $dq->execute([$dayId]);
    $drafts = $dq->fetchAll();
} catch (Throwable $e) {
    $drafts = [];
}
// Открытый черновик (?draft=): форма заполняется им, при сохранении игры он удаляется.
$draft = null;
$draftId = (int)($_GET['draft'] ?? 0);
foreach ($drafts as $d) {
    if ((int)$d['id'] === $draftId) {
        $draft = $d;
    }
}
if (!$draft) {
    $draftId = 0;
}
$gameNos = [];
foreach ($games as $g) {
    $gameNos[(int)$g['id']] = (int)$g['game_no'];
}

// Редактируемая игра
$editGame = null;
$editSeats = [];
$editGid = (int)($_GET['game'] ?? 0);
if ($draft) {
    // Черновик правки существующей игры — правим её же; если игру тем временем удалили,
    // черновик сохранится новой игрой.
    $editGid = isset($gameNos[(int)$draft['game_id']]) ? (int)$draft['game_id'] : 0;
}
if ($editGid) {
    foreach ($games as $g) {
        if ((int)$g['id'] === $editGid) {
            $editGame = $g;
        }
    }
    if ($editGame) {
        $s = db()->prepare('SELECT gs.*, p.nickname FROM game_seats gs JOIN players p ON p.id = gs.player_id
            WHERE gs.game_id = ? ORDER BY gs.seat');
        $s->execute([$editGid]);
        foreach ($s->fetchAll() as $row) {
            $editSeats[(int)$row['seat']] = $row;
        }
    }
}
if (!$editGame) {
    $editGid = 0;   // чужая или удалённая игра — форма новой игры
}

// Восстановление введённого после ошибки валидации (одноразово, из сессии) —
// иначе redirect с flash стирал всю заполненную форму на 10 игроков.
$old = $_SESSION['protocol_old'] ?? null;
unset($_SESSION['protocol_old']);
if ($draft) {
    $old = json_decode((string)$draft['data'], true);
}
if (is_array($old)) {
    $editGame = is_array($editGame) ? $editGame : [];
    $editGame['judge_player_id'] = (int)($old['judge'] ?? 0);
    $editGame['winner'] = (string)($old['winner'] ?? '');
    $editGame['first_killed_seat'] = (int)($old['pu'] ?? 0);
    $editGame['bm_seat1'] = (int)($old['bm1'] ?? 0);
    $editGame['bm_seat2'] = (int)($old['bm2'] ?? 0);
    $editGame['bm_seat3'] = (int)($old['bm3'] ?? 0);
    $editGame['comment'] = (string)($old['comment'] ?? '');
    for ($i = 1; $i <= 10; $i++) {
        $editSeats[$i] = [
            'nickname' => trim((string)($old["nick$i"] ?? '')),
            'role' => (string)($old["role$i"] ?? 'civ'),
            'fouls' => (int)($old["fouls$i"] ?? 0),
            'tech_fouls' => (int)($old["tech$i"] ?? 0),
            'big_tech' => (int)($old["bigtech$i"] ?? 0),
            'removal' => (int)($old["removal$i"] ?? 0),
            'plus' => (float)str_replace(',', '.', (string)($old["plus$i"] ?? '0')),
            'minus' => (float)str_replace(',', '.', (string)($old["minus$i"] ?? '0')),
        ];
    }
}

// Судья (просьба руководителя): не вся база в ~250 ников, а судящие — сверху те, кто судил вечера
// сезона этого вечера (по числу игр), ниже остальные с правом вести протоколы: новый судья выберет
// себя и с нулём игр. Гостевой судья — через «Другой игрок…», JS раскроет всю базу.
[$jsFrom, $jsTo] = current_season_bounds((string)$day['date']);
$judgeCnt = [];
$jc = db()->prepare('SELECT g.judge_player_id AS pid, COUNT(*) AS c FROM games g
    JOIN game_days d ON d.id = g.day_id
    WHERE g.judge_player_id IS NOT NULL AND d.date BETWEEN ? AND ?
    GROUP BY g.judge_player_id');
$jc->execute([$jsFrom, $jsTo]);
foreach ($jc->fetchAll() as $r) {
    $judgeCnt[(int)$r['pid']] = (int)$r['c'];
}
$judgeClub = [];
try {
    $jr = db()->query("SELECT p.id FROM players p JOIN users u ON u.id = p.user_id
        WHERE u.is_judge = 1 OR u.role IN ('judge','admin','deputy','owner')");
    foreach ($jr->fetchAll(PDO::FETCH_COLUMN) as $jpid) {
        $judgeClub[(int)$jpid] = true;
    }
} catch (Throwable $e) {
}
$nickById = array_column($allPlayers, 'nickname', 'id');
$selJudge = (int)($editGame['judge_player_id'] ?? 0);
if ($selJudge > 0 && !isset($nickById[$selJudge])) {
    // Судья игры забанен и в $allPlayers его нет — всё равно показываем, иначе правка игры молча
    // стёрла бы судью.
    $jn = db()->prepare('SELECT nickname FROM players WHERE id = ?');
    $jn->execute([$selJudge]);
    $jnick = $jn->fetchColumn();
    if ($jnick !== false) {
        $nickById[$selJudge] = (string)$jnick;
    }
}
$judgeSeason = [];   // [id, ник, игр] — судили в этом сезоне
$judgeOther = [];    // [id, ник] — судьи клуба без игр в сезоне (и выбранный судья вне списков)
foreach ($judgeCnt as $jpid => $jcnt) {
    if (isset($nickById[$jpid])) {
        $judgeSeason[] = [$jpid, (string)$nickById[$jpid], $jcnt];
    }
}
usort($judgeSeason, fn($a, $b) => ($b[2] <=> $a[2]) ?: strcmp(mb_strtolower($a[1]), mb_strtolower($b[1])));
foreach ($judgeClub + ($selJudge > 0 ? [$selJudge => true] : []) as $jpid => $_) {
    if (!isset($judgeCnt[$jpid]) && isset($nickById[$jpid])) {
        $judgeOther[] = [$jpid, (string)$nickById[$jpid]];
    }
}
usort($judgeOther, fn($a, $b) => strcmp(mb_strtolower($a[1]), mb_strtolower($b[1])));

$roleOpts = ['civ' => 'Мирный', 'maf' => 'Мафия', 'sheriff' => 'Шериф', 'don' => 'Дон'];

page_head('Ведение игры — ' . $day['title'], '');
?>
<p><a href="/admin/days.php">← Вечера</a></p>
<h1>Ведение игры: <?= esc($day['title']) ?> · <?= date('d.m.Y', strtotime($day['date'])) ?></h1>
<?php
// Запись на вечер — закрыть/открыть прямо из протокола (вечер идёт — новым записываться поздно).
// Смена статуса — общая логика admin/days.php (form=status), она же вернёт обратно сюда.
if (in_array($day['status'], ['reg_open', 'reg_closed'], true) && user_perm($u, 'manage_days')):
    $toSt = $day['status'] === 'reg_open' ? 'reg_closed' : 'reg_open'; ?>
<form method="post" action="/admin/days.php" style="margin:-4px 0 14px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;"<?= $toSt === 'reg_closed' ? ' onsubmit="return confirm(\'Закрыть запись на этот вечер? Новые игроки не смогут записаться — ни на сайте, ни в боте.\');"' : '' ?>>
  <?= csrf_field() ?>
  <input type="hidden" name="form" value="status">
  <input type="hidden" name="day_id" value="<?= (int)$dayId ?>">
  <input type="hidden" name="to" value="<?= $toSt ?>">
  <input type="hidden" name="back" value="/admin/protocol.php?day=<?= (int)$dayId ?>">
  <span class="tag <?= $day['status'] === 'reg_open' ? 'tag-open' : '' ?>"><?= $day['status'] === 'reg_open' ? 'запись открыта' : 'запись закрыта' ?></span>
  <button class="btn btn-ghost" style="padding:5px 12px;font-size:13px;" type="submit"><?= $toSt === 'reg_closed' ? '🔒 Закрыть запись' : '🔓 Открыть запись снова' ?></button>
</form>
<?php endif; ?>

<!-- Таймер ведущего -->
<div class="card timer-card">
  <div class="timer-display" id="tm-display">0:30</div>
  <div class="timer-controls">
    <button type="button" class="tm-btn" data-sec="15">15</button>
    <button type="button" class="tm-btn" data-sec="20">20</button>
    <button type="button" class="tm-btn" data-sec="30">30</button>
    <button type="button" class="tm-btn" data-sec="45">45</button>
    <button type="button" class="tm-btn" data-sec="60">60</button>
    <button type="button" class="tm-btn" id="tm-add">+30</button>
    <button type="button" class="tm-btn" data-sec="20" id="tm-lh">ЛХ 20</button>
    <button type="button" class="tm-btn tm-stop" id="tm-stop">Стоп</button>
  </div>
</div>

<!-- Форма игры -->
<form method="post" action="/admin/protocol.php?day=<?= $dayId ?>" id="game-form" autocomplete="off">
  <?= csrf_field() ?>
  <input type="hidden" name="form" value="save_game">
  <input type="hidden" name="game_id" value="<?= $editGid ?>">
  <input type="hidden" name="draft_id" value="<?= $draftId ?>">

  <datalist id="players-dl">
    <?php foreach ($suggest as $snick): ?>
      <option value="<?= esc($snick) ?>"></option>
    <?php endforeach; ?>
  </datalist>

  <div class="card">
    <div class="section-head" style="margin-bottom:10px;">
      <h2 style="margin:0;"><?= $draft ? '📝 Черновик · ' : '' ?><?= $editGid ? 'Игра ' . (int)$gameNos[$editGid] : 'Новая игра ' . (count($games) + 1) ?></h2>
      <div style="display:flex;gap:8px;align-items:center;">
        <label style="font-size:13px;color:var(--tx2);">Судья:</label>
        <select name="judge" id="f-judge" style="background:var(--sf2);color:var(--tx);border:1px solid var(--bd);border-radius:7px;padding:6px 10px;">
          <option value="0">—</option>
          <?php if ($judgeSeason): ?>
            <optgroup label="Судили в этом сезоне">
              <?php foreach ($judgeSeason as [$jpid, $jnick, $jcnt]): ?>
                <option value="<?= $jpid ?>" <?= $selJudge === $jpid ? 'selected' : '' ?>><?= esc($jnick) ?> · <?= $jcnt ?></option>
              <?php endforeach; ?>
            </optgroup>
          <?php endif; ?>
          <?php if ($judgeOther): ?>
            <optgroup label="<?= $judgeSeason ? 'Ещё судьи клуба' : 'Судьи клуба' ?>">
              <?php foreach ($judgeOther as [$jpid, $jnick]): ?>
                <option value="<?= $jpid ?>" <?= $selJudge === $jpid ? 'selected' : '' ?>><?= esc($jnick) ?></option>
              <?php endforeach; ?>
            </optgroup>
          <?php endif; ?>
          <option value="more">Другой игрок…</option>
        </select>
      </div>
    </div>

    <?php if ($draft): ?>
      <div class="draft-note">
        <b>📝 Черновик</b> — в рейтинг и статистику не попал<?= $draft['by_nick'] ? ' · ' . esc($draft['by_nick']) : '' ?>, <?= date('d.m H:i', strtotime((string)$draft['updated_at'])) ?>.
        <?php if (trim((string)$draft['errors']) !== ''): ?>
          <br>Поправить: <?= esc((string)$draft['errors']) ?>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if ($rosterList): ?>
      <p style="font-size:12.5px;color:var(--tx2);margin:0 0 10px;">Записаны:
        <?= implode(', ', array_map(fn($r) => esc($r['nickname']), $rosterList)) ?></p>
    <?php endif; ?>

    <div style="overflow-x:auto;">
      <table class="tbl protocol-tbl">
        <tr>
          <th>#</th><th>Игрок</th><th>Роль</th><th>Фолы</th><th>Тех</th><th title="большой тех.фол: −0.6 каждый, макс 2" style="white-space:nowrap;">Б.тех</th>
          <th title="удаление: −0.6; на критический круг: −1.2">Удал.</th>
          <th>+</th><th>−</th><th class="num">Итог</th>
        </tr>
        <?php for ($i = 1; $i <= 10; $i++): $es = $editSeats[$i] ?? null; ?>
        <tr data-seat="<?= $i ?>">
          <td><?= $i ?></td>
          <td><input type="text" name="nick<?= $i ?>" autocomplete="off" spellcheck="false"
              value="<?= esc($es['nickname'] ?? '') ?>" style="width:120px;">
              <div class="nick-new" style="display:none;font-size:10.5px;line-height:1.2;color:var(--tx3);white-space:nowrap;margin-top:2px;">нет на платформе</div></td>
          <td>
            <select name="role<?= $i ?>" class="f-role">
              <?php foreach ($roleOpts as $rk => $rl): ?>
                <option value="<?= $rk ?>" <?= ($es['role'] ?? 'civ') === $rk ? 'selected' : '' ?>><?= $rl ?></option>
              <?php endforeach; ?>
            </select>
          </td>
          <td><select name="fouls<?= $i ?>" class="f-fouls" data-stepper data-stepper-warn><?php for ($f = 0; $f <= 4; $f++): ?>
            <option value="<?= $f ?>" <?= (int)($es['fouls'] ?? 0) === $f ? 'selected' : '' ?>><?= $f ?></option>
          <?php endfor; ?></select></td>
          <td><select name="tech<?= $i ?>" class="f-tech" data-stepper><?php for ($f = 0; $f <= 2; $f++): ?>
            <option value="<?= $f ?>" <?= (int)($es['tech_fouls'] ?? 0) === $f ? 'selected' : '' ?>><?= $f ?></option>
          <?php endfor; ?></select></td>
          <td><select name="bigtech<?= $i ?>" class="f-bigtech" data-stepper><?php for ($f = 0; $f <= 2; $f++): ?>
            <option value="<?= $f ?>" <?= (int)($es['big_tech'] ?? 0) === $f ? 'selected' : '' ?>><?= $f ?></option>
          <?php endfor; ?></select></td>
          <td><select name="removal<?= $i ?>" class="f-removal" data-stepper data-stepper-warn title="удаление / на критический круг">
            <option value="0" <?= (int)($es['removal'] ?? 0) === 0 ? 'selected' : '' ?>>—</option>
            <option value="1" <?= (int)($es['removal'] ?? 0) === 1 ? 'selected' : '' ?>>уд</option>
            <option value="2" <?= (int)($es['removal'] ?? 0) === 2 ? 'selected' : '' ?>>уд!</option>
          </select></td>
          <td><input type="text" name="plus<?= $i ?>" class="f-plus" inputmode="decimal"
              value="<?= $es && (float)$es['plus'] ? rtrim(rtrim(number_format((float)$es['plus'], 1, '.', ''), '0'), '.') : '' ?>" style="width:42px;"></td>
          <td><input type="text" name="minus<?= $i ?>" class="f-minus" inputmode="decimal"
              value="<?= $es && (float)$es['minus'] ? rtrim(rtrim(number_format((float)$es['minus'], 1, '.', ''), '0'), '.') : '' ?>" style="width:42px;"></td>
          <td class="num"><b class="f-total">0</b></td>
        </tr>
        <?php endfor; ?>
      </table>
    </div>

    <div id="dop-pad" style="margin-top:10px;padding:9px 11px;background:var(--sf2);border-radius:9px;display:flex;flex-wrap:wrap;gap:6px;align-items:center;">
      <span style="font-size:12px;color:var(--tx2);">Быстрый ввод → <b id="dop-target" style="color:var(--ac);">кликни поле «+» или «−»</b>:</span>
      <?php for ($d = 1; $d <= 15; $d++): $vv = number_format($d / 10, 1, '.', ''); ?>
        <button type="button" class="btn btn-ghost dop-b" data-v="<?= $vv ?>" style="padding:3px 9px;font-size:12.5px;"><?= $vv ?></button>
      <?php endfor; ?>
      <button type="button" class="btn btn-ghost dop-b" data-v="0" style="padding:3px 11px;font-size:12.5px;" title="очистить">×</button>
    </div>

    <div style="display:flex;gap:18px;flex-wrap:wrap;margin-top:14px;align-items:end;">
      <div class="field" style="margin:0;">
        <label>Первоубиенный (ПУ)</label>
        <select name="pu" id="f-pu" style="background:var(--sf2);color:var(--tx);border:1px solid var(--bd);border-radius:7px;padding:7px 10px;">
          <option value="0">промах / нет</option>
          <?php for ($o = 1; $o <= 10; $o++): ?>
            <option value="<?= $o ?>" <?= (int)($editGame['first_killed_seat'] ?? 0) === $o ? 'selected' : '' ?>><?= $o ?></option>
          <?php endfor; ?>
        </select>
      </div>
      <div class="field" style="margin:0;">
        <label>Лучший ход (3 места)</label>
        <div style="display:flex;gap:6px;">
          <?php foreach (['bm1' => 'bm_seat1', 'bm2' => 'bm_seat2', 'bm3' => 'bm_seat3'] as $f => $col): ?>
          <select name="<?= $f ?>" class="f-bm" style="background:var(--sf2);color:var(--tx);border:1px solid var(--bd);border-radius:7px;padding:7px 8px;">
            <option value="0">—</option>
            <?php for ($o = 1; $o <= 10; $o++): ?>
              <option value="<?= $o ?>" <?= (int)($editGame[$col] ?? 0) === $o ? 'selected' : '' ?>><?= $o ?></option>
            <?php endfor; ?>
          </select>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="field" style="margin:0;">
        <label>Победа</label>
        <select name="winner" id="f-winner" class="f-win">
          <option value="">—</option>
          <option value="red" <?= ($editGame['winner'] ?? '') === 'red' ? 'selected' : '' ?>>Красные</option>
          <option value="black" <?= ($editGame['winner'] ?? '') === 'black' ? 'selected' : '' ?>>Чёрные</option>
          <option value="draw" <?= ($editGame['winner'] ?? '') === 'draw' ? 'selected' : '' ?>>Ничья</option>
        </select>
      </div>
    </div>

    <div class="field" style="margin:12px 0 0;">
      <label>Комментарий к игре</label>
      <input type="text" name="comment" value="<?= esc($editGame['comment'] ?? '') ?>">
    </div>

    <div style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap;">
      <button class="btn" type="submit"><?= $editGid ? 'Сохранить изменения' : 'Сохранить игру' ?></button>
      <button class="btn btn-ghost" type="submit" name="as_draft" value="1" title="Отложить: игра не попадёт в рейтинг, пока её не сохранят">📝 В черновик</button>
      <?php if ($editGid || $draft): ?><a class="btn btn-ghost" href="/admin/protocol.php?day=<?= $dayId ?>"><?= $draft ? 'Закрыть черновик' : 'Отмена' ?></a><?php endif; ?>
    </div>
    <p style="font-size:12px;color:var(--tx2);margin:10px 0 0;">Ci (компенсация ПУ) считается автоматически при сохранении. Итог в таблице — предварительный, без Ci.<br>
      Штрафы: 4 фола −0.6 · тех −0.3 · бол.тех −0.6 (макс 2) · <b>уд</b> (удаление) −0.6 · <b>уд!</b> (на критический круг) −1.2.</p>
  </div>
</form>

<?php if ($drafts): ?>
<div class="card draft-card">
  <h2 style="margin-top:0;">📝 Черновики (<?= count($drafts) ?>)</h2>
  <p style="font-size:12.5px;color:var(--tx2);margin:-6px 0 10px;">Игры с ошибками или отложенные — в рейтинг и статистику не попали. Откройте, поправьте и сохраните.</p>
  <table class="tbl">
    <tr><th>Игра</th><th>Что поправить</th><th>Когда</th><th></th></tr>
    <?php foreach ($drafts as $d):
        $dData = json_decode((string)$d['data'], true);
        $dPlayers = 0;
        for ($i = 1; $i <= 10; $i++) {
            if (is_array($dData) && trim((string)($dData["nick$i"] ?? '')) !== '') {
                $dPlayers++;
            }
        }
        $dGid = (int)$d['game_id'];
        $dErr = trim((string)$d['errors']); ?>
      <tr<?= (int)$d['id'] === $draftId ? ' class="draft-on"' : '' ?>>
        <td style="white-space:nowrap;"><?= isset($gameNos[$dGid]) ? 'правка игры ' . $gameNos[$dGid] : 'новая игра' ?>
          <div style="font-size:11.5px;color:var(--tx3);">за столом: <?= $dPlayers ?></div></td>
        <td style="font-size:12.5px;color:var(--tx2);"><?= $dErr !== '' ? esc(mb_strimwidth($dErr, 0, 160, '…')) : 'отложена судьёй' ?></td>
        <td style="white-space:nowrap;font-size:12.5px;color:var(--tx2);"><?= date('d.m H:i', strtotime((string)$d['updated_at'])) ?>
          <?php if ($d['by_nick']): ?><div style="font-size:11.5px;color:var(--tx3);"><?= esc($d['by_nick']) ?></div><?php endif; ?></td>
        <td style="white-space:nowrap;">
          <?php if ((int)$d['id'] !== $draftId): ?>
            <a class="btn btn-ghost" style="padding:4px 10px;font-size:12px;" href="/admin/protocol.php?day=<?= $dayId ?>&draft=<?= (int)$d['id'] ?>">Открыть</a>
          <?php else: ?>
            <span style="font-size:12px;color:var(--tx3);margin-right:6px;">открыт</span>
          <?php endif; ?>
          <form method="post" action="/admin/protocol.php?day=<?= $dayId ?>" style="display:inline;" onsubmit="return confirm('Удалить черновик? Введённое в нём пропадёт.');"><?= csrf_field() ?>
            <input type="hidden" name="form" value="delete_draft"><input type="hidden" name="draft_id" value="<?= (int)$d['id'] ?>">
            <button class="btn btn-ghost" style="padding:4px 10px;font-size:12px;color:var(--ac);" type="submit">Удалить</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>
<?php endif; ?>

<?php if ($games): ?>
<div class="card">
  <h2 style="margin-top:0;">Игры вечера (<?= count($games) ?>)</h2>
  <table class="tbl">
    <tr><th>№</th><th>Победа</th><th>Судья</th><th>ПУ</th><th></th></tr>
    <?php $winLbl = ['red' => 'Красные', 'black' => 'Чёрные', 'draw' => 'Ничья'];
    foreach ($games as $g): ?>
      <tr>
        <td><?= (int)$g['game_no'] ?></td>
        <td><?= $g['winner'] ? $winLbl[$g['winner']] : '—' ?></td>
        <td><?= esc($g['judge_nick'] ?? '—') ?></td>
        <td><?= $g['first_killed_seat'] ? 'место ' . (int)$g['first_killed_seat'] : 'промах' ?></td>
        <td>
          <a class="btn btn-ghost" style="padding:4px 10px;font-size:12px;" href="/admin/protocol.php?day=<?= $dayId ?>&game=<?= (int)$g['id'] ?>">Изменить</a>
          <form method="post" action="/admin/protocol.php?day=<?= $dayId ?>" style="display:inline;" onsubmit="return confirm('Удалить игру?');"><?= csrf_field() ?>
            <input type="hidden" name="form" value="delete_game"><input type="hidden" name="game_id" value="<?= (int)$g['id'] ?>">
            <button class="btn btn-ghost" style="padding:4px 10px;font-size:12px;color:var(--ac);" type="submit">Удалить</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>
<?php endif; ?>

<script>
(function () {
  // ── Таймер ──
  var disp = document.getElementById('tm-display');
  var remain = 30, timer = null, beep = null;
  function fmt(s) { var m = Math.floor(s / 60); var ss = s % 60; return m + ':' + (ss < 10 ? '0' : '') + ss; }
  function render() { disp.textContent = fmt(Math.max(0, remain)); disp.classList.toggle('tm-low', remain <= 5 && remain > 0); disp.classList.toggle('tm-zero', remain <= 0); }
  function stop() { if (timer) { clearInterval(timer); timer = null; } }
  function start(sec) {
    stop(); remain = sec; render();
    timer = setInterval(function () {
      remain--; render();
      if (remain <= 0) { stop(); try { beepSound(); } catch (e) {} }
    }, 1000);
  }
  // AudioContext создаётся в пользовательском жесте — иначе мобильные браузеры глушат звук из setInterval
  var audioCtx = null;
  function armAudio() { try { if (!audioCtx) { audioCtx = new (window.AudioContext || window.webkitAudioContext)(); } if (audioCtx && audioCtx.state === 'suspended') { audioCtx.resume(); } } catch (e) {} }
  function beepSound() {
    var ctx = audioCtx; if (!ctx) return;
    var o = ctx.createOscillator(); var g = ctx.createGain();
    o.connect(g); g.connect(ctx.destination); o.frequency.value = 880; o.start();
    g.gain.setValueAtTime(0.3, ctx.currentTime); g.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.4);
    o.stop(ctx.currentTime + 0.4);
  }
  document.querySelectorAll('.tm-btn[data-sec]').forEach(function (b) {
    b.addEventListener('click', function () { armAudio(); start(parseInt(b.dataset.sec, 10)); });
  });
  document.getElementById('tm-add').addEventListener('click', function () { armAudio(); remain += 30; render(); if (!timer) start(remain); });
  document.getElementById('tm-stop').addEventListener('click', function () { stop(); remain = 0; render(); });
  render();

  // ── Живой подсчёт итога ──
  var RED = ['civ', 'sheriff'], BLACK = ['maf', 'don'];
  function roles() { var r = {}; document.querySelectorAll('tr[data-seat]').forEach(function (tr) { r[tr.dataset.seat] = tr.querySelector('.f-role').value; }); return r; }
  function bmBonus() {
    var rs = roles(), hits = 0, given = 0;
    ['bm1', 'bm2', 'bm3'].forEach(function (n) {
      var v = document.querySelector('[name=' + n + ']').value;
      if (v !== '0') { given++; if (BLACK.indexOf(rs[v]) >= 0) hits++; }
    });
    if (given === 0) return 0;
    return { 1: 0.1, 2: 0.3, 3: 0.6 }[hits] || 0;
  }
  function recompute() {
    var winner = document.getElementById('f-winner').value;
    var pu = document.getElementById('f-pu').value;
    var bonus = bmBonus();
    document.querySelectorAll('tr[data-seat]').forEach(function (tr) {
      var seat = tr.dataset.seat;
      var role = tr.querySelector('.f-role').value;
      var plus = parseFloat((tr.querySelector('.f-plus').value || '0').replace(',', '.')) || 0;
      var minus = parseFloat((tr.querySelector('.f-minus').value || '0').replace(',', '.')) || 0;
      var fouls = parseInt(tr.querySelector('.f-fouls').value, 10) || 0;
      var tech = parseInt(tr.querySelector('.f-tech').value, 10) || 0;
      var bigtech = parseInt((tr.querySelector('.f-bigtech') || {}).value, 10) || 0;
      var removal = parseInt((tr.querySelector('.f-removal') || {}).value, 10) || 0;
      var isPu = (pu === seat);
      var total = 0;
      if (winner === 'draw') {
        total = plus - minus + (isPu && RED.indexOf(role) >= 0 && bonus > 0 ? bonus : 0);
      } else if (winner === 'red' || winner === 'black') {
        var team = winner === 'black' ? BLACK : RED;
        total = (team.indexOf(role) >= 0 ? 1 : 0) + plus - minus;
        if (isPu && RED.indexOf(role) >= 0 && bonus > 0) total += bonus;
      } else {
        total = plus - minus;
      }
      if (fouls >= 4) total -= 0.6;
      total -= 0.3 * tech;
      total -= 0.6 * bigtech;
      if (removal === 1) total -= 0.6; else if (removal === 2) total -= 1.2;
      var nick = tr.querySelector('[name=nick' + seat + ']').value.trim();
      tr.querySelector('.f-total').textContent = nick ? (Math.round(total * 100) / 100) : '0';
    });
  }
  document.getElementById('game-form').addEventListener('input', recompute);
  document.getElementById('game-form').addEventListener('change', recompute);
  recompute();

  // ── Данные для подсказки ника: вся база (сверяться надо со всеми, а предлагать — недавних),
  // аватары и сегодняшние участники.
  var allNicks = <?= json_encode(array_column($allPlayers, 'nickname'), $jsonFlags) ?: '[]' ?>;

  // «Другой игрок…» в списке судей: гостевой судья — раскрываем всю базу игроков.
  var judgeSel = document.getElementById('f-judge');
  var allPlayerIds = <?= json_encode(array_map('intval', array_column($allPlayers, 'id')), $jsonFlags) ?: '[]' ?>;
  judgeSel.addEventListener('change', function () {
    if (judgeSel.value !== 'more') return;
    var html = '<option value="0">—</option>';
    allNicks.forEach(function (n, i) { html += '<option value="' + allPlayerIds[i] + '">' + escHtml(n) + '</option>'; });
    judgeSel.innerHTML = html;
    judgeSel.value = '0';
    judgeSel.focus();
    try { judgeSel.showPicker(); } catch (e) {}
  });
  var nickAvatars = <?= json_encode((object)$nickAvatars, $jsonFlags) ?: '{}' ?>;
  var todayNicks = {};
  (<?= json_encode($todayNicks, $jsonFlags) ?: '[]' ?>).forEach(function (n) { todayNicks[String(n).toLowerCase()] = true; });
  var knownNicks = {};
  allNicks.forEach(function (n) { knownNicks[String(n).trim().toLowerCase()] = true; });
  var recentNicks = [].map.call(document.querySelectorAll('#players-dl option'), function (o) { return o.value; });
  var seatNickInputs = [].slice.call(document.querySelectorAll('tr[data-seat] input[name^="nick"]'));

  // ── Подсказка ника в стиле сайта (вместо белого системного datalist): аватар, выделение
  // набранного, «сегодня» у записавшихся. Единственный вариант подсвечен сразу — Enter его
  // подставляет. Сверху недавние игроки, с двух букв — совпадения из всей базы, чтобы
  // вернувшегося после перерыва тоже можно было выбрать. Сидящих за этим столом не предлагаем.
  var dd = document.createElement('div');
  dd.className = 'nick-dd';
  dd.setAttribute('role', 'listbox');
  dd.style.display = 'none';
  document.body.appendChild(dd);
  var ddOpen = false, ddInput = null, ddItems = [], ddOn = -1;

  function escHtml(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function avaHtml(n) {
    if (nickAvatars[n]) return '<img src="' + escHtml(nickAvatars[n]) + '" alt="">';
    var first = Array.from(String(n))[0] || '?';
    return '<span class="avatar-circle">' + escHtml(first.toUpperCase()) + '</span>';
  }
  // Транслит и раскладка (просьба руководителя: «лулу» не находил «Lulu»). Ник и запрос
  // сводим к одному латинскому ключу: «лулу» → lulu = «Lulu», «вспышка» → vspyshka. Запрос,
  // набранный не в той раскладке («Дгдг» вместо «Lulu»), тоже переводим и сверяем.
  var TR = { 'а': 'a', 'б': 'b', 'в': 'v', 'г': 'g', 'д': 'd', 'е': 'e', 'ё': 'e', 'ж': 'zh', 'з': 'z',
    'и': 'i', 'й': 'y', 'к': 'k', 'л': 'l', 'м': 'm', 'н': 'n', 'о': 'o', 'п': 'p', 'р': 'r', 'с': 's',
    'т': 't', 'у': 'u', 'ф': 'f', 'х': 'h', 'ц': 'c', 'ч': 'ch', 'ш': 'sh', 'щ': 'sch', 'ъ': '', 'ы': 'y',
    'ь': '', 'э': 'e', 'ю': 'yu', 'я': 'ya' };
  function nkey(s) {
    s = String(s).toLowerCase().replace(/[^0-9a-zа-яё]/g, '');
    var out = '';
    for (var i = 0; i < s.length; i++) { out += Object.prototype.hasOwnProperty.call(TR, s[i]) ? TR[s[i]] : s[i]; }
    return out.replace(/sch/g, 'sh').replace(/kh/g, 'h').replace(/ts/g, 'c').replace(/ph/g, 'f').replace(/ck/g, 'k')
      .replace(/w/g, 'v').replace(/j/g, 'y').replace(/q/g, 'k').replace(/x/g, 'ks').replace(/(.)\1+/g, '$1');
  }
  var LAY_EN = "qwertyuiop[]asdfghjkl;'zxcvbnm,.`", LAY_RU = 'йцукенгшщзхъфывапролджэячсмитьбюё';
  function swapLayout(s) {
    var out = '';
    for (var i = 0; i < s.length; i++) {
      var a = LAY_EN.indexOf(s[i]), b = LAY_RU.indexOf(s[i]);
      out += a >= 0 ? LAY_RU[a] : (b >= 0 ? LAY_EN[b] : s[i]);
    }
    return out;
  }
  var nickKeys = {};
  function keyOf(n) {
    var s = String(n);
    if (!Object.prototype.hasOwnProperty.call(nickKeys, s)) nickKeys[s] = nkey(s);
    return nickKeys[s];
  }
  function findNicks(self) {
    var q = self.value.trim().toLowerCase(), taken = {}, seen = {}, tiers = [[], [], [], []];
    seatNickInputs.forEach(function (i) {
      var v = i.value.trim().toLowerCase();
      if (i !== self && v) taken[v] = true;
    });
    // Порядок: буквально с начала ника, буквально внутри, по транслиту/раскладке с начала, внутри.
    var fuzzy = q.length >= 2;
    var qk = fuzzy ? nkey(q) : '', qsk = fuzzy ? nkey(swapLayout(q)) : '';
    function scan(list) {
      list.forEach(function (n) {
        var k = String(n).toLowerCase();
        if (seen[k] || taken[k]) return;
        var tier = -1, pos = q === '' ? 0 : k.indexOf(q);
        if (pos === 0) { tier = 0; } else if (pos > 0) { tier = 1; } else if (fuzzy) {
          var nk = keyOf(n), p1 = qk ? nk.indexOf(qk) : -1, p2 = qsk ? nk.indexOf(qsk) : -1;
          if (p1 === 0 || p2 === 0) { tier = 2; } else if (p1 > 0 || p2 > 0) { tier = 3; }
        }
        if (tier < 0) return;
        seen[k] = true;
        tiers[tier].push(n);
      });
    }
    scan(recentNicks);
    if (q.length >= 2) scan(allNicks);
    var out = tiers[0].concat(tiers[1], tiers[2], tiers[3]);
    // Ник, набранный целиком, — первым: Enter подставит ровно его, а не более длинный похожий
    // («Rain», а не «Rainbow»).
    for (var i = 0; i < out.length; i++) {
      if (String(out[i]).toLowerCase() === q) { out.unshift(out.splice(i, 1)[0]); break; }
    }
    return out.slice(0, 30);
  }
  function placeDd() {
    if (!ddOpen || !ddInput) return;
    var r = ddInput.getBoundingClientRect(), h = dd.offsetHeight, w = dd.offsetWidth;
    dd.style.left = Math.max(8, Math.min(r.left, window.innerWidth - w - 8)) + 'px';
    // Под полем не помещается — показываем над ним.
    dd.style.top = ((r.bottom + 4 + h > window.innerHeight && r.top - h - 4 > 0) ? (r.top - h - 4) : (r.bottom + 4)) + 'px';
  }
  function setOn(i) {
    ddOn = i;
    var els = dd.querySelectorAll('.nick-dd-item');
    [].forEach.call(els, function (el, j) { el.classList.toggle('on', j === i); });
    var el = els[i];
    if (!el) return;
    // Прокручиваем только сам список (scrollIntoView мог бы дёрнуть всю страницу).
    if (el.offsetTop < dd.scrollTop) {
      dd.scrollTop = el.offsetTop;
    } else if (el.offsetTop + el.offsetHeight > dd.scrollTop + dd.clientHeight) {
      dd.scrollTop = el.offsetTop + el.offsetHeight - dd.clientHeight;
    }
  }
  function closeDd() {
    ddOpen = false;
    ddItems = [];
    ddOn = -1;
    dd.style.display = 'none';
  }
  function openDd(inp) {
    ddInput = inp;
    ddItems = findNicks(inp);
    var q = inp.value.trim().toLowerCase();
    // Предлагать нечего — или ник уже введён целиком и других вариантов нет.
    if (!ddItems.length || (ddItems.length === 1 && String(ddItems[0]).toLowerCase() === q)) {
      closeDd();
      markNewNicks();
      return;
    }
    var html = '';
    ddItems.forEach(function (n, i) {
      var s = String(n), k = s.toLowerCase(), pos = q ? k.indexOf(q) : -1;
      var name = pos === -1 ? escHtml(s)
        : escHtml(s.slice(0, pos)) + '<b>' + escHtml(s.slice(pos, pos + q.length)) + '</b>' + escHtml(s.slice(pos + q.length));
      html += '<div class="nick-dd-item" role="option" data-i="' + i + '">' + avaHtml(s)
        + '<span class="nick-dd-name">' + name + '</span>'
        + (todayNicks[k] ? '<span class="nick-dd-tag">сегодня</span>' : '') + '</div>';
    });
    html += '<div class="nick-dd-foot">' + (q !== ''
      ? 'Enter — выделенный · ↑ ↓ — другой · Esc — оставить набранное'
      : '↑ ↓ — выбрать · Enter — подставить') + '</div>';
    dd.innerHTML = html;
    dd.style.minWidth = Math.max(210, Math.round(inp.getBoundingClientRect().width)) + 'px';
    dd.style.display = 'block';
    ddOpen = true;
    placeDd();
    // Первый вариант сразу выделен — Enter подставляет его (просьба руководителя). В пустом поле
    // не выделяем: там список — просто недавние игроки, и случайный Enter ничего не вставит.
    setOn(q !== '' ? 0 : -1);
    markNewNicks();
  }
  function pickNick(i) {
    if (!ddInput || i < 0 || i >= ddItems.length) return;
    var inp = ddInput;
    inp.value = ddItems[i];
    inp.dispatchEvent(new Event('input', { bubbles: true }));   // пересчёт итогов и пометок
    closeDd();
    markNewNicks();
  }
  dd.addEventListener('mousedown', function (e) { e.preventDefault(); });   // фокус остаётся в поле
  dd.addEventListener('click', function (e) {
    var it = e.target.closest('.nick-dd-item');
    if (it) pickNick(parseInt(it.getAttribute('data-i'), 10));
  });
  // Закрываем по нажатию мимо поля и подсказки, а не по blur: на телефоне прокрутка списка
  // пальцем уводит фокус из поля, и подсказка закрывалась бы прямо под пальцем.
  document.addEventListener('pointerdown', function (e) {
    if (ddOpen && e.target !== ddInput && !dd.contains(e.target)) {
      closeDd();
      markNewNicks();
    }
  }, true);
  seatNickInputs.forEach(function (inp) {
    inp.addEventListener('focus', function () { openDd(inp); });
    inp.addEventListener('input', function () { openDd(inp); });
    inp.addEventListener('blur', function () { setTimeout(markNewNicks, 0); });
    inp.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') {
        // Enter в поле ника не отправляет всю игру; при подсказке — подставляет вариант.
        e.preventDefault();
        if (ddOpen && ddOn >= 0) pickNick(ddOn);
        return;
      }
      if (!ddOpen) return;
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        setOn(Math.min(ddOn + 1, ddItems.length - 1));
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        setOn(Math.max(ddOn - 1, 0));
      } else if (e.key === 'Escape' || e.key === 'Tab') {
        closeDd();
        markNewNicks();
      }
    });
  });
  window.addEventListener('resize', placeDd);
  window.addEventListener('scroll', placeDd, true);

  // ── «Нет на платформе»: ник, которого нет во ВСЕЙ базе игроков, красим серым с подписью.
  // Такой игрок заведётся при сохранении — судья видит это заранее и ловит опечатку
  // («Васся» вместо «Вася»). Пока судья печатает и подсказка что-то предлагает, ник просто
  // недописан — пометку не показываем (раньше «нет на платформе» появлялось уже на «Всп»).
  function markNewNicks() {
    seatNickInputs.forEach(function (inp) {
      var v = inp.value.trim().toLowerCase();
      var typing = ddOpen && ddInput === inp && document.activeElement === inp;
      var show = v !== '' && !knownNicks[v] && !typing;
      var hint = inp.parentNode.querySelector('.nick-new');
      if (hint) hint.style.display = show ? '' : 'none';
      inp.style.color = show ? 'var(--tx3)' : '';
    });
  }
  document.getElementById('game-form').addEventListener('input', markNewNicks);
  markNewNicks();

  // ── Быстрые кнопки (применяются к последнему выбранному полю «+» или «−») ──
  var lastField = null, dopTarget = document.getElementById('dop-target');
  function bindQuick(sel, kind) {
    document.querySelectorAll(sel).forEach(function (inp) {
      inp.addEventListener('focus', function () {
        lastField = inp;
        var tr = inp.closest('tr[data-seat]');
        if (dopTarget && tr) {
          var ni = tr.querySelector('input[name^="nick"]');
          var nick = ni ? ni.value.trim() : '';
          dopTarget.textContent = kind + ' · место ' + tr.dataset.seat + (nick ? ' · ' + nick : '');
        }
      });
    });
  }
  bindQuick('.f-plus', 'доп +');
  bindQuick('.f-minus', 'минус −');
  document.querySelectorAll('.dop-b').forEach(function (b) {
    b.addEventListener('mousedown', function (e) { e.preventDefault(); });
    b.addEventListener('click', function () {
      if (!lastField) return;
      var v = b.getAttribute('data-v');
      lastField.value = (v === '0') ? '' : v;
      lastField.dispatchEvent(new Event('input', { bubbles: true }));
      lastField.focus();
    });
  });
})();
</script>
<?php page_foot(); ?>
