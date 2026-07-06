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

// ── ELO простыми словами (сворачиваемо, разворачивается по ссылке #elo) ──
echo <<<'HTML'
<details class="elo-explain" id="elo">
  <summary>❓ Как считается ELO — простыми словами</summary>
  <div class="elo-explain-body">
    <p><b>ELO — это «сила» игрока одним числом.</b> У всех старт — <b>1000</b>. Побеждаешь — растёт, проигрываешь — падает. Но не на фиксированную величину: система смотрит, <b>кого</b> ты обыграл и <b>как</b> сыграл лично.</p>
    <div class="elo-steps">
      <div class="elo-step"><span class="elo-step-n">1</span><div><b>Команда на команду.</b> 🔴 Красные (мир + шериф) против ⚫ чёрных (мафия + дон). Система сравнивает среднюю силу двух команд.</div></div>
      <div class="elo-step"><span class="elo-step-n">2</span><div><b>Ждали или нет.</b> От сильной команды ждут победы: обыграть заведомо слабых — очков мало, а проиграть им — обидно и дорого. Обыграть тех, кто сильнее, — <b>жирный плюс</b>.</div></div>
      <div class="elo-step"><span class="elo-step-n">3</span><div><b>Личный вклад.</b> Командный «приз» делится не поровну. Больше получает тот, кто <b>наиграл больше баллов</b> за игру и лично сидел против более сильных соперников.</div></div>
      <div class="elo-step"><span class="elo-step-n">4</span><div><b>Поражения — мягче.</b> За проигрыш теряешь меньше, чем получил бы за такую же победу. Один неудачный вечер не обрушит рейтинг.</div></div>
      <div class="elo-step"><span class="elo-step-n">5</span><div><b>Ниже 100 не упасть.</b> У ELO есть пол — совсем в минус уйти нельзя.</div></div>
    </div>
    <div class="elo-ex">
      <div class="elo-ex-ttl">Одна и та же победа стоит по-разному</div>
      <div class="elo-ex-row"><span class="elo-ex-lbl">Обыграли команду <b>сильнее</b> вашей</span><span class="elo-ex-bar"><i style="width:100%;background:var(--ok);"></i></span><span class="elo-ex-v">＋ много</span></div>
      <div class="elo-ex-row"><span class="elo-ex-lbl">Обыграли <b>равных</b></span><span class="elo-ex-bar"><i style="width:60%;background:var(--ok);"></i></span><span class="elo-ex-v">＋ средне</span></div>
      <div class="elo-ex-row"><span class="elo-ex-lbl">Обыграли <b>слабее</b> вас</span><span class="elo-ex-bar"><i style="width:32%;background:var(--ok);"></i></span><span class="elo-ex-v">＋ немного</span></div>
      <div class="elo-ex-row"><span class="elo-ex-lbl">Проиграли <b>слабым</b> (сенсация)</span><span class="elo-ex-bar"><i style="width:55%;background:var(--ac);"></i></span><span class="elo-ex-v">－ заметно</span></div>
      <div class="elo-ex-row"><span class="elo-ex-lbl">Проиграли <b>сильным</b></span><span class="elo-ex-bar"><i style="width:22%;background:var(--ac);"></i></span><span class="elo-ex-v">－ чуть-чуть</span></div>
    </div>
    <div class="elo-ex">
      <div class="elo-ex-ttl">Внутри победившей команды приз делят по вкладу</div>
      <div class="elo-ex-row"><span class="elo-ex-lbl">🅰️ активная игра, +2 балла, против сильных</span><span class="elo-ex-bar"><i style="width:92%;background:var(--ok);"></i></span><span class="elo-ex-v">＋ больше</span></div>
      <div class="elo-ex-row"><span class="elo-ex-lbl">🅱️ тихая игра, 0 баллов</span><span class="elo-ex-bar"><i style="width:45%;background:var(--ok);"></i></span><span class="elo-ex-v">＋ меньше</span></div>
      <p style="margin:8px 0 0;color:var(--tx3);font-size:12px;">Оба в плюсе — команда победила. Но А наградили сильнее за вклад.</p>
    </div>

    <div class="elo-sub">📐 Точная формула — для тех, кто хочет докопаться</div>
    <p>Никакой магии: всё считается по этим правилам. У каждого — набор констант и три шага.</p>
    <div class="elo-consts">
      <span>старт <b>1000</b></span><span>K (масштаб) <b>310</b></span><span>делитель команд <b>2500</b></span>
      <span>делитель личный <b>900</b></span><span>база неожиданности <b>0.35</b></span><span>множитель проигрыша <b>0.6</b></span><span>пол <b>100</b></span>
    </div>

    <div class="elo-step-ttl">Шаг 1. Ожидание и общая дельта команды</div>
    <p>Сравниваем среднюю силу команд и считаем, насколько результат отличается от ожидаемого:</p>
    <div class="elo-formula">E_красных = 1 / (1 + 10^((ELO_чёрных − ELO_красных) / 2500))
