<?php
require dirname(__DIR__) . '/inc/bootstrap.php';

$aId = (int)($_GET['a'] ?? 0);
$bId = (int)($_GET['b'] ?? 0);

page_head('Очная ставка', 'records');
echo '<h1>Очная ставка</h1>';
echo '<p style="color:var(--tx2);margin-top:-6px;">Сравните двух игроков и личные встречи между ними.</p>';

if (!db_ready()) {
    empty_state('Нет данных', '');
    page_foot();
    exit;
}

$players = db()->query('SELECT id, nickname FROM players WHERE banned_at IS NULL ORDER BY nickname')->fetchAll();
$mainId = (int)db()->query('SELECT id FROM ratings WHERE is_main = 1 LIMIT 1')->fetchColumn();

// Форма выбора
$sel = function (string $name, int $cur) use ($players) {
    $h = '<select name="' . $name . '" style="background:var(--sf2);color:var(--tx);border:1px solid var(--bd);border-radius:8px;padding:10px 12px;min-width:180px;"><option value="0">— игрок —</option>';
    foreach ($players as $p) {
        $h .= '<option value="' . (int)$p['id'] . '"' . ((int)$p['id'] === $cur ? ' selected' : '') . '>' . esc($p['nickname']) . '</option>';
    }
    return $h . '</select>';
};
echo '<form method="get" action="/vs.php" class="card" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">';
echo $sel('a', $aId) . '<span style="color:var(--tx2);font-weight:700;">VS</span>' . $sel('b', $bId);
echo '<button class="btn" type="submit">Сравнить</button></form>';

$cmpData = function (int $pid) use ($mainId) {
    $st = db()->prepare('SELECT p.*, rc.games, rc.sum_total, rc.avg_total, rc.club_score, rc.pu_count, rc.lh_sum,
            rc.dop_sum, rc.minus_sum, rc.g_civ, rc.w_civ, rc.g_maf, rc.w_maf, rc.g_sher, rc.w_sher, rc.g_don, rc.w_don
        FROM players p LEFT JOIN rating_cache rc ON rc.player_id = p.id AND rc.rating_id = ? WHERE p.id = ?');
    $st->execute([$mainId, $pid]);
    return $st->fetch() ?: null;
};

if ($aId && $bId && $aId !== $bId) {
    $A = $cmpData($aId);
    $B = $cmpData($bId);
    if (!$A || !$B) {
        echo '<p style="color:var(--ac);">Игрок не найден.</p>';
        page_foot();
        exit;
    }
    $wins = fn($r) => (int)$r['w_civ'] + (int)$r['w_maf'] + (int)$r['w_sher'] + (int)$r['w_don'];
    $wr = fn($r) => (int)$r['games'] ? round($wins($r) / (int)$r['games'] * 100) : 0;

    // Шапки игроков
    echo '<div class="vs-head">';
    foreach ([$A, $B] as $P) {
        echo '<a class="vs-player" href="/player.php?id=' . (int)$P['id'] . '">'
            . avatar_html($P, 56, 'background:var(--acsf);color:var(--ac);')
            . '<div class="vs-name">' . player_label($P) . '</div>'
            . '<div class="vs-elo">' . (int)round((float)$P['elo']) . ' ELO</div></a>';
    }
    echo '</div>';

    // Сравнение показателей: чей больше — подсвечиваем
    $metrics = [
        ['ELO', (float)$A['elo'], (float)$B['elo'], 0],
        ['Игр', (int)$A['games'], (int)$B['games'], 0],
        ['Винрейт', $wr($A), $wr($B), 0, '%'],
        ['Клубный счёт', (float)$A['club_score'], (float)$B['club_score'], 2],
        ['Σ', (float)$A['sum_total'], (float)$B['sum_total'], 2],
        ['~Σ', (float)$A['avg_total'], (float)$B['avg_total'], 2],
        ['ПУ', (int)$A['pu_count'], (int)$B['pu_count'], 0],
        ['Допы', (float)$A['dop_sum'], (float)$B['dop_sum'], 1],
    ];
    echo '<div class="card"><table class="tbl vs-tbl">';
    foreach ($metrics as $m) {
        $lbl = $m[0]; $va = $m[1]; $vb = $m[2]; $dec = $m[3]; $suf = $m[4] ?? '';
        $fa = ($dec ? number_format($va, $dec) : (string)(int)$va) . $suf;
        $fb = ($dec ? number_format($vb, $dec) : (string)(int)$vb) . $suf;
        $aBetter = $va > $vb ? ' vs-win' : '';
        $bBetter = $vb > $va ? ' vs-win' : '';
        echo '<tr><td class="num vs-a' . $aBetter . '">' . $fa . '</td>'
            . '<td class="vs-mid">' . $lbl . '</td>'
            . '<td class="num vs-b' . $bBetter . '">' . $fb . '</td></tr>';
    }
    echo '</table></div>';

    // ── Личные встречи ──
    $st = db()->prepare("SELECT g.winner, sa.role AS ra, sb.role AS rb
        FROM games g
        JOIN game_seats sa ON sa.game_id = g.id AND sa.player_id = ?
        JOIN game_seats sb ON sb.game_id = g.id AND sb.player_id = ?
        WHERE g.status = 'finished'");
    $st->execute([$aId, $bId]);
    $aw = 0; $bw = 0; $dr = 0; $together = 0; $togetherWin = 0;
    $team = fn($role) => in_array($role, ['civ', 'sheriff'], true) ? 'red' : 'black';
    foreach ($st->fetchAll() as $g) {
        $ta = $team($g['ra']); $tb = $team($g['rb']);
        if ($ta === $tb) {
            $together++;
            if ($g['winner'] === $ta) {
                $togetherWin++;
            }
            continue;
        }
        if ($g['winner'] === 'draw') {
            $dr++;
        } elseif ($g['winner'] === $ta) {
            $aw++;
        } elseif ($g['winner'] === $tb) {
            $bw++;
        }
    }
    echo '<div class="card"><h2 style="margin-top:0;">Личные встречи</h2>';
    echo '<p style="color:var(--tx2);font-size:13px;margin-top:-4px;">когда играли в разных командах</p>';
    echo '<div class="vs-h2h">';
    echo '<div class="vs-h2h-side"><div class="v">' . $aw . '</div><div class="l">' . esc($A['nickname']) . '</div></div>';
    echo '<div class="vs-h2h-mid">' . $dr . '<span>ничьих</span></div>';
    echo '<div class="vs-h2h-side"><div class="v">' . $bw . '</div><div class="l">' . esc($B['nickname']) . '</div></div>';
    echo '</div>';
    echo '<p style="color:var(--tx2);font-size:13px;text-align:center;margin-bottom:0;">В одной команде сыграно вместе: <b style="color:var(--tx);">' . $together . '</b>'
        . ($together ? ' (побед ' . $togetherWin . ')' : '') . '</p>';
    echo '</div>';
}
page_foot();
