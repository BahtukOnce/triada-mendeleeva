<?php
require dirname(__DIR__) . '/inc/bootstrap.php';

$q = trim((string)($_GET['q'] ?? ''));
$list = [];

if (db_ready()) {
    $mainId = (int)db()->query('SELECT id FROM ratings WHERE is_main = 1 LIMIT 1')->fetchColumn();
    // Игры и победы — по всем зарегистрированным играм клуба (game_seats),
    // включая исторические вечера: статистика всегда актуальна по всем играм.
    $sql = "SELECT p.id, p.nickname, p.avatar, p.fav_role, p.flair, p.elo,
            agg.games, agg.wins
        FROM players p
        LEFT JOIN (
            SELECT gs.player_id,
                COUNT(*) AS games,
                SUM(CASE WHEN (g.winner = 'red' AND gs.role IN ('civ','sheriff'))
                          OR (g.winner = 'black' AND gs.role IN ('maf','don')) THEN 1 ELSE 0 END) AS wins
            FROM game_seats gs JOIN games g ON g.id = gs.game_id
            WHERE g.status = 'finished'
            GROUP BY gs.player_id
        ) agg ON agg.player_id = p.id
        WHERE p.banned_at IS NULL";
    $params = [];
    if ($q !== '') {
        $sql .= ' AND p.nickname LIKE ?';
        $params[] = '%' . $q . '%';
    }
    $sql .= ' ORDER BY p.nickname LIMIT 400';
    $st = db()->prepare($sql);
    $st->execute($params);
    $list = $st->fetchAll();

    // Ранг в основном рейтинге Триады (позиция по club_score)
    $rankMap = [];
    if ($mainId) {
        $rk = db()->prepare('SELECT player_id FROM rating_cache
            WHERE rating_id = ? AND club_score IS NOT NULL ORDER BY club_score DESC');
        $rk->execute([$mainId]);
        $pos = 0;
        foreach ($rk->fetchAll(PDO::FETCH_COLUMN) as $pid) {
            $rankMap[(int)$pid] = ++$pos;
        }
    }

    // MVP вечеров (для золотого бейджа на карточке)
    $mvpMap = [];
    if ($mainId) {
        $mv = db()->prepare('SELECT player_id, mvp_evenings FROM rating_cache WHERE rating_id = ? AND mvp_evenings > 0');
        $mv->execute([$mainId]);
        foreach ($mv->fetchAll() as $r) {
            $mvpMap[(int)$r['player_id']] = (int)$r['mvp_evenings'];
        }
    }
}

page_head('Игроки', 'players');
echo '<h1>Игроки</h1>';

echo '<form method="get" action="/players.php" style="max-width:340px;margin-bottom:14px;">';
echo '<div class="field" style="margin:0;"><input type="search" id="pl-search" name="q" placeholder="Поиск по нику" value="' . esc($q) . '" autocomplete="off"></div>';
echo '</form>';

if ($list) {
    $roleLbl = ['civ' => 'Мирный', 'sheriff' => 'Шериф', 'maf' => 'Мафия', 'don' => 'Дон'];
    echo '<p id="pl-count" style="color:var(--tx2);font-size:13px;margin:0 0 10px;">Всего игроков: ' . count($list) . '</p>';
    echo '<div class="player-grid">';
    foreach ($list as $p) {
        $g = $p['games'] !== null ? (int)$p['games'] : 0;
        $w = (int)$p['wins'];
        $wr = $g ? round($w / $g * 100) : null;
        $elo = (int)round((float)($p['elo'] ?? 1000));
        echo '<a class="player-card" data-nick="' . esc(mb_strtolower((string)$p['nickname'])) . '" href="/player.php?id=' . (int)$p['id'] . '">';
        $favHtml = $p['fav_role']
            ? '<span class="fav-chip"><span class="fdot" style="background:' . role_color($p['fav_role'] === 'sheriff' ? 'sheriff' : $p['fav_role']) . ';"></span>' . esc($roleLbl[$p['fav_role']]) . '</span>'
            : '<span style="color:var(--tx3);font-size:11.5px;">роль не выбрана</span>';
        $rank = $rankMap[(int)$p['id']] ?? null;
        $rankHtml = $rank
            ? '<span class="pc-rank' . ($rank <= 3 ? ' top' . $rank : '') . '" title="место в рейтинге Триады">#' . $rank . '</span>'
            : '';
        $mvp = $mvpMap[(int)$p['id']] ?? 0;
        $mvpChip = $mvp ? ' <span title="MVP вечеров" style="color:#ffc400;font-weight:600;">🥇' . $mvp . '</span>' : '';
        echo '<div class="pc-top">' . avatar_html(['nickname' => $p['nickname'], 'avatar' => $p['avatar']], 42)
            . '<div class="pc-name">' . player_label($p)
            . '<div class="pc-fav">' . $favHtml . $mvpChip . '</div></div>'
            . $rankHtml . '</div>';
        echo '<div class="pc-stats">'
            . '<div><b>' . $g . '</b><span>игр</span></div>'
            . '<div><b>' . ($wr !== null ? $wr . '%' : '—') . '</b><span>винрейт</span></div>'
            . '<div><b>' . $elo . '</b><span>ELO</span></div>'
            . '</div></a>';
    }
    echo '</div>';
    echo '<div id="pl-empty" style="display:none;color:var(--tx2);padding:18px 2px;">Никого не нашлось — попробуйте другой запрос.</div>';
} elseif ($q !== '') {
    empty_state('Никого не нашлось', 'Попробуйте изменить запрос.');
} else {
    empty_state('Список игроков пока пуст', 'Появится после переноса истории.');
}
?>
<script>
(function () {
  var input = document.getElementById('pl-search');
  if (!input) return;
  var cards = [].slice.call(document.querySelectorAll('.player-card[data-nick]'));
  var countEl = document.getElementById('pl-count');
  var emptyEl = document.getElementById('pl-empty');
  function apply() {
    var q = input.value.trim().toLowerCase();
    var shown = 0;
    for (var i = 0; i < cards.length; i++) {
      var ok = !q || cards[i].getAttribute('data-nick').indexOf(q) !== -1;
      cards[i].style.display = ok ? '' : 'none';
      if (ok) shown++;
    }
    if (countEl) countEl.textContent = 'Всего игроков: ' + shown;
    if (emptyEl) emptyEl.style.display = (shown === 0 && q) ? '' : 'none';
  }
  input.addEventListener('input', apply);
  var form = input.closest('form');
  if (form) form.addEventListener('submit', function (e) { e.preventDefault(); apply(); });
  apply();
})();
</script>
<?php
page_foot();
