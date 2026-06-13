<?php
require dirname(__DIR__) . '/inc/bootstrap.php';

$dbok = db_ready();
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id && $dbok) {
    $st = db()->prepare('SELECT n.*, u.nickname AS author FROM news n
        LEFT JOIN users u ON u.id = n.author_id
        WHERE n.id = ? AND n.published_at IS NOT NULL');
    $st->execute([$id]);
    $item = $st->fetch();
    if (!$item) {
        http_response_code(404);
    }
    page_head($item ? $item['title'] : 'Новость не найдена', 'news');
    if ($item) {
        echo '<h1>' . esc($item['title']) . '</h1>';
        echo '<p style="color:var(--tx2);font-size:13px;">'
            . esc(date('d.m.Y', strtotime($item['published_at'])))
            . ($item['author'] ? ' · ' . esc($item['author']) : '') . '</p>';
        echo '<div class="card" style="line-height:1.7;">' . nl2br(esc($item['body'] ?? '')) . '</div>';
        echo '<p><a href="/news.php">← Все новости</a></p>';
    } else {
        empty_state('Новость не найдена', 'Возможно, она была удалена.');
    }
    page_foot();
    exit;
}

$list = [];
if ($dbok) {
    $list = db()->query('SELECT id, title, published_at FROM news
        WHERE published_at IS NOT NULL
        ORDER BY pinned DESC, published_at DESC LIMIT 50')->fetchAll();
}

page_head('Новости', 'news');
echo '<h1>Новости</h1>';
echo '<p style="margin-top:-6px;display:flex;gap:8px;flex-wrap:wrap;">'
    . '<a class="btn btn-ghost" href="/rules.php">📖 Правила игры</a>'
    . '<a class="btn btn-ghost" href="/suggest.php">💡 Предложить идею</a></p>';

if ($list) {
    echo '<div class="card">';
    $first = true;
    foreach ($list as $n) {
        echo '<div class="news-item' . ($first ? ' first' : '') . '">';
        echo '<div class="ttl"><a href="/news.php?id=' . (int)$n['id'] . '" style="color:var(--tx);">' . esc($n['title']) . '</a></div>';
        echo '<div class="dt">' . esc(date('d.m.Y', strtotime($n['published_at']))) . '</div>';
        echo '</div>';
        $first = false;
    }
    echo '</div>';
} else {
    empty_state('Новостей пока нет', 'Анонсы вечеров, итоги турниров и объявления клуба будут появляться здесь.');
}

// ── Рекорды клуба ──
$recs = $dbok ? club_records() : [];
if ($recs) {
    echo '<h2 style="margin:20px 0 4px;">Рекорды клуба</h2>';
    echo '<div class="records-grid">';
    foreach (array_slice($recs, 0, 8) as [$ic, $title, $row, $val, $type]) {
        echo '<a class="rec-card" href="/player.php?id=' . (int)$row['pid'] . '">';
        echo '<div class="rec-ic">' . $ic . '</div>';
        echo '<div class="rec-body"><div class="rec-title">' . esc($title) . '</div>';
        echo '<div class="rec-player">' . avatar_html($row, 30) . '<span>' . player_label($row) . '</span></div></div>';
        echo '<div class="rec-val">' . esc(records_fmt($val, $type)) . '</div>';
        echo '</a>';
    }
    echo '</div>';
}

// ── Достижения ──
echo '<h2 style="margin:20px 0 4px;">Достижения</h2>';
echo '<p style="color:var(--tx2);font-size:13px;margin:0 0 6px;">Зелёная карточка — ачивку уже кто-то получил, серая — пока никто. Нажми на ачивку, чтобы увидеть всех, кто её получил.</p>';
$earners = $dbok ? achievement_earners() : [];
$byGroup = [];
foreach (achievements_catalog() as $k => [$ic, $t, $d, $grp]) {
    $byGroup[$grp][$k] = [$ic, $t, $d];
}
foreach ($byGroup as $grp => $items) {
    echo '<div style="font-size:11.5px;color:var(--tx2);text-transform:uppercase;letter-spacing:0.6px;margin:12px 0 6px;">' . esc($grp) . '</div>';
    echo '<div class="ach-grid">';
    foreach ($items as $k => [$ic, $t, $d]) {
        $who = $earners[$k] ?? [];
        $cnt = count($who);
        $names = array_map(fn($e) => $e[1], $who);
        $tip = $cnt ? 'Получили (' . $cnt . '): ' . implode(', ', array_slice($names, 0, 40)) : 'Пока ни у кого';
        $whoJson = esc(json_encode(array_slice($who, 0, 200), JSON_UNESCAPED_UNICODE));
        echo '<div class="ach' . ($cnt > 0 ? ' ach-on' : '') . '" data-who="' . $whoJson . '" title="' . esc($tip) . '">'
            . '<div class="ach-ic">' . $ic . '</div><div class="ach-t">' . esc($t) . '</div>'
            . '<div class="ach-d">' . esc($d) . '</div><div class="ach-cnt">' . $cnt . ' получ.</div></div>';
    }
    echo '</div>';
}

page_foot();
