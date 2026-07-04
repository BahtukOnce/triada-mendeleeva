<?php
require dirname(__DIR__) . '/inc/bootstrap.php';

$q = trim((string)($_GET['q'] ?? ''));
$list = [];

if (db_ready()) {
    $mainId = (int)db()->query('SELECT id FROM ratings WHERE is_main = 1 LIMIT 1')->fetchColumn();
    [$seasonStart, $seasonEnd] = current_season_bounds();
    // Игры и победы — за ТЕКУЩИЙ сезон (1 сент–31 авг), включая турниры (sagg).
    // В списке остаётся каждый, кто когда-либо играл ИЛИ зарегистрирован (agg_all — для фильтра).
    $sql = "SELECT p.id, p.nickname, p.avatar, p.fav_role, p.fav_seat, p.flair, p.elo,
            sagg.games, sagg.wins
        FROM players p
        LEFT JOIN (
            SELECT gs.player_id,
                COUNT(*) AS games,
                SUM(CASE WHEN (g.winner = 'red' AND gs.role IN ('civ','sheriff'))
                          OR (g.winner = 'black' AND gs.role IN ('maf','don')) THEN 1 ELSE 0 END) AS wins
            FROM game_seats gs JOIN games g ON g.id = gs.game_id
            LEFT JOIN game_days d ON d.id = g.day_id
            LEFT JOIN tournaments t ON t.id = g.tournament_id
            WHERE g.status = 'finished' AND g.winner IS NOT NULL
              AND COALESCE(d.date, t.date_from) BETWEEN ? AND ?
            GROUP BY gs.player_id
        ) sagg ON sagg.player_id = p.id
        LEFT JOIN (
            SELECT gs.player_id, COUNT(*) AS games
            FROM game_seats gs JOIN games g ON g.id = gs.game_id
            WHERE g.status = 'finished' AND g.winner IS NOT NULL
            GROUP BY gs.player_id
        ) agg_all ON agg_all.player_id = p.id
        WHERE p.banned_at IS NULL AND (agg_all.games IS NOT NULL OR p.user_id IS NOT NULL)";
    $params = [$seasonStart, $seasonEnd];
    if ($q !== '') {
        $sql .= ' AND p.nickname LIKE ?';
        $params[] = '%' . like_escape($q) . '%';
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
echo '<p style="color:var(--tx2);font-size:13px;margin:-6px 0 14px;">Статистика за ' . esc(current_season_bounds()[2]) . ' · вся история — в профиле игрока</p>';

echo '<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:14px;">';
echo '<form method="get" action="/players.php" style="max-width:340px;flex:1;min-width:220px;">';
echo '<div class="field" style="margin:0;"><input type="search" id="pl-search" name="q" placeholder="Поиск по нику" value="' . esc($q) . '" autocomplete="off"></div>';
echo '</form>';
echo '<a class="tag" href="/versus.php" title="Очные встречи двух игроков, соратники и немезиды">⚔️ Дуэль</a>';
echo '</div>';

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
        $casper = is_casper((string)$p['nickname']);
        $favHtml = $casper
            ? '<span style="color:var(--tx3);font-size:11.5px;">👻 призрак клуба</span>'
            : ($p['fav_role']
                ? '<span class="fav-chip"><span class="fdot" style="background:' . role_color($p['fav_role'] === 'sheriff' ? 'sheriff' : $p['fav_role']) . ';"></span>' . esc($roleLbl[$p['fav_role']]) . '</span>'
                : '<span style="color:var(--tx3);font-size:11.5px;">роль не выбрана</span>');
        $rank = $casper ? null : ($rankMap[(int)$p['id']] ?? null);
        $rankHtml = $rank
            ? '<span class="pc-rank' . ($rank <= 3 ? ' top' . $rank : '') . '" title="место в рейтинге Триады">#' . $rank . '</span>'
            : '';
        $mvp = $casper ? 0 : ($mvpMap[(int)$p['id']] ?? 0);
        $mvpChip = $mvp ? ' <span title="MVP вечеров" style="color:#ffc400;font-weight:600;">🥇' . $mvp . '</span>' : '';
        $seatChip = (!$casper && !empty($p['fav_seat']))
            ? ' <span class="fav-chip" title="любимое место за столом"><span class="fdot" style="background:var(--ac);"></span>место ' . (int)$p['fav_seat'] . '</span>'
            : '';
        echo '<div class="pc-top">' . avatar_html(['nickname' => $p['nickname'], 'avatar' => $p['avatar']], 42)
            . '<div class="pc-name">' . player_label($p)
            . '<div class="pc-fav">' . $favHtml . $seatChip . $mvpChip . '</div></div>'
            . $rankHtml . '</div>';
        if ($casper) {
            echo '<div class="pc-stats" style="grid-template-columns:1fr;">'
                . '<div><b style="font-size:18px;">👻 Бу!</b><span>у призраков нет статистики</span></div>'
                . '</div></a>';
        } else {
            echo '<div class="pc-stats">'
                . '<div><b>' . $g . '</b><span>игр</span></div>'
                . '<div><b>' . ($wr !== null ? $wr . '%' : '—') . '</b><span>винрейт</span></div>'
                . '<div><b>' . $elo . '</b><span>ELO</span></div>'
                . '</div></a>';
        }
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
