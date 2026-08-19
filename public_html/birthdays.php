<?php
// Календарь дней рождения клуба (просьба Буханки из «Предложений»).
// Только для вошедших: год рождения — личные данные, наружу их не отдаём.
// В профиле игрока дата видна без года, здесь — так же, плюс возраст только тем,
// кто сам указал полную дату в анкете.
require dirname(__DIR__) . '/inc/bootstrap.php';
require_login();

$months = [1 => 'Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь',
    'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'];

$rows = [];
if (db_ready()) {
    // Берём тех, кто хоть раз играл или имеет аккаунт — как в списке игроков,
    // чтобы календарь не заполнялся давно ушедшими анкетами без единой игры.
    $rows = db()->query("SELECT p.id, p.nickname, p.avatar, p.flair, p.birth_date,
            MONTH(p.birth_date) m, DAY(p.birth_date) d
        FROM players p
        WHERE p.birth_date IS NOT NULL AND p.banned_at IS NULL
          AND (p.user_id IS NOT NULL OR EXISTS (SELECT 1 FROM game_seats gs WHERE gs.player_id = p.id))
        ORDER BY m, d, p.nickname")->fetchAll();
}

$byMonth = [];
foreach ($rows as $r) {
    $byMonth[(int)$r['m']][] = $r;
}

$todayM = (int)date('n');
$todayD = (int)date('j');

// Ближайшие дни рождения — 30 дней вперёд, с переходом через Новый год
$soon = [];
foreach ($rows as $r) {
    $m = (int)$r['m'];
    $d = (int)$r['d'];
    $diff = (mktime(0, 0, 0, $m, $d, (int)date('Y')) - mktime(0, 0, 0, $todayM, $todayD, (int)date('Y'))) / 86400;
    if ($diff < 0) {
        $diff += 365;                      // уже прошёл в этом году — считаем до следующего
    }
    if ($diff <= 30) {
        $soon[] = $r + ['in' => (int)round($diff)];
    }
}
usort($soon, fn($a, $b) => $a['in'] <=> $b['in']);

$meta = ['url' => '/birthdays.php', 'description' => 'Календарь дней рождения игроков клуба «Триада Менделеева».'];
page_head('Дни рождения', 'players', $meta);
echo '<h1>🎂 Дни рождения</h1>';
echo '<p style="color:var(--tx2);font-size:14px;margin-top:-6px;">Чтобы никого не забыть поздравить. '
    . 'Дата берётся из анкеты — свою можно поправить в <a href="/cabinet.php">личном кабинете</a>.</p>';

if (!$rows) {
    empty_state('Дат пока нет', 'Никто не указал дату рождения в анкете.');
    page_foot();
    exit;
}

$card = function (array $r, bool $withMonth = false) use ($months, $todayM, $todayD): string {
    $isToday = (int)$r['m'] === $todayM && (int)$r['d'] === $todayD;
    $date = (int)$r['d'] . ($withMonth ? ' ' . mb_strtolower($months[(int)$r['m']]) : '');
    return '<a class="bd-row' . ($isToday ? ' bd-today' : '') . '" href="/player.php?id=' . (int)$r['id'] . '">'
        . '<span class="bd-day">' . esc($date) . '</span>'
        . avatar_html($r, 26)
        . '<span class="bd-nick">' . player_label($r) . '</span>'
        . ($isToday ? '<span class="bd-mark">сегодня! 🎉</span>' : '')
        . '</a>';
};

// ── Ближайшие ──
if ($soon) {
    echo '<div class="card" style="max-width:640px;"><h2 style="margin-top:0;font-size:17px;">Ближайшие 30 дней</h2>';
    echo '<div class="bd-rows">';
    foreach ($soon as $r) {
        echo $card($r, true);
    }
    echo '</div></div>';
}

// ── Календарь по месяцам ──
echo '<div class="bd-grid">';
for ($m = 1; $m <= 12; $m++) {
    $list = $byMonth[$m] ?? [];
    $isNow = $m === $todayM;
    echo '<div class="card bd-month' . ($isNow ? ' bd-month-now' : '') . '">';
    echo '<div class="bd-mhead">' . $months[$m] . '<span>' . count($list) . '</span></div>';
    if (!$list) {
        echo '<div class="bd-empty">—</div>';
    } else {
        echo '<div class="bd-rows">';
        foreach ($list as $r) {
            echo $card($r);
        }
        echo '</div>';
    }
    echo '</div>';
}
echo '</div>';

page_foot();