Δ_команды = 310 × (S − E_красных)     S = 1 победа · 0.5 ничья · 0 поражение</div>
    <p>Чёрные получают ровно <b>−Δ_команды</b>. Делитель 2500 большой → одна победа важнее, чем разница в силе: обыграть чуть более сильных приятно, но не решает всё.</p>

    <div class="elo-step-ttl">Шаг 2. Вес каждого игрока в команде</div>
    <p>Общая дельта делится не поровну. У каждого игрока — свой «вес»: насколько лично он не был обязан побеждать (<b>неожиданность</b>) × насколько заметно сыграл (<b>вклад</b>, по баллам за игру):</p>
    <div class="elo-formula">E_личное      = 1 / (1 + 10^((ELO_соперников − ELO_игрока) / 900))
неожиданность = 0.35 + | результат_команды − E_личное |
вклад         = max(0.4 ; 1 + 0.25 × знак × (баллы − среднее_по_команде))
вес           = неожиданность × вклад</div>
    <p><b>знак</b> = +1 при победе команды, −1 при поражении (в проигрыше сильнее «штрафует» слабую игру). <b>баллы</b> = плюсы − минусы за игру. Делитель 900 узкий → личная сила соперников здесь ощутимее, чем на уровне команд.</p>

    <div class="elo-step-ttl">Шаг 3. Доля, смягчение проигрыша и пол</div>
    <div class="elo-formula">Δ_игрока = Δ_команды × (вес_игрока / сумма_весов_команды)
если Δ_игрока &lt; 0 → Δ_игрока × 0.6      (проигрыш мягче победы)
ELO_новое = max(100 ; ELO_старое + Δ_игрока)</div>

    <div class="elo-sub">🧮 Полный пример на реальных числах</div>
    <p>🔴 Красные (средняя сила <b>1000</b>) обыграли ⚫ чёрных (<b>1100</b>). Считаем шаг 1:</p>
    <div class="elo-formula">E_красных = 1 / (1 + 10^((1100 − 1000)/2500)) = 1 / (1 + 10^0.04) ≈ 0.477
Δ_команды = 310 × (1 − 0.477) ≈ +162   на всю команду красных</div>
    <p>Теперь делим эти +162 между пятью красными по весам. Возьмём игрока <b>А</b> (ELO 950, за игру +2 балла при среднем по команде +0.4):</p>
    <div class="elo-formula">E_личное      = 1/(1 + 10^((1100 − 950)/900)) = 1/(1+10^0.167) ≈ 0.41
