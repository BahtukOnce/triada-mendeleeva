<?php
// ⚔️ Дуэль: очные встречи двух игроков + автоматические связи (соратники/немезиды/жертвы).
// Всё считается по реальным сыгранным играм (game_seats × games), за всю историю клуба.
require dirname(__DIR__) . '/inc/bootstrap.php';

const V_RED = ['civ', 'sheriff'];
$team = fn(string $role): string => in_array($role, V_RED, true) ? 'red' : 'black';

// ── Входные параметры: id (a, b) или ники (an, bn) из формы ──
$aId = (int)($_GET['a'] ?? 0);
$bId = (int)($_GET['b'] ?? 0);
$resolve = function (string $nick): int {
    $nick = trim($nick);
    if ($nick === '') {
        return 0;
    }
    $st = db()->prepare('SELECT id FROM players WHERE LOWER(nickname) = LOWER(?) AND banned_at IS NULL LIMIT 1');
    $st->execute([$nick]);
    return (int)($st->fetchColumn() ?: 0);
};
if (!$aId && !empty($_GET['an'])) {
    $aId = $resolve((string)$_GET['an']);
}
if (!$bId && !empty($_GET['bn'])) {
    $bId = $resolve((string)$_GET['bn']);
}

$loadP = function (int $id): ?array {
    if ($id < 1) {
        return null;
    }
    $st = db()->prepare('SELECT id, nickname, avatar, flair, elo FROM players WHERE id = ? AND banned_at IS NULL');
    $st->execute([$id]);
    $p = $st->fetch() ?: null;
    return ($p && !is_casper($p['nickname'])) ? $p : null;
};
$pa = $loadP($aId);
$pb = $aId !== $bId ? $loadP($bId) : null;

