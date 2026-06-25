<?php
require dirname(__DIR__) . '/inc/bootstrap.php';

$dbok = db_ready();
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Картинки поста: массив путей (из images JSON, иначе одиночное image).
$imgsOf = function (array $n): array {
    $arr = [];
    if (!empty($n['images'])) {
        $d = json_decode((string)$n['images'], true);
        if (is_array($d)) {
            $arr = $d;
        }
    }
    if (!$arr && !empty($n['image'])) {
        $arr = [$n['image']];
    }
    return array_values(array_filter($arr));
};

$newsChan = (string)cfg('news_channel_id', 'triada_mendeleeva');
if ($newsChan === '') {
    $newsChan = 'triada_mendeleeva';
}
$me = current_user();
$meId = $me ? (int)$me['id'] : null;
$csrf = csrf_token();

// Карточка поста: галерея + текст + видео + реакции + просмотры/дата.
$renderPost = function (array $n) use ($imgsOf, $newsChan, $meId, $csrf): string {
    $imgs = $imgsOf($n);
    $h = '<article class="post-card">';
    if ($imgs) {
        $cnt = count($imgs);
        $cls = $cnt === 1 ? 'n1' : ($cnt === 2 ? 'n2' : ($cnt === 3 ? 'n3' : 'nmore'));
        $h .= '<div class="post-imgs ' . $cls . '">';
        foreach ($imgs as $src) {
            $h .= '<img src="' . esc($src) . '" alt="" loading="lazy">';
        }
        $h .= '</div>';
    }
    $h .= '<div class="post-main">';
    $body = render_post_body($n['body'] ?? '');
    if ($body !== '') {
        $h .= '<div class="post-body">' . $body . '</div>';
    }
    if (!empty($n['has_video']) && !empty($n['tg_msg_id'])) {
        $tgUrl = 'https://t.me/' . $newsChan . '/' . (int)$n['tg_msg_id'];
        $h .= '<a class="post-tgvideo" href="' . esc($tgUrl) . '" target="_blank" rel="noopener">▶ Смотреть видео в Telegram</a>';
    }
    // Реакции (эмодзи)
    [$rcounts, $rmine] = news_reaction_data((int)$n['id'], $meId);
    $h .= '<div class="post-reactions" data-id="' . (int)$n['id'] . '" data-csrf="' . esc($csrf) . '"' . ($meId ? '' : ' data-guest="1"') . '>';
    foreach (news_react_emojis() as $em) {
        $c = $rcounts[$em] ?? 0;
        $h .= '<button type="button" class="react-btn' . ($rmine === $em ? ' active' : '') . '" data-emoji="' . esc($em) . '">'
            . '<span class="re">' . $em . '</span><span class="rc">' . ($c ? $c : '') . '</span></button>';
    }
    $h .= '</div>';
    $h .= '<div class="post-foot"><span class="post-meta"><span class="post-date">' . esc(date('d.m.Y', strtotime($n['published_at']))) . '</span>'
        . '<span class="post-views">👁 ' . (int)($n['views'] ?? 0) . '</span></span>';
    if (!empty($n['tg_msg_id'])) {
        $tgUrl = 'https://t.me/' . $newsChan . '/' . (int)$n['tg_msg_id'];
        $h .= '<a class="post-tglink" href="' . esc($tgUrl) . '" target="_blank" rel="noopener">Открыть в Telegram →</a>';
    }
    $h .= '</div>';
    $h .= '</div></article>';
    return $h;
};