неожиданность = 0.35 + |1 − 0.41| = 0.94
вклад         = max(0.4 ; 1 + 0.25 × (+2 − 0.4)) = 1.40
вес А         = 0.94 × 1.40 = 1.32</div>
    <p>Сумма весов всех пятерых красных ≈ <b>4.58</b>, поэтому доля А = 1.32 / 4.58 = <b>29%</b>, и Δ_А = 162 × 0.29 ≈ <b>+47</b>. Аналогично для остальных:</p>
    <div class="elo-tbl-wrap"><table class="tbl elo-worked">
      <tr><th>Игрок</th><th class="num">ELO до</th><th class="num">Баллы</th><th class="num">Вес</th><th class="num">Доля</th><th class="num">Δ</th><th class="num">ELO после</th></tr>
      <tr><td>А</td><td class="num">950</td><td class="num">+2.0</td><td class="num">1.32</td><td class="num">29%</td><td class="num" style="color:var(--ok);">+47</td><td class="num"><b>997</b></td></tr>
      <tr><td>Б</td><td class="num">1000</td><td class="num">+0.5</td><td class="num">0.94</td><td class="num">20%</td><td class="num" style="color:var(--ok);">+33</td><td class="num"><b>1033</b></td></tr>
      <tr><td>В</td><td class="num">1050</td><td class="num">0.0</td><td class="num">0.79</td><td class="num">17%</td><td class="num" style="color:var(--ok);">+28</td><td class="num"><b>1078</b></td></tr>
      <tr><td>Г</td><td class="num">1000</td><td class="num">−0.5</td><td class="num">0.71</td><td class="num">15%</td><td class="num" style="color:var(--ok);">+25</td><td class="num"><b>1025</b></td></tr>
      <tr><td>Д</td><td class="num">1000</td><td class="num">0.0</td><td class="num">0.82</td><td class="num">18%</td><td class="num" style="color:var(--ok);">+29</td><td class="num"><b>1029</b></td></tr>
    </table></div>
    <p>Видно главное: <b>А</b> (был слабее всех, но сыграл активнее) получил +47, а <b>В</b> (самый сильный, но тихий) — лишь +28. Оба победили, но вклад и «андердог-фактор» развели их доли.</p>
    <p style="color:var(--tx3);font-size:12.5px;">⚫ Чёрные делят свои −162 так же, по своим весам, и каждый отрицательный Δ ещё умножается на 0.6 — поэтому теряют мягче: сырые −30 превращаются в −18. Ниже 100 ELO не опускается.</p>

    <p style="color:var(--tx3);font-size:12.5px;margin-bottom:2px;">ELO пересчитывается по всем сыгранным играм в хронологическом порядке — включая перенесённые исторические. Поэтому рейтинг «живой»: добавили старую игру — картина может немного сдвинуться.</p>
  </div>
</details>
<script>if(location.hash==='#elo'){var d=document.getElementById('elo');if(d){d.open=true;d.scrollIntoView({block:'start'});}}</script>
HTML;

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
    $mp = current_player();
    $mePid = $mp ? (int)$mp['id'] : 0;
    $pos = 0;
    foreach ($rows as $row) {
        $pos++;
        $w = $row['w_civ'] + $row['w_maf'] + $row['w_sher'] + $row['w_don'];
        $medal = $pos === 1 ? '🥇' : ($pos === 2 ? '🥈' : ($pos === 3 ? '🥉' : ''));
        $isMe = $mePid && (int)$row['player_id'] === $mePid;
        echo '<tr data-games="' . (int)$row['games'] . '"' . ($pos <= 3 ? ' class="rt-' . $pos . '"' : '') . ($isMe ? ' style="' . me_row_style() . '"' : '') . '>';
        echo '<td data-sort="' . $pos . '">' . ($medal !== '' ? '<span style="font-size:15px;">' . $medal . '</span>' : $pos) . '</td>';
        echo '<td><a class="rt-player" href="/player.php?id=' . (int)$row['player_id'] . '" style="' . me_nick_style($isMe) . '">'
            . avatar_html(['nickname' => $row['nickname'], 'avatar' => $row['avatar']], 26, 'margin-right:8px;')
            . '<span>' . esc($row['nickname']) . casper_ghost($row['nickname']) . '</span></a></td>';
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
