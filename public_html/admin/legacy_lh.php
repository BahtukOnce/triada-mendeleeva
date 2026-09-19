<?php
require dirname(__DIR__, 2) . '/inc/bootstrap.php';
require_once ROOT . '/inc/legacy_lh.php';
require_once ROOT . '/inc/rating.php';
// Дособрать историю: проставить легаси-играм первоубиенного и лучший ход (db/legacy_lh.txt).
// Сначала показываем, что получится, и только по кнопке применяем + пересчитываем рейтинг и ELO.
$u = require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $rep = legacy_lh_run(true);
    log_action((int)$u['id'], 'legacy_lh_apply', ['applied' => $rep['applied'], 'skipped' => count($rep['skip'])]);
    try {
        recompute_all_locked();   // ЛХ и Ci влияют на итоги, вклад в ELO и ачивки
    } catch (Throwable $e) {
        flash_set('err', 'Проставлено, но пересчёт упал: ' . $e->getMessage());
        redirect('/admin/legacy_lh.php');
    }
    flash_set('ok', 'Проставлено игр: ' . $rep['applied'] . ' · пропущено: ' . count($rep['skip']) . '. Рейтинг и ELO пересчитаны.');
    redirect('/admin/legacy_lh.php');
}

$rep = legacy_lh_run(false);
page_head('История: ПУ и ЛХ', '');
echo '<h1>Первоубиенный и лучший ход в исторических играх</h1>';
echo '<p style="color:var(--tx2);margin-top:-6px;">Импорт с mafiauniverse принёс только ники, роли и итоги. ПУ и ЛХ сняты отдельно '
    . '(<code>db/legacy_lh.txt</code>) — здесь они проставляются в игры сезонов 2022/23–2024/25. '
    . 'Бонус за ЛХ вычитается из допов: у них он уже был в итоге, а наш движок начислит его сам.</p>';

$ok = count($rep['ok']);
$skip = count($rep['skip']);
echo '<div class="card"><h2 style="margin-top:0;">Что получится</h2>';
echo '<p style="font-size:15px;">Строк в файле: <b>' . (int)$rep['total'] . '</b> · сопоставлено: <b style="color:var(--ok);">' . $ok
    . '</b> · не сопоставлено: <b style="color:' . ($skip ? 'var(--ac)' : 'var(--tx2)') . ';">' . $skip . '</b></p>';
if ($ok) {
    echo '<form method="post" action="/admin/legacy_lh.php" onsubmit="return confirm(\'Проставить ПУ и ЛХ в ' . $ok
        . ' исторических игр? Рейтинг и ELO будут пересчитаны по всей истории.\');">' . csrf_field()
        . '<button class="btn" type="submit">Проставить и пересчитать</button></form>';
}
echo '</div>';

if ($skip) {
    echo '<div class="card"><h2 style="margin-top:0;">Не сопоставлено (' . $skip . ')</h2>';
    echo '<table class="tbl"><tr><th>Дата</th><th>Игра в источнике</th><th>ПУ</th><th>Почему</th></tr>';
    foreach ($rep['skip'] as $r) {
        echo '<tr><td>' . esc($r['date']) . '</td><td>№' . (int)$r['gno'] . '</td>'
            . '<td>место ' . (int)$r['pu'] . ' · ' . esc($r['nick']) . '</td>'
            . '<td style="color:var(--tx2);">' . esc($r['why']) . '</td></tr>';
    }
    echo '</table></div>';
}

echo '<div class="card"><h2 style="margin-top:0;">Сопоставлено (' . $ok . ')</h2>';
echo '<table class="tbl"><tr><th>Дата</th><th>Игра</th><th>ПУ</th><th>ЛХ</th><th class="num">Допы было</th><th class="num">станет</th></tr>';
foreach ($rep['ok'] as $r) {
    $chg = abs($r['plus_was'] - $r['plus_now']) > 0.001;
    echo '<tr><td>' . esc($r['date']) . '</td><td>№' . (int)$r['game_no'] . '</td>'
        . '<td>место ' . (int)$r['pu'] . ' · ' . esc($r['nick']) . '</td>'
        . '<td>' . esc(implode(', ', $r['bm'])) . ($r['bonus'] > 0 ? ' <span style="color:var(--ok);">+' . $r['bonus'] . '</span>' : '') . '</td>'
        . '<td class="num">' . number_format($r['plus_was'], 2) . '</td>'
        . '<td class="num"' . ($chg ? ' style="color:var(--ac);"' : '') . '>' . number_format($r['plus_now'], 2) . '</td></tr>';
}
echo '</table></div>';
page_foot();