if ($id && $dbok) {
    $st = db()->prepare('SELECT n.*, u.nickname AS author FROM news n
        LEFT JOIN users u ON u.id = n.author_id
        WHERE n.id = ? AND n.published_at IS NOT NULL');
    $st->execute([$id]);
    $item = $st->fetch();
    if (!$item) {
        http_response_code(404);
    } else {
        try {
            db()->prepare('UPDATE news SET views = views + 1 WHERE id = ?')->execute([$id]);
        } catch (Throwable $e) {
        }
        $item['views'] = (int)($item['views'] ?? 0) + 1;
    }
    // Частичный рендер для модалки «Показать полностью» (без шапки/подвала).
    if (isset($_GET['partial'])) {
        header('Content-Type: text/html; charset=utf-8');
        echo $item ? $renderPost($item) : '<p style="padding:18px;color:var(--tx2);">Новость не найдена.</p>';
        exit;
    }
    page_head($item ? $item['title'] : 'Новость не найдена', 'news');
    if ($item) {
        echo '<p style="margin:0 0 12px;"><a href="/news.php">← Все новости</a></p>';
        echo '<div class="post-single">' . $renderPost($item) . '</div>';
    } else {
        empty_state('Новость не найдена', 'Возможно, она была удалена.');
    }
    page_foot();
    exit;
}

$list = [];
if ($dbok) {
    $list = db()->query('SELECT id, title, published_at, image, images, has_video FROM news
        WHERE published_at IS NOT NULL
        ORDER BY pinned DESC, published_at DESC LIMIT 48')->fetchAll();
}

page_head('Новости', 'news');
echo '<h1>Новости</h1>';
echo '<p style="margin-top:-6px;display:flex;gap:8px;flex-wrap:wrap;">'
    . '<a class="btn btn-ghost" href="/rules.php">📖 Правила игры</a>'
    . '<a class="btn btn-ghost" href="/suggest.php">💡 Предложить идею</a></p>';

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
echo '<p style="color:var(--tx2);font-size:13px;margin:0 0 6px;">Зелёная карточка — ачивку уже кто-то получил, серая — пока никто. Наведи курсор на ачивку — справа появятся все, кто её получил (или нажми, чтобы открыть списком).</p>';
$earners = $dbok ? achievement_earners() : [];
$byGroup = [];
foreach (achievements_catalog() as $k => [$ic, $t, $d, $grp]) {
    $byGroup[$grp][$k] = [$ic, $t, $d];
}
echo '<div class="ach-wrap"><div class="ach-main">';
foreach ($byGroup as $grp => $items) {
    echo '<div style="font-size:11.5px;color:var(--tx2);text-transform:uppercase;letter-spacing:0.6px;margin:12px 0 6px;">' . esc($grp) . '</div>';
    echo '<div class="ach-grid">';
    foreach ($items as $k => [$ic, $t, $d]) {
        $who = $earners[$k] ?? [];
        $cnt = count($who);
        $whoJson = esc(json_encode(array_slice($who, 0, 200), JSON_UNESCAPED_UNICODE));
        echo '<div class="ach' . ($cnt > 0 ? ' ach-on' : '') . '" data-who="' . $whoJson . '" data-title="' . esc($t) . '">'
            . '<div class="ach-ic">' . $ic . '</div><div class="ach-t">' . esc($t) . '</div>'
            . '<div class="ach-d">' . esc($d) . '</div><div class="ach-cnt">' . $cnt . ' получ.</div></div>';
    }
    echo '</div>';
}
echo '</div>'; // .ach-main
echo '<aside class="ach-side" id="ach-side"><div class="ach-side-inner">'
    . '<div class="ach-side-empty">Наведи на ачивку — здесь появятся те, кто её получил</div>'
    . '</div></aside>';
echo '</div>'; // .ach-wrap

// ── Лента новостей (внизу страницы): превью-карточки, полный текст по кнопке ──
echo '<h2 style="margin:26px 0 12px;">Лента новостей</h2>';
if ($list) {
    echo '<div class="news-cards">';
    foreach ($list as $n) {
        $imgs = $imgsOf($n);
        $cover = $imgs[0] ?? '';
        echo '<a class="ncard" href="/news.php?id=' . (int)$n['id'] . '" data-id="' . (int)$n['id'] . '">';
        echo '<span class="ncard-cover">';
        if ($cover !== '') {
            echo '<img class="ncard-img" src="' . esc($cover) . '" alt="" loading="lazy">';
        } else {
            echo '<span class="ncard-noimg">' . logo_svg(34) . '</span>';
        }
        if (!empty($n['has_video'])) {
            echo '<span class="ncard-play" aria-hidden="true">▶</span>';
        }
        echo '</span>';
        echo '<span class="ncard-body"><span class="ncard-ttl">' . esc($n['title']) . '</span>'
            . '<span class="ncard-meta"><span class="ncard-date">' . esc(date('d.m.Y', strtotime($n['published_at']))) . '</span>'
            . '<span class="ncard-more">Показать полностью →</span></span></span>';
        echo '</a>';
    }
    echo '</div>';
} else {
    empty_state('Новостей пока нет', 'Анонсы вечеров, итоги турниров и объявления клуба будут появляться здесь.');
}

page_foot();
