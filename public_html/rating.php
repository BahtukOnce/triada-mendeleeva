<?php
require dirname(__DIR__) . '/inc/bootstrap.php';
require_once ROOT . '/inc/rating.php'; // общий wr_cell()

$ratings = [];
$current = null;
$rows = [];

if (db_ready()) {
    // основной (текущий сезон) первым, дальше исторические сезоны от новых к старым
    $ratings = db()->query('SELECT * FROM ratings WHERE is_active = 1 ORDER BY is_main DESC, id DESC')->fetchAll();
    $reqId = isset($_GET['r']) ? (int)$_GET['r'] : 0;
    foreach ($ratings as $r) {
        if ((int)$r['id'] === $reqId) {
            $current = $r;
        }
    }
    // прямой доступ по ?r=ID к рейтингу вне переключателя (турнирная таблица)
    if (!$current && $reqId) {
        $st0 = db()->prepare('SELECT * FROM ratings WHERE id = ?');
        $st0->execute([$reqId]);
        $current = $st0->fetch() ?: null;
    }
    if (!$current && $ratings) {
        $current = $ratings[0];
    }
    if ($current) {
        // Рейтинг всегда по принципу клуба (~Σ×Σ); дальнейшая сортировка — кликом по колонке (JS)
        $st = db()->prepare("SELECT rc.*, p.nickname, p.avatar, p.elo FROM rating_cache rc
            JOIN players p ON p.id = rc.player_id
            WHERE rc.rating_id = ?
            ORDER BY (rc.club_score IS NULL), rc.club_score DESC, rc.sum_total DESC LIMIT 300");
        $st->execute([$current['id']]);
        $rows = $st->fetchAll();
    }
}

page_head('Рейтинг', 'rating');
echo '<h1>Рейтинг</h1>';

if (count($ratings) > 1) {
    echo '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px;">';
    foreach ($ratings as $r) {
        $on = $current && (int)$r['id'] === (int)$current['id'];
        echo '<a class="tag ' . ($on ? 'tag-open' : '') . '" href="/rating.php?r=' . (int)$r['id'] . '">' . esc($r['title']) . '</a>';
    }
    echo '</div>';
}

// Турнирная таблица (рейтинг вне переключателя) — подписываем и даём ссылку назад
$inSwitcher = false;
foreach ($ratings as $r) {
    if ($current && (int)$r['id'] === (int)$current['id']) {
        $inSwitcher = true;
        break;
    }
}
if ($current && !$inSwitcher) {
    echo '<p style="margin:-4px 0 14px;color:var(--tx2);">Итоговая таблица турнира: <b style="color:var(--tx);">'
        . esc($current['title']) . '</b> · <a href="/tournaments.php">← ко всем турнирам</a></p>';
}

if ($rows) {
    // ── Номинации (среди игроков с минимумом игр) ──
    $minG = (int)(setting('min_games_nomination') ?: '10');
    $cands = array_filter($rows, fn($r) => (int)$r['games'] >= $minG);
    // $tie — доп-баллы за эту роль: при равном винрейте выше тот, у кого их больше
    // (а при равных допах — у кого больше игр в роли).
    $bestBy = function (array $cands, callable $w, callable $g, callable $tie, int $min = 1) {
        $best = null;
        $bw = -1;
        foreach ($cands as $r) {
            $gg = $g($r);
            if ($gg < $min) {
                continue;
            }
            $wr = $w($r) / $gg;
            if ($wr > $bw + 1e-9) {
                $bw = $wr;
                $best = $r;
            } elseif ($best && abs($wr - $bw) < 1e-9) {
                $ct = $tie($r);
                $bt = $tie($best);
                if ($ct > $bt + 1e-9 || (abs($ct - $bt) < 1e-9 && $gg > $g($best))) {
                    $best = $r;
                }
            }
        }
        return $best ? [$best, $bw] : null;
    };
    $mvp = null;
    foreach ($rows as $r) {
        if ((int)$r['games'] >= $minG) {
            $mvp = $r;
            break;
        }
    }
    $noms = [
        ['MVP клуба', $mvp ? [$mvp, null] : null, 'выше всех в рейтинге'],
        ['Лучший дон', $bestBy($cands, fn($r) => (int)$r['w_don'], fn($r) => (int)$r['g_don'], fn($r) => (float)($r['dop_don'] ?? 0), 4), 'дон'],
        ['Лучший шериф', $bestBy($cands, fn($r) => (int)$r['w_sher'], fn($r) => (int)$r['g_sher'], fn($r) => (float)($r['dop_sher'] ?? 0), 4), 'шериф'],
        ['Лучший красный', $bestBy($cands, fn($r) => (int)$r['w_civ'], fn($r) => (int)$r['g_civ'], fn($r) => (float)($r['dop_civ'] ?? 0), 10), 'мирный'],
        ['Лучший чёрный', $bestBy($cands, fn($r) => (int)$r['w_maf'], fn($r) => (int)$r['g_maf'], fn($r) => (float)($r['dop_maf'] ?? 0), 8), 'мафия'],
    ];
    $hasNoms = false;
    foreach ($noms as $n) {
        if ($n[1]) {
            $hasNoms = true;
        }
    }
    if ($hasNoms) {
        echo '<div class="noms-grid">';
        foreach ($noms as [$title, $data, $hint]) {
            if (!$data) {
                continue;
            }
            [$row, $wr] = $data;
            echo '<div class="nom-card">';
            echo '<div class="nom-title">' . esc($title) . '</div>';
            echo '<a class="nom-player" href="/player.php?id=' . (int)$row['player_id'] . '">'
                . avatar_html(['nickname' => $row['nickname'], 'avatar' => $row['avatar']], 34)
                . '<span>' . esc($row['nickname']) . '</span></a>';
            echo '<div class="nom-meta">' . ($wr !== null ? round($wr * 100) . '% · ' : '') . esc($hint) . '</div>';
            echo '</div>';
        }
        echo '</div>';
    }

    echo '<p style="color:var(--tx2);font-size:12.5px;margin:0 0 8px;">Рейтинг по принципу клуба (~Σ×Σ). '
        . 'Нажмите на заголовок колонки, чтобы отсортировать. Номинации — среди игроков от ' . $minG . ' игр.</p>';

    echo '<div style="display:flex;align-items:center;gap:10px;margin:0 0 10px;flex-wrap:wrap;">';
    echo '<label style="font-size:13px;color:var(--tx2);">Показывать игроков от</label>';
    echo '<input type="number" id="rt-mingames" min="0" value="0" style="width:80px;background:var(--sf2);color:var(--tx);border:1px solid var(--bd);border-radius:8px;padding:7px 10px;"> ';
    echo '<span style="font-size:13px;color:var(--tx2);">игр</span>';
    echo '<span id="rt-count" style="font-size:12.5px;color:var(--tx3);"></span></div>';

    echo '<div class="card" style="overflow-x:auto;padding:8px 10px;">';
    echo '<table class="tbl sortable rating-tbl" style="font-size:13px;">';
    echo '<thead>'
        . '<tr class="rt-groups"><th colspan="2"></th><th class="c-elo"></th>'
        . '<th colspan="11">Баллы и суммы</th><th class="c-cards-first" colspan="5">По картам</th></tr>'
        . '<tr>'
        . '<th data-type="num">#</th><th>Игрок</th><th class="num c-elo" data-type="num">ELO</th>'
        . '<th class="num c-club" data-type="num">~Σ×Σ</th><th class="num" data-type="num">~Σ</th><th class="num" data-type="num">Σ</th>'
        . '<th class="num" data-type="num">Σ+</th><th class="num" data-type="num">Игр</th><th class="num" data-type="num">ПУ</th><th class="num" data-type="num">ЛХ</th>'
        . '<th class="num" data-type="num">Допы</th><th class="num c-club" data-type="num">ср.доп</th><th class="num" data-type="num">−</th><th class="num" data-type="num">Ci</th>'
        . '<th class="c-cards c-cards-first" data-type="num">Общ</th><th class="c-cards" data-type="num">Мир</th>'
        . '<th class="c-cards" data-type="num">Маф</th><th class="c-cards" data-type="num">Шер</th><th class="c-cards" data-type="num">Дон</th>'
        . '</tr></thead><tbody>';
    $pos = 0;
    foreach ($rows as $row) {
        $pos++;
        $w = $row['w_civ'] + $row['w_maf'] + $row['w_sher'] + $row['w_don'];
        $medal = $pos === 1 ? '🥇' : ($pos === 2 ? '🥈' : ($pos === 3 ? '🥉' : ''));
        echo '<tr data-games="' . (int)$row['games'] . '"' . ($pos <= 3 ? ' class="rt-' . $pos . '"' : '') . '>';
        echo '<td data-sort="' . $pos . '">' . ($medal !== '' ? '<span style="font-size:15px;">' . $medal . '</span>' : $pos) . '</td>';
        echo '<td><a class="rt-player" href="/player.php?id=' . (int)$row['player_id'] . '" style="color:var(--tx);">'
            . avatar_html(['nickname' => $row['nickname'], 'avatar' => $row['avatar']], 26, 'margin-right:8px;')
            . '<span>' . esc($row['nickname']) . '</span></a></td>';
        echo '<td class="num c-elo" data-sort="' . (float)$row['elo'] . '"><b>' . number_format((float)$row['elo'], 0, '.', '') . '</b></td>';
        echo '<td class="num c-club" data-sort="' . (float)$row['club_score'] . '"><b>' . ($row['club_score'] !== null ? number_format((float)$row['club_score'], 2) : '—') . '</b></td>';
        echo '<td class="num" data-sort="' . (float)$row['avg_total'] . '">' . ($row['avg_total'] !== null ? number_format((float)$row['avg_total'], 2) : '—') . '</td>';
        echo '<td class="num" data-sort="' . (float)$row['sum_total'] . '">' . number_format((float)$row['sum_total'], 2) . '</td>';
        echo '<td class="num" data-sort="' . (float)$row['sum_plus'] . '">' . number_format((float)$row['sum_plus'], 2) . '</td>';
        echo '<td class="num" data-sort="' . (int)$row['games'] . '">' . (int)$row['games'] . '</td>';
        echo '<td class="num" data-sort="' . (int)$row['pu_count'] . '">' . (int)$row['pu_count'] . '</td>';
        echo '<td class="num" data-sort="' . (float)$row['lh_sum'] . '">' . number_format((float)$row['lh_sum'], 1) . '</td>';
        echo '<td class="num" data-sort="' . (float)$row['dop_sum'] . '">' . number_format((float)$row['dop_sum'], 1) . '</td>';
        $avgDop = (int)$row['games'] ? (float)$row['dop_sum'] / (int)$row['games'] : 0;
        echo '<td class="num c-club" data-sort="' . round($avgDop, 3) . '"><b>' . number_format($avgDop, 2) . '</b></td>';
        echo '<td class="num" data-sort="' . (float)$row['minus_sum'] . '">' . number_format((float)$row['minus_sum'], 1) . '</td>';
        echo '<td class="num" data-sort="' . (float)$row['ci_sum'] . '">' . number_format((float)$row['ci_sum'], 2) . '</td>';
        echo str_replace('c-cards"', 'c-cards c-cards-first"', wr_cell((int)$w, (int)$row['games'], (float)$row['dop_sum']));
        echo wr_cell((int)$row['w_civ'], (int)$row['g_civ'], (float)($row['dop_civ'] ?? 0));
        echo wr_cell((int)$row['w_maf'], (int)$row['g_maf'], (float)($row['dop_maf'] ?? 0));
        echo wr_cell((int)$row['w_sher'], (int)$row['g_sher'], (float)($row['dop_sher'] ?? 0));
        echo wr_cell((int)$row['w_don'], (int)$row['g_don'], (float)($row['dop_don'] ?? 0));
        echo '</tr>';
    }
    echo '</tbody></table></div>';
    echo '<p style="color:var(--tx2);font-size:12.5px;">ELO — динамический рейтинг (старт 1000). Слева — клубный счёт и баллы; справа (выделено) — '
        . '<b style="color:var(--tx2);">статистика по картам</b>: винрейт общий и по ролям. '
        . 'Σ — сумма итогов; Σ+ — допы + ЛХ + Ci; ~Σ — средний балл; ПУ — первоубиенный; ЛХ — лучший ход; Ci — компенсации.</p>';
    ?>
<script>
(function () {
  var inp = document.getElementById('rt-mingames'), cnt = document.getElementById('rt-count');
  if (!inp) return;
  try { inp.value = localStorage.getItem('rt-mingames') || '0'; } catch (e) {}
  function apply() {
    var min = parseInt(inp.value, 10) || 0;
    try { localStorage.setItem('rt-mingames', min); } catch (e) {}
    var rows = document.querySelectorAll('.rating-tbl tbody tr'), shown = 0;
    rows.forEach(function (tr) {
      var g = parseInt(tr.dataset.games, 10) || 0;
      var hide = g < min;
      tr.style.display = hide ? 'none' : '';
      if (!hide) shown++;
    });
    cnt.textContent = '— показано ' + shown + ' из ' + rows.length;
  }
  inp.addEventListener('input', apply);
  apply();
})();
</script>
    <?php
} else {
    empty_state('Рейтинг пока пуст', 'Таблица появится после переноса истории игр.');
}
page_foot();
