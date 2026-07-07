<?php
// Заявка на вступление в клуб — замена Google-формы «Регистрация нового жителя клуба».
// Публичная (без входа). Заявка уходит руководителю: колокольчик на сайте + Telegram-бот.
require dirname(__DIR__) . '/inc/bootstrap.php';
require_once ROOT . '/inc/bot_lib.php';

// Уже вошёл — значит уже в клубе: показываем это вместо формы (повторная заявка не нужна)
if ($me = current_user()) {
    page_head('Вы уже в клубе', '');
    echo '<div class="form-narrow"><div class="form-card" style="text-align:center;">';
    echo '<div style="font-size:52px;line-height:1;margin-bottom:10px;">✅</div>';
    echo '<h1 style="margin:0 0 10px;">Вы уже зарегистрированы</h1>';
    echo '<p style="color:var(--tx2);line-height:1.65;margin:0 0 22px;">Вы вошли под ником <b style="color:var(--tx);">'
        . esc($me['nickname']) . '</b> — заявка на вступление больше не нужна.</p>';
    echo '<a class="btn btn-block" href="/cabinet.php">В личный кабинет</a>';
    echo '<div style="margin-top:12px;"><a href="/" style="color:var(--tx2);">← На главную</a></div>';
    echo '</div></div>';
    page_foot();
    exit;
}

// ── Варианты ответов (совпадают с прежней Google-формой) ──
const JOIN_STATUS = ['Студент', 'Выпускник', 'Сотрудник', 'Гость (не из РХТУ)'];
const JOIN_FACULTY = ['ЦиТХИн', 'БПЭ', 'ВХК РАН', 'УГН', 'ИМСЭН', 'ИПУР', 'ИХТ', 'НПМ', 'ТНВиВМ', 'ФЕН', 'ХФТ', 'Другое'];
const JOIN_EXPERIENCE = [
    'Играю или пробовал(а) играть в других клубах. Имею достаточный опыт.',
    'Знаю основные принципы и правила спортивной мафии, но не играл(а).',
    'Был опыт игры только в городскую мафию с друзьями.',
    'Никогда не играл(а), но хотел(а) бы попробовать.',
];
const JOIN_SOURCE = [
    'Увидел(а) пост-рекламу Триады в группе, связанной с РХТУ',
    'Позвали друзья',
    'Случайно наткнулся(ась)',
    'Увидел трансляцию/группу вк в рекомендованных',
    'Увидел(а) телеграм-канал',
    'Увидел(а) плакат на стенах университета',
    'Другое',
];

$vals = ['full_name' => '', 'nickname' => '', 'applicant_status' => '', 'faculty' => '',
    'study_group' => '', 'experience' => '', 'source' => '', 'source_other' => '',
    'tg_username' => '', 'birth_date' => ''];