// Все игроки с играми — для выпадашек
$allP = db()->query("SELECT p.id, p.nickname FROM players p
    WHERE p.banned_at IS NULL AND EXISTS (SELECT 1 FROM game_seats gs WHERE gs.player_id = p.id)
    ORDER BY p.nickname")->fetchAll();
$allP = array_values(array_filter($allP, fn($p) => !is_casper($p['nickname'])));

$plg = function (int $n): string {
    $a = $n % 10;
    $b = $n % 100;
    $w = ($a === 1 && $b !== 11) ? 'игра' : ((in_array($a, [2, 3, 4], true) && !in_array($b, [12, 13, 14], true)) ? 'игры' : 'игр');
    return $n . ' ' . $w;
};
$pct = fn(int $w, int $g): int => $g > 0 ? (int)round($w / $g * 100) : 0;

$meta = ['url' => 'versus.php', 'description' => 'Дуэли игроков клуба «Триада Менделеева»: очные встречи, соратники и немезиды — по всем сыгранным играм.'];
if ($pa && $pb) {
    $meta['url'] = 'versus.php?a=' . (int)$pa['id'] . '&b=' . (int)$pb['id'];
    $meta['description'] = 'Дуэль: ' . $pa['nickname'] . ' vs ' . $pb['nickname'] . ' — очные встречи, счёт и совместные игры.';
}
page_head('⚔️ Дуэль' . ($pa && $pb ? ': ' . $pa['nickname'] . ' vs ' . $pb['nickname'] : ''), 'players', $meta);

echo '<h1 style="display:flex;align-items:center;gap:10px;">⚔️ Дуэль</h1>';
echo '<p style="color:var(--tx2);font-size:14px;margin-top:-6px;">Очные встречи двух игроков по всем сыгранным играм клуба: счёт по разные стороны, винрейт в одной команде, история совместных столов.</p>';

// ── Форма выбора пары ──
echo '<div class="card"><form method="get" action="/versus.php" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;">';
$dl = '<datalist id="duel-dl">';
foreach ($allP as $p) {
    $dl .= '<option value="' . esc($p['nickname']) . '">';
}
$dl .= '</datalist>';
echo $dl;
echo '<div class="field" style="margin:0;flex:1;min-width:180px;"><label>Игрок 1</label>'
    . '<input type="text" name="an" list="duel-dl" autocomplete="off" placeholder="ник" value="' . esc($pa['nickname'] ?? '') . '"></div>';
echo '<div style="align-self:center;font-weight:800;color:var(--tx2);padding:0 2px;">vs</div>';
echo '<div class="field" style="margin:0;flex:1;min-width:180px;"><label>Игрок 2</label>'
    . '<input type="text" name="bn" list="duel-dl" autocomplete="off" placeholder="ник" value="' . esc($pb['nickname'] ?? '') . '"></div>';
echo '<button class="btn" type="submit">Сравнить</button>';
echo '</form></div>';

// ════════════════════════ РЕЖИМ ПАРЫ ════════════════════════
if ($pa && $pb) {
    // Общие игры пары
    $st = db()->prepare("SELECT g.id, g.winner, g.context, g.game_no,
            sa.role ra, sb.role rb,
            COALESCE(d.date, t.date_from) gdate, d.id day_id, d.title day_title, t.id t_id, t.title t_title
        FROM games g
        JOIN game_seats sa ON sa.game_id = g.id AND sa.player_id = ?
        JOIN game_seats sb ON sb.game_id = g.id AND sb.player_id = ?
        LEFT JOIN game_days d ON d.id = g.day_id
        LEFT JOIN tournaments t ON t.id = g.tournament_id
        WHERE g.status = 'finished' AND g.winner IS NOT NULL
        ORDER BY COALESCE(d.date, t.date_from) DESC, g.id DESC");
    $st->execute([(int)$pa['id'], (int)$pb['id']]);
    $joint = $st->fetchAll();

    $tg = 0;      // вместе: игр
    $tw = 0;      // вместе: побед
    $tRed = [0, 0];   // вместе за красных: [игр, побед]
    $tBlack = [0, 0]; // вместе за чёрных
    $aWin = 0;
    $bWin = 0;
    $vsDraw = 0;
    $aMafG = 0;   // A чёрный против красного B
    $aMafW = 0;
    $bMafG = 0;
    $bMafW = 0;
    foreach ($joint as $g) {
        $ta = $team($g['ra']);
        $tb = $team($g['rb']);
        if ($ta === $tb) {
            $tg++;
            $won = $g['winner'] === $ta;
            if ($won) {
                $tw++;
            }
            if ($ta === 'red') {
                $tRed[0]++;
                $tRed[1] += $won ? 1 : 0;
            } else {
                $tBlack[0]++;
                $tBlack[1] += $won ? 1 : 0;
            }
            continue;
        }
        if ($g['winner'] === 'draw') {
            $vsDraw++;
        } elseif ($g['winner'] === $ta) {
            $aWin++;
        } else {
            $bWin++;
        }
        if ($ta === 'black') {
            $aMafG++;
            $aMafW += $g['winner'] === 'black' ? 1 : 0;
        } else {
            $bMafG++;
            $bMafW += $g['winner'] === 'black' ? 1 : 0;
        }
    }
    $vsG = $aWin + $bWin + $vsDraw;

    // Общая статистика каждого (за всю историю)
    $ovr = function (int $pid): array {
        $st = db()->prepare("SELECT COUNT(*) g,
                SUM((g.winner='red' AND gs.role IN ('civ','sheriff')) OR (g.winner='black' AND gs.role IN ('maf','don'))) w
            FROM game_seats gs JOIN games g ON g.id = gs.game_id
            WHERE gs.player_id = ? AND g.status='finished' AND g.winner IN ('red','black')");
        $st->execute([$pid]);
        $r = $st->fetch() ?: ['g' => 0, 'w' => 0];
        return [(int)$r['g'], (int)$r['w']];
    };
    [$aG, $aW] = $ovr((int)$pa['id']);
    [$bG, $bW] = $ovr((int)$pb['id']);

    // ── Заголовок-вердикт ──
    $verdict = '';
    if ($vsG >= 5 && ($aWin + $bWin) > 0) {
        $share = $aWin / max(1, $aWin + $bWin);
        if ($share >= 0.65) {
            $verdict = '😈 <b>' . esc($pa['nickname']) . '</b> — немезида для <b>' . esc($pb['nickname']) . '</b>';
        } elseif ($share <= 0.35) {
            $verdict = '😈 <b>' . esc($pb['nickname']) . '</b> — немезида для <b>' . esc($pa['nickname']) . '</b>';
        } else {
            $verdict = '⚖️ Равная борьба — исход решает конкретный вечер';
        }
    }
    if ($tg >= 5 && $tw / max(1, $tg) >= 0.65) {
        $verdict .= ($verdict ? ' · ' : '') . '🤝 в одной команде — грозная сила (' . $pct($tw, $tg) . '% побед)';
    }

    // ── Карточки игроков ──
    $card = function (array $p, int $g, int $w, bool $right = false) use ($pct): string {
        $wr = $pct($w, $g);
        $col = $wr >= 60 ? 'var(--ok)' : ($wr < 42 && $g > 0 ? 'var(--ac)' : 'var(--tx)');
        return '<a href="/player.php?id=' . (int)$p['id'] . '" style="flex:1;min-width:200px;display:flex;align-items:center;gap:12px;color:var(--tx);'
            . ($right ? 'flex-direction:row-reverse;text-align:right;' : '') . '">'
            . avatar_html($p, 52)
            . '<span><b style="font-size:17px;">' . player_label($p) . '</b>'
            . '<span style="display:block;font-size:12.5px;color:var(--tx2);">ELO ' . (int)round((float)$p['elo'])
            . ' · игр ' . $g . ' · <b style="color:' . $col . ';">' . ($g ? $wr . '%' : '—') . '</b> побед</span></span></a>';
    };
    echo '<div class="card">';
    echo '<div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;">';
    echo $card($pa, $aG, $aW);
    echo '<div style="font-size:26px;font-weight:800;color:var(--ac);flex:none;">VS</div>';
    echo $card($pb, $bG, $bW, true);
    echo '</div>';
    if ($verdict) {
        echo '<p style="margin:12px 0 0;padding-top:12px;border-top:1px solid var(--bd);font-size:14px;">' . $verdict . '</p>';
    }
    echo '</div>';

    if (!$joint) {
        empty_state('За одним столом ещё не встречались', 'Как только сыграют вместе — здесь появится счёт и история встреч.');
    } else {
        // ── Очный счёт ──
        echo '<div class="grid-2">';
        echo '<div class="card"><h2 style="margin-top:0;">⚔️ По разные стороны <span style="color:var(--tx3);font-weight:400;font-size:13px;">· ' . $plg($vsG) . '</span></h2>';
        if ($vsG) {
            echo '<div style="display:flex;align-items:baseline;justify-content:center;gap:14px;font-variant-numeric:tabular-nums;">'
                . '<span style="font-size:40px;font-weight:800;color:' . ($aWin >= $bWin ? 'var(--ok)' : 'var(--tx)') . ';">' . $aWin . '</span>'
                . '<span style="font-size:20px;color:var(--tx3);">:</span>'
                . '<span style="font-size:40px;font-weight:800;color:' . ($bWin >= $aWin ? 'var(--ok)' : 'var(--tx)') . ';">' . $bWin . '</span>'
                . '</div>';
            echo '<div style="display:flex;justify-content:center;gap:24px;font-size:12.5px;color:var(--tx2);margin-top:2px;">'
                . '<span>' . esc($pa['nickname']) . '</span><span>' . esc($pb['nickname']) . '</span></div>';
            if ($vsDraw) {
                echo '<p style="text-align:center;color:var(--tx3);font-size:12.5px;margin:6px 0 0;">и ' . $plg($vsDraw) . ' вничью</p>';
            }
            $barA = $pct($aWin, max(1, $aWin + $bWin));
            echo '<div style="height:8px;border-radius:4px;background:var(--sf2);overflow:hidden;margin-top:12px;display:flex;">'
                . '<span style="width:' . $barA . '%;background:var(--ok);"></span>'
                . '<span style="flex:1;background:var(--ac);opacity:.75;"></span></div>';
            $chips = [];
            if ($aMafG) {
                $chips[] = esc($pa['nickname']) . ' за чёрных против ' . esc($pb['nickname']) . ': <b>' . $aMafW . '/' . $aMafG . '</b>';
            }
            if ($bMafG) {
                $chips[] = esc($pb['nickname']) . ' за чёрных против ' . esc($pa['nickname']) . ': <b>' . $bMafW . '/' . $bMafG . '</b>';
            }
            if ($chips) {
                echo '<div style="margin-top:12px;display:flex;flex-direction:column;gap:5px;font-size:12.5px;color:var(--tx2);">'
                    . implode('', array_map(fn($c) => '<span>🌑 ' . $c . '</span>', $chips)) . '</div>';
            }
        } else {
            echo '<p style="color:var(--tx3);margin:0;">По разные стороны ещё не играли.</p>';
        }
        echo '</div>';

        echo '<div class="card"><h2 style="margin-top:0;">🤝 В одной команде <span style="color:var(--tx3);font-weight:400;font-size:13px;">· ' . $plg($tg) . '</span></h2>';
        if ($tg) {
            $twr = $pct($tw, $tg);
            $col = $twr >= 60 ? 'var(--ok)' : ($twr < 42 ? 'var(--ac)' : 'var(--tx)');
            echo '<div style="text-align:center;"><span style="font-size:40px;font-weight:800;color:' . $col . ';">' . $twr . '%</span>'
                . '<span style="display:block;font-size:12.5px;color:var(--tx2);">побед вместе (' . $tw . ' из ' . $tg . ')</span></div>';
            echo '<div style="height:8px;border-radius:4px;background:var(--sf2);overflow:hidden;margin-top:12px;">'
                . '<span style="display:block;height:100%;width:' . $twr . '%;background:' . $col . ';"></span></div>';
            $rows = [];
            if ($tRed[0]) {
                $rows[] = '❤️ за красных: <b>' . $tRed[1] . '/' . $tRed[0] . '</b> (' . $pct($tRed[1], $tRed[0]) . '%)';
            }
            if ($tBlack[0]) {
                $rows[] = '🖤 за чёрных: <b>' . $tBlack[1] . '/' . $tBlack[0] . '</b> (' . $pct($tBlack[1], $tBlack[0]) . '%)';
            }
            if ($rows) {
                echo '<div style="margin-top:12px;display:flex;flex-direction:column;gap:5px;font-size:12.5px;color:var(--tx2);">'
                    . implode('', array_map(fn($c) => '<span>' . $c . '</span>', $rows)) . '</div>';
            }
        } else {
            echo '<p style="color:var(--tx3);margin:0;">В одной команде ещё не играли.</p>';
        }
        echo '</div>';
        echo '</div>';

        // ── История встреч ──
        $roleLbl = ['civ' => 'Мирный', 'sheriff' => 'Шериф', 'maf' => 'Мафия', 'don' => 'Дон'];
        echo '<div class="card"><h2 style="margin-top:0;">История встреч <span style="color:var(--tx3);font-weight:400;font-size:13px;">· последние ' . min(15, count($joint)) . ' из ' . count($joint) . '</span></h2>';
        echo '<div style="overflow-x:auto;"><table class="tbl" style="font-size:13px;">';
        echo '<tr><th>Дата</th><th>Где</th><th>' . esc($pa['nickname']) . '</th><th>' . esc($pb['nickname']) . '</th><th>Итог</th></tr>';
        foreach (array_slice($joint, 0, 15) as $g) {
            $where = $g['context'] === 'day'
                ? '<a href="/day.php?id=' . (int)$g['day_id'] . '">' . esc((string)$g['day_title']) . '</a>'
                : '<a href="/tournament.php?id=' . (int)$g['t_id'] . '">' . esc((string)$g['t_title']) . '</a>';
            $ta = $team($g['ra']);
            $tb = $team($g['rb']);
            $cell = function (string $role, string $t) use ($g, $roleLbl): string {
                $won = $g['winner'] === $t;
                $mark = $g['winner'] === 'draw' ? '' : ($won ? ' <span style="color:var(--ok);">✓</span>' : ' <span style="color:var(--ac);">✗</span>');
                return role_dot($role) . $roleLbl[$role] . $mark;
            };
            $res = $g['winner'] === 'draw' ? 'ничья'
                : ($ta === $tb
                    ? ($g['winner'] === $ta ? '<span style="color:var(--ok);">вместе победили</span>' : '<span style="color:var(--ac);">вместе проиграли</span>')
                    : ('верх взял ' . esc($g['winner'] === $ta ? $pa['nickname'] : $pb['nickname'])));
            echo '<tr><td style="white-space:nowrap;">' . ($g['gdate'] ? date('d.m.Y', strtotime((string)$g['gdate'])) : '—') . '</td>'
                . '<td>' . $where . ' · игра ' . (int)$g['game_no'] . '</td>'
                . '<td style="white-space:nowrap;">' . $cell($g['ra'], $ta) . '</td>'
                . '<td style="white-space:nowrap;">' . $cell($g['rb'], $tb) . '</td>'
                . '<td>' . $res . '</td></tr>';
        }
        echo '</table></div></div>';
    }
}

// ════════════════════════ РЕЖИМ СВЯЗЕЙ ОДНОГО ИГРОКА ════════════════════════
if ($pa && !$pb) {
    // Все соседства игрока по столам: с кем играл и как сложилось
    $st = db()->prepare("SELECT gb.player_id pid, p2.nickname, p2.avatar, p2.flair,
            ga.role ra, gb.role rb, g.winner
        FROM game_seats ga
        JOIN games g ON g.id = ga.game_id AND g.status = 'finished' AND g.winner IN ('red','black')
        JOIN game_seats gb ON gb.game_id = ga.game_id AND gb.player_id <> ga.player_id
        JOIN players p2 ON p2.id = gb.player_id AND p2.banned_at IS NULL
        WHERE ga.player_id = ?");
    $st->execute([(int)$pa['id']]);
    $agg = [];
    foreach ($st->fetchAll() as $r) {
        if (is_casper((string)$r['nickname'])) {
            continue;
        }
        $pid = (int)$r['pid'];
        if (!isset($agg[$pid])) {
            $agg[$pid] = ['p' => ['id' => $pid, 'nickname' => $r['nickname'], 'avatar' => $r['avatar'], 'flair' => $r['flair']],
                'tg' => 0, 'tw' => 0, 'og' => 0, 'ow' => 0];
        }
        $ta = $team($r['ra']);
        $tb = $team($r['rb']);
        if ($ta === $tb) {
            $agg[$pid]['tg']++;
            $agg[$pid]['tw'] += $r['winner'] === $ta ? 1 : 0;
        } else {
            $agg[$pid]['og']++;
            $agg[$pid]['ow'] += $r['winner'] === $ta ? 1 : 0;
        }
    }

    $MIN = 5;
    $mates = array_filter($agg, fn($x) => $x['tg'] >= $MIN);
    usort($mates, fn($x, $y) => [$y['tw'] / $y['tg'], $y['tg']] <=> [$x['tw'] / $x['tg'], $x['tg']]);
    $nemeses = array_filter($agg, fn($x) => $x['og'] >= $MIN);
    usort($nemeses, fn($x, $y) => [1 - $y['ow'] / $y['og'], $y['og']] <=> [1 - $x['ow'] / $x['og'], $x['og']]);
    $preys = array_filter($agg, fn($x) => $x['og'] >= $MIN);
    usort($preys, fn($x, $y) => [$y['ow'] / $y['og'], $y['og']] <=> [$x['ow'] / $x['og'], $x['og']]);

    echo '<div class="card" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">'
        . avatar_html($pa, 44) . '<div><b style="font-size:16px;">' . player_label($pa) . '</b>'
        . '<div style="font-size:12.5px;color:var(--tx2);">связи по всем играм клуба · минимум ' . $plg($MIN) . ' для попадания в списки</div></div>'
        . '<a class="tag" style="margin-left:auto;" href="/player.php?id=' . (int)$pa['id'] . '">профиль →</a></div>';

    $renderList = function (string $title, string $hint, array $list, string $mode) use ($pa, $pct, $plg): void {
        echo '<div class="card"><h2 style="margin-top:0;">' . $title . '</h2>';
        echo '<p style="color:var(--tx3);font-size:12px;margin:-6px 0 10px;">' . $hint . '</p>';
        if (!$list) {
            echo '<p style="color:var(--tx3);margin:0;">Пока мало совместных игр — списки появятся с опытом.</p></div>';
            return;
        }
        foreach (array_slice($list, 0, 5) as $x) {
            [$g, $w] = $mode === 'mate' ? [$x['tg'], $x['tw']] : [$x['og'], $x['ow']];
            $shown = $mode === 'nemesis' ? $g - $w : $w; // немезида: сколько раз ОНИ взяли верх
            $wr = $pct($shown, $g);
            $col = $mode === 'nemesis' ? 'var(--ac)' : 'var(--ok)';
            echo '<div style="display:flex;align-items:center;gap:10px;padding:7px 0;border-top:1px solid var(--bd);">'
                . '<a href="/player.php?id=' . (int)$x['p']['id'] . '" style="display:inline-flex;align-items:center;gap:8px;color:var(--tx);font-weight:600;min-width:0;">'
                . avatar_html($x['p'], 26) . '<span style="overflow:hidden;text-overflow:ellipsis;">' . player_label($x['p']) . '</span></a>'
                . '<span style="margin-left:auto;font-size:12.5px;color:var(--tx2);white-space:nowrap;">' . $shown . ' из ' . $g . ' · <b style="color:' . $col . ';">' . $wr . '%</b></span>'
                . '<a class="tag" href="/versus.php?a=' . (int)$pa['id'] . '&b=' . (int)$x['p']['id'] . '" title="открыть дуэль">⚔️</a>'
                . '</div>';
        }
        echo '</div>';
    };

    echo '<div class="tables-grid" style="grid-template-columns:repeat(auto-fit,minmax(280px,1fr));">';
    $renderList('🤝 Соратники', 'с кем чаще всего побеждаете, играя в одной команде', array_values($mates), 'mate');
    $renderList('😈 Немезиды', 'кто чаще всего берёт верх, когда вы по разные стороны', array_values($nemeses), 'nemesis');
    $renderList('🎯 Жертвы', 'против кого вы выигрываете чаще всего', array_values($preys), 'prey');
    echo '</div>';
}

if (!$pa && !$pb) {
    echo '<p style="color:var(--tx2);">Выберите одного игрока — увидите его соратников, немезид и жертв. Выберите двух — получите полную дуэль: счёт очных встреч и историю совместных игр.</p>';
}
page_foot();
