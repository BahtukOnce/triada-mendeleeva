<?php
// Заявка на вступление в клуб — замена Google-формы «Регистрация нового жителя клуба».
// Публичная (без входа). Заявка уходит руководителю: колокольчик на сайте + Telegram-бот.
require dirname(__DIR__) . '/inc/bootstrap.php';
require_once ROOT . '/inc/bot_lib.php';

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
$errors = [];
$done = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    // Анти-спам: honeypot (скрытое поле, боты его заполняют) — молча «принимаем», ничего не пишем
    if (trim((string)($_POST['site'] ?? '')) !== '') {
        redirect('/join.php?ok=1');
    }

    foreach (array_keys($vals) as $k) {
        $vals[$k] = trim((string)($_POST[$k] ?? ''));
    }
    $vals['nickname'] = nickname_clean($vals['nickname']);
    $vals['tg_username'] = ltrim($vals['tg_username'], '@ ');

    if (mb_strlen($vals['full_name']) < 2 || mb_strlen($vals['full_name']) > 150) {
        $errors['full_name'] = 'Укажите ФИО';
    }
    if ($vals['nickname'] === '' || mb_strlen($vals['nickname']) > 60) {
        $errors['nickname'] = 'Укажите игровой ник (без эмодзи)';
    } elseif (is_casper($vals['nickname'])) {
        $errors['nickname'] = 'Этот ник принадлежит призраку клуба 👻 — выберите другой';
    } else {
        // Ник должен быть свободен — новичок не может взять уже используемый ник
        $exN = db()->prepare('SELECT 1 FROM players WHERE LOWER(nickname) = LOWER(?) LIMIT 1');
        $exN->execute([$vals['nickname']]);
        if ($exN->fetchColumn()) {
            $errors['nickname'] = 'Этот ник уже занят игроком клуба — выберите другой';
        }
    }
    if (!in_array($vals['applicant_status'], JOIN_STATUS, true)) {
        $errors['applicant_status'] = 'Выберите статус';
    }
    if (!in_array($vals['faculty'], JOIN_FACULTY, true)) {
        $errors['faculty'] = 'Выберите факультет';
    }
    if (mb_strlen($vals['study_group']) > 50) {
        $vals['study_group'] = mb_substr($vals['study_group'], 0, 50);
    }
    if (!in_array($vals['experience'], JOIN_EXPERIENCE, true)) {
        $errors['experience'] = 'Выберите вариант';
    }
    if (!in_array($vals['source'], JOIN_SOURCE, true)) {
        $errors['source'] = 'Выберите вариант';
    }
    if ($vals['source'] === 'Другое' && $vals['source_other'] === '') {
        $errors['source'] = 'Напишите, откуда узнали';
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
        db()->prepare('INSERT INTO club_applications
            (full_name, nickname, applicant_status, faculty, study_group, experience, source, source_other, tg_username, birth_date, ip)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([
                $vals['full_name'], $vals['nickname'], $vals['applicant_status'], $vals['faculty'],
                $vals['study_group'] ?: null, $vals['experience'], $vals['source'],
                $vals['source'] === 'Другое' ? ($vals['source_other'] ?: null) : null,
                $vals['tg_username'], $vals['birth_date'] ?: null, $ip ?: null,
            ]);
        $appId = (int)db()->lastInsertId();

        // ── Уведомляем: колокольчик — всем админам, Telegram-бот — руководителю ──
        $tgShown = '@' . ltrim($vals['tg_username'], '@');
        app_notify_admins('🆕 Новая заявка в клуб: ' . $vals['nickname'] . ' (' . $vals['full_name'] . ')', '/admin/applications.php');
        try {
            $botText = "🆕 <b>Новая заявка в клуб</b>\n\n"
                . "👤 <b>" . bot_esc($vals['full_name']) . "</b>\n"
                . "🎭 Ник: <b>" . bot_esc($vals['nickname']) . "</b>\n"
                . "📱 Telegram: " . bot_esc($tgShown) . "\n"
                . "🎓 " . bot_esc($vals['applicant_status']) . " · " . bot_esc($vals['faculty'])
                . ($vals['study_group'] !== '' ? ' · гр. ' . bot_esc($vals['study_group']) : '') . "\n"
                . "🎲 Опыт: " . bot_esc($vals['experience']) . "\n"
                . "🔎 Узнал(а): " . bot_esc($vals['source'] === 'Другое' ? ($vals['source_other'] ?: 'Другое') : $vals['source'])
                . ($vals['birth_date'] !== '' ? "\n🎂 " . bot_esc(date('d.m.Y', strtotime($vals['birth_date']))) : '')
                . "\n\nОткрыть на сайте: " . rtrim((string)cfg('base_url', 'https://triada-mendeleeva.ru'), '/') . "/admin/applications.php";
            if (bot_token() !== '') {
                $owners = db()->query("SELECT tg_user_id FROM users WHERE role = 'owner' AND tg_user_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
                foreach ($owners as $tg) {
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
    echo '<div style="margin-top:18px;display:flex;gap:10px;justify-content:center;flex-wrap:wrap;">';
    echo '<a class="btn" href="/">На главную</a>';
    echo '<a class="btn btn-ghost" href="https://t.me/triada_mendeleeva" target="_blank" rel="noopener">Наш Telegram</a>';
    echo '</div></div>';
    page_foot();
    exit;
}

// ── Форма ──
$radioList = function (string $name, array $options, string $current) : string {
    $h = '<div class="join-opts">';
    foreach ($options as $opt) {
        $on = $current === $opt;
        $h .= '<label class="join-opt' . ($on ? ' join-opt-on' : '') . '">'
            . '<input type="radio" name="' . $name . '" value="' . esc($opt) . '"' . ($on ? ' checked' : '') . '>'
            . '<span>' . esc($opt) . '</span></label>';
    }
    return $h . '</div>';
};
$err = fn(string $k): string => isset($errors[$k])
    ? '<div style="color:var(--ac);font-size:12.5px;margin-top:4px;">' . esc($errors[$k]) . '</div>' : '';

echo '<style>'
    . '.join-opts{display:flex;flex-direction:column;gap:7px;}'
    . '.join-opt{display:flex;gap:9px;align-items:flex-start;padding:10px 12px;border:1px solid var(--bd);border-radius:9px;cursor:pointer;transition:border-color .15s,background .15s;}'
    . '.join-opt:hover{border-color:var(--tx3);}'
    . '.join-opt-on{border-color:var(--ac);background:var(--acsf);}'
    . '.join-opt input{margin-top:2px;flex:none;accent-color:var(--ac);}'
    . '.join-sel{width:100%;background:var(--sf2);color:var(--tx);border:1px solid var(--bd);border-radius:8px;padding:11px 12px;font-size:15px;}'
    . '</style>';

echo '<div style="max-width:640px;margin:0 auto;">';
echo '<h1 style="margin-bottom:4px;">Вступить в клуб</h1>';
echo '<p style="color:var(--tx2);font-size:15px;line-height:1.6;margin-top:0;">Доброе утро, город! Очень рады, что вы, дорогой житель, заинтересовались вступлением в клуб «Триада Менделеева». Чтобы мы с вами познакомились поближе, ответьте на несколько вопросов ниже.</p>';

if (isset($errors['_'])) {
    echo '<div class="flash flash-err">' . esc($errors['_']) . '</div>';
}

echo '<form method="post" action="/join.php" class="card" autocomplete="off">' . csrf_field();
// honeypot (скрыт от людей, ловит ботов)
echo '<div style="position:absolute;left:-9999px;" aria-hidden="true"><label>Сайт<input type="text" name="site" tabindex="-1" autocomplete="off"></label></div>';

echo '<div class="field"><label>ФИО <span style="color:var(--ac);">*</span></label>'
    . '<input type="text" name="full_name" value="' . esc($vals['full_name']) . '" required maxlength="150" placeholder="Иванов Иван Иванович">' . $err('full_name') . '</div>';

echo '<div class="field"><label>Игровой ник <span style="color:var(--ac);">*</span></label>'
    . '<input type="text" name="nickname" value="' . esc($vals['nickname']) . '" required maxlength="60" placeholder="как вас звать за столом">'
    . '<div style="font-size:12px;color:var(--tx3);margin-top:5px;line-height:1.5;">Игровое имя позволяет оставить игровой конфликт в игре и не привязывать его к личности человека. <span style="opacity:.7;">© Госпожа Косатка</span></div>'
    . $err('nickname') . '</div>';

echo '<div class="field"><label>Ваш статус <span style="color:var(--ac);">*</span></label>' . $radioList('applicant_status', JOIN_STATUS, $vals['applicant_status']) . $err('applicant_status') . '</div>';

echo '<div class="field"><label>Факультет <span style="color:var(--ac);">*</span></label>'
    . '<select name="faculty" class="join-sel" required><option value="">— выбрать —</option>';
foreach (JOIN_FACULTY as $f) {
    echo '<option value="' . esc($f) . '"' . ($vals['faculty'] === $f ? ' selected' : '') . '>' . esc($f) . '</option>';
}
echo '</select>' . $err('faculty') . '</div>';

echo '<div class="field"><label>Учебная группа <span style="color:var(--tx3);font-weight:400;">(при наличии)</span></label>'
    . '<input type="text" name="study_group" value="' . esc($vals['study_group']) . '" maxlength="50" placeholder="например, ЦТ-13"></div>';

echo '<div class="field"><label>Опыт игры в спортивную мафию <span style="color:var(--ac);">*</span></label>' . $radioList('experience', JOIN_EXPERIENCE, $vals['experience']) . $err('experience') . '</div>';

echo '<div class="field"><label>Как узнали про наш клуб? <span style="color:var(--ac);">*</span></label>' . $radioList('source', JOIN_SOURCE, $vals['source']);
echo '<input type="text" name="source_other" id="join-source-other" value="' . esc($vals['source_other']) . '" maxlength="255" placeholder="Расскажите, откуда именно" style="margin-top:8px;'
    . ($vals['source'] === 'Другое' ? '' : 'display:none;') . '">' . $err('source') . '</div>';

echo '<div class="field"><label>Ник в Telegram <span style="color:var(--ac);">*</span></label>'
    . '<input type="text" name="tg_username" value="' . esc($vals['tg_username']) . '" required maxlength="100" placeholder="@username">'
    . '<div style="font-size:12px;color:var(--tx3);margin-top:5px;">Чтобы мы могли позвать вас на игры.</div>' . $err('tg_username') . '</div>';

echo '<div class="field"><label>Дата рождения <span style="color:var(--tx3);font-weight:400;">(необязательно)</span></label>'
    . '<input type="date" name="birth_date" value="' . esc($vals['birth_date']) . '" max="' . date('Y-m-d') . '" style="color-scheme:dark;">'
    . '<div style="font-size:12px;color:var(--tx3);margin-top:5px;">Нужна для своевременных поздравлений 🎂</div></div>';

echo '<button class="btn" type="submit" style="width:100%;padding:13px;font-size:16px;margin-top:6px;">Отправить заявку</button>';
echo '<p style="font-size:12px;color:var(--tx3);text-align:center;margin:12px 0 0;">Уже играли в клубе и хотите аккаунт под своим ником? <a href="/register.php">Зарегистрируйтесь здесь</a>.</p>';
echo '</form>';
echo '</div>';
?>
<script>
(function () {
  // Показ поля «Другое» при выборе соответствующего варианта; подсветка выбранной опции
  var other = document.getElementById('join-source-other');
  document.querySelectorAll('.join-opts').forEach(function (grp) {
    grp.addEventListener('change', function () {
      grp.querySelectorAll('.join-opt').forEach(function (l) {
        var inp = l.querySelector('input');
        l.classList.toggle('join-opt-on', inp.checked);
      });
      var picked = grp.querySelector('input[name=source]:checked');
      if (picked && other) {
        var isOther = picked.value === 'Другое';
        other.style.display = isOther ? '' : 'none';
        if (isOther) other.focus();
      }
    });
  });
})();
</script>
<?php
page_foot();
