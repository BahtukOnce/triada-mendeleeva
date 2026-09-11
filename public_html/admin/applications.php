<?php
// Заявки на вступление в клуб — руководитель, зам и админы (зам и админы — по таблице прав,
// право «applications»; в бот заявка уходит руководителю).
require dirname(__DIR__, 2) . '/inc/bootstrap.php';
$u = require_role('admin');
if (!user_perm($u, 'applications')) { http_response_code(403); exit('Приём заявок вам не разрешён (таблица прав).'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int)($_POST['id'] ?? 0);
    $form = (string)($_POST['form'] ?? '');

    $st = db()->prepare('SELECT * FROM club_applications WHERE id = ?');
    $st->execute([$id]);
    $app = $st->fetch();
    if (!$app) {
        flash_set('err', 'Заявка не найдена');
        redirect('/admin/applications.php');
    }

    if ($form === 'update') {
        $state = (string)($_POST['state'] ?? 'new');
        $state = in_array($state, ['new', 'approved', 'rejected'], true) ? $state : 'new';
        db()->prepare('UPDATE club_applications SET state = ?, admin_note = ?, processed_by = ?, processed_at = NOW() WHERE id = ?')
            ->execute([$state, trim((string)($_POST['admin_note'] ?? '')) ?: null, (int)$u['id'], $id]);
        log_action((int)$u['id'], 'application_update', ['id' => $id, 'state' => $state]);
        flash_set('ok', 'Заявка обновлена');
        redirect('/admin/applications.php');
    }

    if ($form === 'delete') {
        db()->prepare('DELETE FROM club_applications WHERE id = ?')->execute([$id]);
        log_action((int)$u['id'], 'application_delete', ['id' => $id]);
        flash_set('ok', 'Заявка удалена');
        redirect('/admin/applications.php');
    }

    if ($form === 'approve') {
        $nick = nickname_clean((string)$app['nickname']);
        if ($nick === '') {
            flash_set('err', 'Пустой ник — исправьте заявку');
            redirect('/admin/applications.php');
        }
        // «Дубль» (просьба Буханки): принимающий заявку — руководитель, зам или админ — сверил
        // анкету и указал, что человек уже есть в базе —
        // играл раньше, в т.ч. в легаси-сезонах, возможно под другим написанием ника. Тогда
        // заявку привязываем к этому игроку со всей историей и статистикой, нового не создаём.
        // Кандидатов подсказывает автосверка на карточке; выбрать можно и вручную по нику.
        $dupPid = (int)($_POST['link_player_id'] ?? 0);
        $dupNick = trim((string)($_POST['link_nick'] ?? ''));
        if ($dupPid <= 0 && $dupNick !== '') {
            $dupPid = (int)(player_id_by_nick($dupNick) ?? 0);
            if ($dupPid <= 0) {
                flash_set('err', 'Игрок «' . $dupNick . '» не найден — проверьте ник для привязки дубля');
                redirect('/admin/applications.php');
            }
        }
        $dup = null;
        if ($dupPid > 0) {
            $c = db()->prepare('SELECT id, user_id, nickname, banned_at FROM players WHERE id = ?');
            $c->execute([$dupPid]);
            $dup = $c->fetch() ?: null;
            if (!$dup) {
                flash_set('err', 'Игрок для привязки дубля не найден');
                redirect('/admin/applications.php');
            }
            // Забаненный, подавший заявку заново, не должен тихо получить аккаунт на старую запись:
            // сначала снять бан осознанно (автосверка забаненных и не предлагает).
            if (!empty($dup['banned_at'])) {
                flash_set('err', 'Игрок «' . $dup['nickname'] . '» забанен — привязка дубля невозможна. Сначала решите вопрос с баном.');
                redirect('/admin/applications.php');
            }
            if (!empty($dup['user_id'])) {
                flash_set('err', 'У игрока «' . $dup['nickname'] . '» уже есть аккаунт — это не дубль новой регистрации: человек может просто войти.');
                redirect('/admin/applications.php');
            }
            // Аккаунт получает ник существующего игрока: вход = имя, под которым вся его история
            // (так же поступает активация по ссылке — activate.php берёт ник игрока).
            $nick = (string)$dup['nickname'];
            $ex = $dup;
        } else {
            // Игрок с таким ником уже есть? С аккаунтом — активация не нужна. Без аккаунта —
            // привязываемся к нему («своя история»). Иначе создаём нового игрока.
            $c = db()->prepare('SELECT id, user_id FROM players WHERE LOWER(nickname) = LOWER(?)');
            $c->execute([$nick]);
            $ex = $c->fetch();
            if ($ex && !empty($ex['user_id'])) {
                flash_set('err', 'У ника «' . $nick . '» уже есть аккаунт — активация не требуется.');
                redirect('/admin/applications.php');
            }
        }
        if ($ex) {
            $pid = (int)$ex['id']; // существующий игрок (история) — аккаунт привяжется к нему
            if ($dup) {
                // Анкета дополняет старую запись: заполняем только пустые поля, ничего не затираем.
                db()->prepare("UPDATE players SET
                        real_name = COALESCE(NULLIF(real_name, ''), ?),
                        tg = COALESCE(NULLIF(tg, ''), ?),
                        faculty = COALESCE(NULLIF(faculty, ''), ?),
                        study_group = COALESCE(NULLIF(study_group, ''), ?),
                        birth_date = COALESCE(birth_date, ?),
                        status = COALESCE(NULLIF(status, ''), ?)
                    WHERE id = ?")
                    ->execute([
                        $app['full_name'] ?: null,
                        $app['tg_username'] ? '@' . ltrim((string)$app['tg_username'], '@') : null,
                        $app['faculty'] ?: null, $app['study_group'] ?: null, $app['birth_date'] ?: null,
                        $app['applicant_status'] ?: null, $pid,
                    ]);
                // След в заявке: кто и к кому привязан — видно в карточке без захода в логи.
                db()->prepare("UPDATE club_applications SET admin_note = CONCAT(COALESCE(NULLIF(admin_note, ''), ''),
                        IF(admin_note IS NULL OR admin_note = '', '', ' · '), ?) WHERE id = ?")
                    ->execute(['дубль игрока «' . $dup['nickname'] . '» (#' . $pid . ')', $id]);
            }
        } else {
            $isRhtu = $app['applicant_status'] !== 'Гость (не из РХТУ)' ? 1 : 0;
            db()->prepare('INSERT INTO players (nickname, real_name, tg, faculty, study_group, birth_date, status, is_rhtu, joined_at)
                VALUES (?,?,?,?,?,?,?,?, CURDATE())')
                ->execute([
                    $nick, $app['full_name'] ?: null, $app['tg_username'] ? '@' . ltrim((string)$app['tg_username'], '@') : null,
                    $app['faculty'] ?: null, $app['study_group'] ?: null, $app['birth_date'] ?: null,
                    $app['applicant_status'] ?: null, $isRhtu,
                ]);
            $pid = (int)db()->lastInsertId();
        }
        // Новый поток: пароль задан прямо в заявке → создаём аккаунт сразу, без активации.
        if (!empty($app['password_hash'])) {
            $uid = create_user_for_player($pid, $nick, (string)$app['password_hash']);
            db()->prepare('UPDATE club_applications SET state = \'approved\', player_id = ?, activation_token = NULL, activated_at = NOW(), password_hash = NULL, processed_by = ?, processed_at = NOW() WHERE id = ?')
                ->execute([$pid, (int)$u['id'], $id]);
            log_action((int)$u['id'], 'application_approve', ['id' => $id, 'player_id' => $pid, 'nick' => $nick, 'existing' => (bool)$ex, 'duplicate' => (bool)$dup, 'account' => $uid !== null]);
            flash_set('ok', $uid !== null
                ? ($dup
                    ? 'Принято как дубль игрока «' . $nick . '»: аккаунт привязан ко всей его истории. Вход — под ником «' . $nick . '» и паролем из заявки (если в заявке был другой ник — сообщите участнику).'
                    : 'Заявка принята. Аккаунт создан — участник входит под своим ником и паролем из заявки.')
                : 'Игрок принят, но аккаунт с ником «' . $nick . '» уже существовал — попросите войти под ним.');
            redirect('/admin/applications.php');
        }
        // Старый поток (заявка без пароля, до нововведения): ссылка активации.
        $token = bin2hex(random_bytes(20));
        db()->prepare('UPDATE club_applications SET state = \'approved\', player_id = ?, activation_token = ?, activated_at = NULL, processed_by = ?, processed_at = NOW() WHERE id = ?')
            ->execute([$pid, $token, (int)$u['id'], $id]);
        log_action((int)$u['id'], 'application_approve', ['id' => $id, 'player_id' => $pid, 'nick' => $nick, 'existing' => (bool)$ex, 'duplicate' => (bool)$dup]);
        flash_set('ok', ($dup ? 'Принято как дубль игрока «' . $nick . '». ' : 'Заявка принята. ')
            . 'Отправьте новичку ссылку активации из карточки — по ней он задаст пароль и войдёт.');
        redirect('/admin/applications.php');
    }
    redirect('/admin/applications.php');
}

$list = db()->query("SELECT * FROM club_applications
    ORDER BY FIELD(state,'new','approved','rejected'), created_at DESC LIMIT 300")->fetchAll();
$newCount = 0;
foreach ($list as $a) {
    if ($a['state'] === 'new') {
        $newCount++;
    }
}
$stateLabel = ['new' => 'новая', 'approved' => 'принята', 'rejected' => 'отклонена'];
$stateTag = ['new' => 'tag-open', 'approved' => 'tag-ok', 'rejected' => ''];

page_head('Админка — заявки в клуб', '');
echo '<p><a href="/admin/">← Админка</a></p><h1>Заявки на вступление' . ($newCount ? ' <span class="tag tag-open">новых: ' . $newCount . '</span>' : '') . '</h1>';
echo '<p style="color:var(--tx2);font-size:14px;margin-top:-6px;">Анкеты новых жителей с формы <a href="/join.php">«Вступить в клуб»</a>. «Принять» → создаётся игрок и сразу аккаунт (пароль человек задал в заявке) — он входит под своим ником и паролем.</p>';

if (!$list) {
    empty_state('Заявок пока нет', 'Когда кто-то заполнит форму вступления, анкета появится здесь.');
    page_foot();
    exit;
}

$row = function (string $lbl, ?string $val): string {
    if ($val === null || $val === '') {
        return '';
    }
    return '<div style="display:flex;gap:8px;padding:3px 0;"><span style="color:var(--tx2);min-width:130px;flex:none;font-size:13px;">' . $lbl . '</span><span>' . esc($val) . '</span></div>';
};

// ── Автосверка дублей: для неразобранных заявок ищем похожих игроков в базе ──
// Сигналы по силе: тот же Telegram; совпадение ФИО; ник — тот же в другом написании (транслит:
// «Vasya» = «Вася») или похожий (как в admin/merge.php: одна правка или общее начало). Дата
// рождения — лишь подкрепляющий признак: сама по себе совпадает случайно. Решение всегда за
// человеком — руководителем, замом или админом; автоматически ничего не склеиваем.
$translit = ['а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'e', 'ж' => 'zh',
    'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p',
    'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'c', 'ч' => 'ch', 'ш' => 'sh',
    'щ' => 'sch', 'ъ' => '', 'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya'];
$nickKey = function (string $s) use ($translit): string {
    $s = (string)preg_replace('/[^\p{L}\p{N}]/u', '', mb_strtolower(trim($s)));
    $s = strtr(strtr($s, $translit), ['sch' => 'sh', 'kh' => 'h', 'ts' => 'c', 'ph' => 'f', 'ck' => 'k',
        'w' => 'v', 'j' => 'y', 'q' => 'k', 'x' => 'ks']);
    return (string)preg_replace('/(.)\1+/u', '$1', $s);   // «Vassya» ~ «Vasya»
};
$nameWords = function (?string $s): array {
    $s = str_replace('ё', 'е', mb_strtolower(trim((string)$s)));
    $w = preg_split('/[\s\-]+/u', (string)preg_replace('/[^\p{L}\s\-]/u', '', $s), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    sort($w);
    return $w;
};
$namesMatch = function (array $a, array $b): bool {
    if (count($a) < 2 || count($b) < 2) {
        return false;                                  // одно слово — слишком слабый признак
    }
    [$small, $big] = count($a) <= count($b) ? [$a, $b] : [$b, $a];
    return !array_diff($small, $big);                  // «Иванов Иван» ⊂ «Иванов Иван Иванович»
};
$tgKey = fn(?string $s): string => ltrim((string)preg_replace('#^(https?://)?t\.me/#i', '', mb_strtolower(trim((string)$s))), '@');

$dupCands = [];
$basePlayers = [];
// Только новые заявки: решение по ним ещё предстоит. Разобранные (в т.ч. отклонённые из старой
// Google-формы — там много уже существующих игроков) плашками не засоряем.
$pending = array_filter($list, fn($a) => $a['state'] === 'new');
if ($pending) {
    $basePlayers = db()->query("SELECT p.id, p.nickname, p.user_id, p.real_name, p.tg, p.tg_username, p.birth_date,
            (SELECT COUNT(*) FROM game_seats gs WHERE gs.player_id = p.id) AS games
        FROM players p WHERE p.banned_at IS NULL")->fetchAll();
    foreach ($basePlayers as &$bp) {
        $bp['_nick'] = $nickKey((string)$bp['nickname']);
        $bp['_name'] = $nameWords($bp['real_name']);
        $bp['_tg'] = $tgKey($bp['tg']);
        $bp['_tgu'] = $tgKey($bp['tg_username']);
    }
    unset($bp);
    foreach ($pending as $a) {
        $aNick = $nickKey((string)$a['nickname']);
        $aName = $nameWords($a['full_name']);
        $aTg = $tgKey($a['tg_username']);
        $aBd = (string)($a['birth_date'] ?? '');
        $found = [];
        foreach ($basePlayers as $bp) {
            $why = [];
            $score = 0;
            // Только настоящий username: в поле телеграма часто пишут «нет» или «-», и такое
            // «совпало» бы со всеми, у кого в базе то же самое.
            if (preg_match('/^[a-z0-9_]{4,}$/', $aTg) && ($aTg === $bp['_tg'] || $aTg === $bp['_tgu'])) {
                $why[] = 'Telegram';
                $score += 100;
            }
            if ($namesMatch($aName, $bp['_name'])) {
                $why[] = 'ФИО';
                $score += 60;
            }
            if ($aNick !== '' && $bp['_nick'] !== '') {
                if ($aNick === $bp['_nick']) {
                    $why[] = mb_strtolower(trim((string)$a['nickname'])) === mb_strtolower(trim((string)$bp['nickname']))
                        ? 'тот же ник' : 'ник в другом написании';
                    $score += 50;
                } elseif (min(strlen($aNick), strlen($bp['_nick'])) >= 4
                    && (levenshtein($aNick, $bp['_nick']) <= 1
                        || str_starts_with($aNick, $bp['_nick']) || str_starts_with($bp['_nick'], $aNick))) {
                    $why[] = 'похожий ник';
                    $score += 30;
                }
            }
            if (!$why) {
                continue;
            }
            if ($aBd !== '' && $aBd === (string)$bp['birth_date']) {
                $why[] = 'дата рождения';
                $score += 40;
            }
            $found[] = ['p' => $bp, 'why' => $why, 'score' => $score];
        }
        usort($found, fn($x, $y) => ($y['score'] <=> $x['score']) ?: ((int)$y['p']['games'] <=> (int)$x['p']['games']));
        $dupCands[(int)$a['id']] = array_slice($found, 0, 3);
    }
    // Общий список ников для ручной пометки «дубль» — только игроки без аккаунта (к ним и привязываем).
    echo '<datalist id="dup-players-dl">';
    foreach ($basePlayers as $bp) {
        if (empty($bp['user_id'])) {
            echo '<option value="' . esc($bp['nickname']) . '"></option>';
        }
    }
    echo '</datalist>';
}

foreach ($list as $a) {
    $tg = '@' . ltrim((string)$a['tg_username'], '@');
    echo '<div class="card">';
    echo '<div style="display:flex;justify-content:space-between;gap:10px;align-items:center;margin-bottom:8px;flex-wrap:wrap;">';
    echo '<div><b style="font-size:17px;">' . esc($a['nickname']) . '</b> <span style="color:var(--tx2);">· ' . esc($a['full_name']) . '</span>'
        . ($a['player_id'] ? ' <a class="tag tag-ok" href="/player.php?id=' . (int)$a['player_id'] . '">игрок создан</a>' : '') . '</div>';
    echo '<span class="tag ' . $stateTag[$a['state']] . '">' . $stateLabel[$a['state']] . ' · ' . date('d.m.Y H:i', strtotime($a['created_at'])) . '</span>';
    echo '</div>';

    echo '<div style="margin-bottom:10px;">';
    echo $row('Telegram:', $tg);
    echo $row('Статус:', $a['applicant_status']);
    echo $row('Факультет:', $a['faculty'] . ($a['study_group'] ? ' · группа ' . $a['study_group'] : ''));
    echo $row('Опыт игры:', $a['experience']);
    $srcTxt = (string)$a['source'];
    if (!empty($a['source_other'])) {
        $srcTxt = str_contains($srcTxt, 'Другое')
            ? str_replace('Другое', 'Другое (' . $a['source_other'] . ')', $srcTxt)
            : trim($srcTxt . ' · ' . $a['source_other']);
    }
    echo $row('Как узнал(а):', $srcTxt);
    echo $row('Дата рождения:', $a['birth_date'] ? date('d.m.Y', strtotime((string)$a['birth_date'])) : '');
    echo '</div>';

    // Ссылка активации (после принятия) — отправить новичку, чтобы задал пароль
    if ($a['state'] === 'approved' && !empty($a['activation_token']) && empty($a['activated_at'])) {
        $actLink = rtrim((string)cfg('base_url', 'https://triada-mendeleeva.ru'), '/') . '/activate.php?token=' . $a['activation_token'];
        echo '<div style="background:var(--sf2);border:1px solid var(--bd);border-radius:9px;padding:10px 12px;margin-bottom:10px;">'
            . '<div style="font-size:13px;color:var(--tx2);margin-bottom:6px;">🔗 Ссылка активации — отправьте новичку (по ней он задаст пароль и войдёт):</div>'
            . '<input readonly value="' . esc($actLink) . '" onclick="this.select();try{document.execCommand(\'copy\');}catch(e){}" '
            . 'title="кликните, чтобы выделить и скопировать" '
            . 'style="width:100%;box-sizing:border-box;background:var(--sf);color:var(--tx);border:1px solid var(--bd);border-radius:7px;padding:8px 10px;font-size:12.5px;cursor:pointer;">'
            . '</div>';
    } elseif (!empty($a['activated_at'])) {
        echo '<div style="font-size:13px;color:var(--ok);margin-bottom:10px;">✓ Аккаунт готов — вход по нику и паролю (' . date('d.m.Y', strtotime((string)$a['activated_at'])) . ')</div>';
    }

    // «Дубль»: кандидаты автосверки + ручная пометка (только для новых заявок, см. $pending)
    if ($a['state'] === 'new') {
        $cands = $dupCands[(int)$a['id']] ?? [];
        if ($cands) {
            echo '<div style="border:1px dashed var(--ac);border-radius:9px;padding:9px 12px;margin-bottom:10px;">';
            echo '<div style="font-size:13px;font-weight:600;margin-bottom:4px;">⚠ Возможно, дубль — похожие игроки уже есть в базе:</div>';
            foreach ($cands as $c) {
                $bp = $c['p'];
                echo '<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;padding:3px 0;">';
                echo '<a href="/player.php?id=' . (int)$bp['id'] . '" target="_blank" rel="noopener"><b>' . esc($bp['nickname']) . '</b></a>';
                echo '<span style="font-size:12px;color:var(--tx2);">' . (int)$bp['games'] . ' игр · совпадает: ' . esc(implode(', ', $c['why'])) . '</span>';
                if (!empty($bp['user_id'])) {
                    echo '<span class="tag" style="margin-left:auto;">уже есть аккаунт</span>';
                } else {
                    $bpNick = esc(addslashes((string)$bp['nickname']));
                    echo '<form method="post" action="/admin/applications.php" style="margin:0 0 0 auto;" onsubmit="return confirm(\'Принять «'
                        . esc(addslashes((string)$a['nickname'])) . '» как дубль игрока «' . $bpNick . '»?\\n\\nНового игрока не будет: аккаунт привяжется к его истории, вход — под ником «'
                        . $bpNick . '».\');">' . csrf_field()
                        . '<input type="hidden" name="form" value="approve"><input type="hidden" name="id" value="' . (int)$a['id'] . '">'
                        . '<input type="hidden" name="link_player_id" value="' . (int)$bp['id'] . '">'
                        . '<button class="btn btn-ghost" style="padding:4px 10px;font-size:12.5px;color:var(--ok);" type="submit">Это он — принять как дубль</button></form>';
                }
                echo '</div>';
            }
            echo '</div>';
        }
        // Ручная пометка — если автосверка промахнулась (совсем другой ник, в базе нет Telegram).
        echo '<details style="margin:0 0 10px;"><summary style="cursor:pointer;font-size:12.5px;color:var(--tx2);">🔗 Отметить как дубль другого игрока…</summary>';
        echo '<form method="post" action="/admin/applications.php" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-top:8px;" onsubmit="return confirm(\'Принять заявку как дубль указанного игрока?\\n\\nНового игрока не будет — аккаунт привяжется к его истории.\');">'
            . csrf_field()
            . '<input type="hidden" name="form" value="approve"><input type="hidden" name="id" value="' . (int)$a['id'] . '">'
            . '<input type="text" name="link_nick" list="dup-players-dl" autocomplete="off" required placeholder="ник игрока в базе" style="min-width:180px;background:var(--sf2);color:var(--tx);border:1px solid var(--bd);border-radius:7px;padding:6px 10px;">'
            . '<button class="btn btn-ghost" style="padding:6px 12px;font-size:13px;" type="submit">Принять как дубль</button></form>';
        echo '</details>';
    }

    // Действия
    echo '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;border-top:1px solid var(--bd);padding-top:10px;">';
    echo '<form method="post" action="/admin/applications.php" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;flex:1;min-width:260px;">' . csrf_field();
    echo '<input type="hidden" name="form" value="update"><input type="hidden" name="id" value="' . (int)$a['id'] . '">';
    echo '<select name="state" style="background:var(--sf2);color:var(--tx);border:1px solid var(--bd);border-radius:7px;padding:6px 10px;">';
    foreach ($stateLabel as $sk => $sl) {
        echo '<option value="' . $sk . '"' . ($a['state'] === $sk ? ' selected' : '') . '>' . $sl . '</option>';
    }
    echo '</select>';
    echo '<input type="text" name="admin_note" placeholder="заметка (необязательно)" value="' . esc((string)$a['admin_note']) . '" style="flex:1;min-width:160px;background:var(--sf2);color:var(--tx);border:1px solid var(--bd);border-radius:7px;padding:6px 10px;">';
    echo '<button class="btn" style="padding:6px 14px;font-size:13px;" type="submit">Сохранить</button>';
    echo '</form>';
    if ($a['state'] !== 'approved') {
        echo '<form method="post" action="/admin/applications.php" onsubmit="return confirm(\'Принять заявку «' . esc(addslashes((string)$a['nickname'])) . '»? Будет создан игрок и аккаунт (пароль из заявки).\');">' . csrf_field()
            . '<input type="hidden" name="form" value="approve"><input type="hidden" name="id" value="' . (int)$a['id'] . '">'
            . '<button class="btn btn-ghost" style="padding:6px 12px;font-size:13px;color:var(--ok);" type="submit">✓ Принять заявку</button></form>';
    }
    echo '<form method="post" action="/admin/applications.php" onsubmit="return confirm(\'Удалить заявку?\');">' . csrf_field()
        . '<input type="hidden" name="form" value="delete"><input type="hidden" name="id" value="' . (int)$a['id'] . '">'
        . '<button class="btn btn-ghost" style="padding:6px 10px;font-size:12px;color:var(--ac);" type="submit">Удалить</button></form>';
    echo '</div>';
    echo '</div>';
}
page_foot();
