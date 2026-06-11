<?php
require dirname(__DIR__) . '/inc/bootstrap.php';

$list = [];
if (db_ready()) {
    $list = db()->query('SELECT t.*,
            (SELECT COUNT(*) FROM tournament_regs r WHERE r.tournament_id = t.id) AS regs_cnt
        FROM tournaments t
        ORDER BY t.date_from DESC, t.id DESC LIMIT 50')->fetchAll();
}

$statusLabel = [
    'draft' => 'черновик', 'announced' => 'анонсирован', 'reg_open' => 'регистрация открыта',
    'live' => 'идёт сейчас', 'finished' => 'завершён',
];

page_head('Турниры', 'tournaments');
echo '<h1>Турниры</h1>';

if ($list) {
    foreach ($list as $t) {
        $tag = $t['status'] === 'reg_open' ? 'tag-open' : ($t['status'] === 'finished' ? '' : 'tag-ok');
        echo '<a class="card card-link" href="/tournament.php?id=' . (int)$t['id'] . '">';
        echo '<div class="section-head"><div style="display:flex;align-items:center;gap:12px;min-width:0;">';
        if (!empty($t['logo'])) {
            echo '<img src="' . esc($t['logo']) . '" alt="" style="width:42px;height:42px;object-fit:contain;border-radius:8px;flex:none;">';
        }
        echo '<h2 style="margin:0;color:var(--tx);">' . esc($t['title']) . '</h2></div>';
        echo '<span class="tag ' . $tag . '">' . esc($statusLabel[$t['status']] ?? $t['status']) . '</span></div>';
        $dates = $t['date_from'] ? date('d.m.Y', strtotime($t['date_from'])) : '';
        if ($t['date_to'] && $t['date_to'] !== $t['date_from']) {
            $dates .= ' — ' . date('d.m.Y', strtotime($t['date_to']));
        }
        echo '<p style="color:var(--tx2);font-size:13.5px;margin:8px 0 0;">'
            . esc($dates)
            . ($t['location'] ? ' · ' . esc($t['location']) : '')
            . ' · столов: ' . (int)$t['tables_count']
            . ' · участников: ' . (int)$t['regs_cnt'] . '</p>';
        echo '</a>';
    }
} else {
    empty_state('Турниров пока нет', '«Точка кипения», «Турнир победы», кубки РХТУ — вся турнирная история переедет сюда на этапе 2, а новые турниры будут анонсироваться здесь.');
}
page_foot();