$srcSel = [];   // выбранные варианты «как узнали» (мультивыбор)
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    // Анти-спам: honeypot (скрытое поле, боты его заполняют) — молча «принимаем», ничего не пишем
    if (trim((string)($_POST['site'] ?? '')) !== '') {
        redirect('/join.php?ok=1');
    }

    foreach (array_keys($vals) as $k) {
        if ($k === 'source') {
            continue; // мультивыбор — читаем отдельно
        }
        $vals[$k] = trim((string)($_POST[$k] ?? ''));
    }
    $vals['nickname'] = nickname_clean($vals['nickname']);
    $vals['tg_username'] = ltrim($vals['tg_username'], '@ ');
    // Источники — несколько вариантов
    $srcSel = array_values(array_intersect(
        array_map(fn($s) => trim((string)$s), (array)($_POST['source'] ?? [])),
        JOIN_SOURCE
    ));
    $vals['source'] = mb_substr(implode('; ', $srcSel), 0, 500);

    if (mb_strlen($vals['full_name']) < 2 || mb_strlen($vals['full_name']) > 150) {
        $errors['full_name'] = 'Укажите ФИО';
    }
    if ($vals['nickname'] === '' || mb_strlen($vals['nickname']) > 60) {
        $errors['nickname'] = 'Укажите игровой ник (без эмодзи)';
    } elseif (is_casper($vals['nickname'])) {
        $errors['nickname'] = 'Этот ник принадлежит призраку клуба 👻 — выберите другой';
    } else {
        // Ник, у которого уже ЕСТЬ аккаунт, занять нельзя. Ник существующего игрока
        // без аккаунта — можно: это «заберу свою историю», руководитель свяжет при приёме.
        $exN = db()->prepare('SELECT user_id FROM players WHERE LOWER(nickname) = LOWER(?) LIMIT 1');
        $exN->execute([$vals['nickname']]);
        $exRow = $exN->fetch();
        if ($exRow && !empty($exRow['user_id'])) {
            $errors['nickname'] = 'У этого ника уже есть аккаунт — если это вы, просто войдите';
        }
    }
    if (!in_array($vals['applicant_status'], JOIN_STATUS, true)) {
        $errors['applicant_status'] = 'Выберите статус';
    }
    // Факультет — только для студентов/выпускников; учебная группа — только для студентов
    $needFaculty = in_array($vals['applicant_status'], ['Студент', 'Выпускник'], true);
    $isStudent = $vals['applicant_status'] === 'Студент';
    if ($needFaculty) {
        if (!in_array($vals['faculty'], JOIN_FACULTY, true)) {
            $errors['faculty'] = 'Выберите факультет';
        }
    } else {
        $vals['faculty'] = ''; // сотрудник/гость — факультет не нужен
    }
    if (!$isStudent) {
        $vals['study_group'] = ''; // группа только у студентов
    } elseif (mb_strlen($vals['study_group']) > 50) {
        $vals['study_group'] = mb_substr($vals['study_group'], 0, 50);
    }
    if (!in_array($vals['experience'], JOIN_EXPERIENCE, true)) {
        $errors['experience'] = 'Выберите вариант';
    }
    if (!$srcSel) {
        $errors['source'] = 'Выберите хотя бы один вариант';
    } elseif (in_array('Другое', $srcSel, true) && $vals['source_other'] === '') {
        $errors['source'] = 'Вы выбрали «Другое» — напишите, откуда узнали';
    }
    if ($vals['tg_username'] === '' || mb_strlen($vals['tg_username']) > 100) {
        $errors['tg_username'] = 'Укажите ник в Telegram';
    }
    if ($vals['birth_date'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $vals['birth_date'])) {
        $vals['birth_date'] = '';
    }

    // Лимит по IP: не больше 5 заявок в час (защита от флуда)
    $ip = mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    if (!$errors) {
        try {
            $rl = db()->prepare('SELECT COUNT(*) FROM club_applications WHERE ip = ? AND created_at > NOW() - INTERVAL 1 HOUR');
            $rl->execute([$ip]);
            if ((int)$rl->fetchColumn() >= 5) {
                $errors['_'] = 'Слишком много заявок с этого адреса за час. Попробуйте позже или напишите нам в Telegram.';
            }
        } catch (Throwable $e) {
        }
    }

    if (!$errors) {
        $isOther = in_array('Другое', $srcSel, true);
        db()->prepare('INSERT INTO club_applications
            (full_name, nickname, applicant_status, faculty, study_group, experience, source, source_other, tg_username, birth_date, ip)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([
                $vals['full_name'], $vals['nickname'], $vals['applicant_status'], $vals['faculty'] ?: null,
                $vals['study_group'] ?: null, $vals['experience'], $vals['source'],
                $isOther ? ($vals['source_other'] ?: null) : null,
                $vals['tg_username'], $vals['birth_date'] ?: null, $ip ?: null,
            ]);
        $appId = (int)db()->lastInsertId();

        // Источник для показа: список + текст «Другое», если был
        $srcDisplay = $vals['source'];
        if ($isOther && $vals['source_other'] !== '') {
            $srcDisplay = str_replace('Другое', 'Другое (' . $vals['source_other'] . ')', $srcDisplay);
        }
        // Факультет/группа — только если заданы (у сотрудника/гостя их нет)
        $eduLine = trim(($vals['faculty'] !== '' ? $vals['faculty'] : '')
            . ($vals['study_group'] !== '' ? ' · гр. ' . $vals['study_group'] : ''));

        // ── Уведомляем ТОЛЬКО руководителя: колокольчик + Telegram-бот (по просьбе владельца) ──
        $tgShown = '@' . ltrim($vals['tg_username'], '@');
        app_notify_owners('🆕 Новая заявка в клуб: ' . $vals['nickname'] . ' (' . $vals['full_name'] . ')', '/admin/applications.php');
        try {
            $botText = "🆕 <b>Новая заявка в клуб</b>\n\n"
                . "👤 <b>" . bot_esc($vals['full_name']) . "</b>\n"
                . "🎭 Ник: <b>" . bot_esc($vals['nickname']) . "</b>\n"
                . "📱 Telegram: " . bot_esc($tgShown) . "\n"
                . "🎓 " . bot_esc($vals['applicant_status']) . ($eduLine !== '' ? ' · ' . bot_esc($eduLine) : '') . "\n"
                . "🎲 Опыт: " . bot_esc($vals['experience']) . "\n"
                . "🔎 Узнал(а): " . bot_esc($srcDisplay)
                . ($vals['birth_date'] !== '' ? "\n🎂 " . bot_esc(date('d.m.Y', strtotime($vals['birth_date']))) : '')
                . "\n\nОткрыть на сайте: " . rtrim((string)cfg('base_url', 'https://triada-mendeleeva.ru'), '/') . "/admin/applications.php";
            if (bot_token() !== '') {
                // Заявки в бот приходят только руководителю (owner).
                $recip = db()->query("SELECT tg_user_id FROM users WHERE role = 'owner' AND tg_user_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
                foreach ($recip as $tg) {
                    bot_send((int)$tg, $botText);
                }
            }
        } catch (Throwable $e) {
        }
        log_action(null, 'club_application', ['id' => $appId, 'nick' => $vals['nickname']]);
        redirect('/join.php?ok=1');
    }
}

$done = isset($_GET['ok']) && $_SERVER['REQUEST_METHOD'] === 'GET';

$meta = ['url' => '/join.php', 'og_title' => 'Вступить в клуб «Триада Менделеева»',
    'description' => 'Заявка на вступление в клуб спортивной мафии «Триада Менделеева» (РХТУ). Заполните анкету — с вами свяжутся и пригласят на игры.'];
page_head('Вступить в клуб', '', $meta);

if ($done) {
    echo '<div class="card" style="max-width:640px;margin:24px auto;text-align:center;">';
    echo '<div style="font-size:44px;line-height:1;margin-bottom:10px;">🌙</div>';
    echo '<h1 style="margin:0 0 8px;">Заявка принята!</h1>';
    echo '<p style="color:var(--tx2);font-size:15px;line-height:1.6;margin:0;">Отлично! Дело за малым. В ближайшее время вам отпишутся и пригласят в логово.</p>';
    echo '<p style="color:var(--tx3);font-style:italic;margin:14px 0 0;">Триада засыпает…</p>';
    $botUser = ltrim((string)setting('bot_username', ''), '@');
    echo '<p style="color:var(--tx2);font-size:14px;line-height:1.6;margin:18px 0 0;">Пока заявку смотрят — подпишитесь на бота клуба. Он пришлёт приглашение, а после принятия поможет со входом.</p>';
    echo '<div style="margin-top:14px;display:flex;gap:10px;justify-content:center;flex-wrap:wrap;">';
    if ($botUser !== '') {
        echo '<a class="btn" href="https://t.me/' . esc($botUser) . '" target="_blank" rel="noopener">🤖 Привязать бота</a>';
    }
    echo '<a class="btn btn-ghost" href="/">На главную</a>';
    echo '</div></div>';
    page_foot();
    exit;
}

// ── Форма ──
// Список одиночного выбора (радио) или мультивыбора (чекбоксы) — единый вид.
$optList = function (string $name, array $options, $selected, bool $multi = false): string {
    $sel = (array)$selected;
    $type = $multi ? 'checkbox' : 'radio';
    $nm = $multi ? $name . '[]' : $name;
    $h = '<div class="join-opts">';
    foreach ($options as $opt) {
        $on = in_array($opt, $sel, true);
        $h .= '<label class="join-opt' . ($on ? ' join-opt-on' : '') . '">'
            . '<input type="' . $type . '" name="' . $nm . '" value="' . esc($opt) . '"' . ($on ? ' checked' : '') . '>'
            . '<span>' . esc($opt) . '</span></label>';
    }
    return $h . '</div>';
};
$err = fn(string $k): string => isset($errors[$k])
    ? '<div style="color:var(--ac);font-size:13px;margin-top:5px;">' . esc($errors[$k]) . '</div>' : '';

// Начальное состояние условных полей (без JS / при повторном рендере с ошибкой)
$curStatus = $vals['applicant_status'];
$showFac = in_array($curStatus, ['Студент', 'Выпускник'], true);
$showGrp = $curStatus === 'Студент';

echo '<style>'
    . '.join-opts{display:flex;flex-direction:column;gap:8px;}'
    . '.join-opt{display:block;padding:12px 14px;border:1px solid var(--bd);border-radius:9px;cursor:pointer;color:var(--tx);font-size:14.5px;line-height:1.4;transition:border-color .15s,background .15s;}'
    . '.join-opt:hover{border-color:var(--tx3);}'
    . '.join-opt-on{border-color:var(--ac);background:var(--acsf);font-weight:600;}'
    . '.join-opt input{position:absolute;opacity:0;width:0;height:0;margin:0;pointer-events:none;}'
    . '.join-sel{width:100%;background:var(--sf2);color:var(--tx);border:1px solid var(--bd);border-radius:8px;padding:11px 12px;font-size:15px;}'
    . '.join-hint{font-size:13px;color:var(--tx2);margin-top:6px;line-height:1.5;}'
    . '.jf label{font-size:14.5px;color:var(--tx);}'
    . '</style>';

echo '<div style="max-width:640px;margin:0 auto;">';
echo '<h1 style="margin-bottom:4px;">Вступить в клуб</h1>';
echo '<p style="color:var(--tx2);font-size:15px;line-height:1.6;margin-top:0;">Доброе утро, город! Очень рады, что вы, дорогой житель, заинтересовались вступлением в клуб «Триада Менделеева». Чтобы мы с вами познакомились поближе, ответьте на несколько вопросов ниже.</p>';

if (isset($errors['_'])) {
    echo '<div class="flash flash-err">' . esc($errors['_']) . '</div>';
}

echo '<form method="post" action="/join.php" class="card jf" autocomplete="off">' . csrf_field();
// honeypot (скрыт от людей, ловит ботов)
echo '<div style="position:absolute;left:-9999px;" aria-hidden="true"><label>Сайт<input type="text" name="site" tabindex="-1" autocomplete="off"></label></div>';

echo '<div class="field"><label>ФИО <span style="color:var(--ac);">*</span></label>'
    . '<input type="text" name="full_name" value="' . esc($vals['full_name']) . '" required maxlength="150" placeholder="Иванов Иван Иванович">' . $err('full_name') . '</div>';

echo '<div class="field"><label>Игровой ник <span style="color:var(--ac);">*</span></label>'
    . '<input type="text" name="nickname" value="' . esc($vals['nickname']) . '" required maxlength="60" placeholder="как вас звать за столом">'
    . '<div class="join-hint">Игровое имя позволяет оставить игровой конфликт в игре и не привязывать его к личности человека. <span style="opacity:.75;">© Госпожа Косатка</span></div>'
    . $err('nickname') . '</div>';

echo '<div class="field"><label>Ваш статус <span style="color:var(--ac);">*</span></label>' . $optList('applicant_status', JOIN_STATUS, $curStatus) . $err('applicant_status') . '</div>';

echo '<div class="field" id="jf-faculty"' . ($showFac ? '' : ' style="display:none;"') . '><label>Факультет <span style="color:var(--ac);">*</span></label>'
    . '<select name="faculty" class="join-sel"><option value="">— выбрать —</option>';
foreach (JOIN_FACULTY as $f) {
    echo '<option value="' . esc($f) . '"' . ($vals['faculty'] === $f ? ' selected' : '') . '>' . esc($f) . '</option>';
}
echo '</select>' . $err('faculty') . '</div>';

echo '<div class="field" id="jf-group"' . ($showGrp ? '' : ' style="display:none;"') . '><label>Учебная группа</label>'
    . '<input type="text" name="study_group" value="' . esc($vals['study_group']) . '" maxlength="50" placeholder="например, ЦТ-13"></div>';

echo '<div class="field"><label>Опыт игры в спортивную мафию <span style="color:var(--ac);">*</span></label>' . $optList('experience', JOIN_EXPERIENCE, $vals['experience']) . $err('experience') . '</div>';

echo '<div class="field"><label>Как узнали про наш клуб? <span style="color:var(--ac);">*</span> <span style="color:var(--tx2);font-weight:400;font-size:13px;">— можно выбрать несколько</span></label>'
    . $optList('source', JOIN_SOURCE, $srcSel, true);
echo '<input type="text" name="source_other" id="join-source-other" value="' . esc($vals['source_other']) . '" maxlength="255" placeholder="Расскажите, откуда именно" style="margin-top:8px;'
    . (in_array('Другое', $srcSel, true) ? '' : 'display:none;') . '">' . $err('source') . '</div>';

echo '<div class="field"><label>Ник в Telegram <span style="color:var(--ac);">*</span></label>'
    . '<input type="text" name="tg_username" value="' . esc($vals['tg_username']) . '" required maxlength="100" placeholder="@username">'
    . '<div class="join-hint">Чтобы мы могли позвать вас на игры.</div>' . $err('tg_username') . '</div>';

echo '<div class="field"><label>Дата рождения <span style="color:var(--tx2);font-weight:400;font-size:13px;">— необязательно</span></label>'
    . '<input type="date" name="birth_date" value="' . esc($vals['birth_date']) . '" max="' . date('Y-m-d') . '" style="color-scheme:dark;">'
    . '<div class="join-hint">Нужна для своевременных поздравлений 🎂</div></div>';

echo '<button class="btn" type="submit" style="width:100%;padding:13px;font-size:16px;margin-top:6px;">Отправить заявку</button>';
echo '<p style="font-size:13px;color:var(--tx2);text-align:center;margin:12px 0 0;">Уже играли в клубе? Укажите в анкете свой прежний ник — привяжем к вашей истории. Уже есть аккаунт? <a href="/login.php">Войдите</a>.</p>';
echo '</form>';
echo '</div>';
?>
<script>
(function () {
  var other = document.getElementById('join-source-other');
  // Подсветка выбранных вариантов + показ поля «Другое» (источник — чекбоксы)
  document.querySelectorAll('.join-opts').forEach(function (grp) {
    grp.addEventListener('change', function () {
      grp.querySelectorAll('.join-opt').forEach(function (l) {
        l.classList.toggle('join-opt-on', l.querySelector('input').checked);
      });
      var otherCb = grp.querySelector('input[value="Другое"]');
      if (otherCb && other) {
        other.style.display = otherCb.checked ? '' : 'none';
        if (otherCb.checked) other.focus();
      }
    });
  });
  // Условные поля: факультет — студент/выпускник, группа — только студент
  var fFac = document.getElementById('jf-faculty');
  var fGrp = document.getElementById('jf-group');
  function applyStatus() {
    var r = document.querySelector('input[name=applicant_status]:checked');
    var st = r ? r.value : '';
    if (fFac) fFac.style.display = (st === 'Студент' || st === 'Выпускник') ? '' : 'none';
    if (fGrp) fGrp.style.display = (st === 'Студент') ? '' : 'none';
  }
  document.querySelectorAll('input[name=applicant_status]').forEach(function (r) {
    r.addEventListener('change', applyStatus);
  });
  applyStatus();
})();
</script>
<?php
page_foot();
