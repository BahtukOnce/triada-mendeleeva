<?php
require dirname(__DIR__) . '/inc/bootstrap.php';

// «Твой год в мафии» — персональные итоги сезона (в стиле Wrapped).
// Свои итоги — залогиненному с привязанным ником; админ может смотреть чужие (?id=).
$u = require_login();
$player = current_player();
$pid = $player ? (int)$player['id'] : 0;
if (isset($_GET['id']) && role_level($u['role']) >= 3) {
    $pid = (int)$_GET['id'];
    $st = db()->prepare('SELECT * FROM players WHERE id = ?');
    $st->execute([$pid]);
    $player = $st->fetch() ?: null;
}
if (!$player) {
    page_head('Итоги сезона', '');
    empty_state('Ник ещё не привязан', 'Чтобы увидеть свои итоги сезона, привяжите игровой ник в личном кабинете.');
    echo '<p style="text-align:center;"><a class="btn" href="/cabinet.php">В личный кабинет</a></p>';
    page_foot();
    exit;
}

// Сезон: 1 сентября — 31 августа. По умолчанию — текущий.
$nowY = (int)date('Y');
$startY = (int)date('n') >= 9 ? $nowY : $nowY - 1;
if (isset($_GET['season'])) {
    $startY = max(2020, min($startY, (int)$_GET['season']));
}
$from = $startY . '-09-01';
$to = ($startY + 1) . '-08-31';
$seasonLabel = 'Сезон ' . $startY . '/' . substr((string)($startY + 1), 2);

// ── Игры сезона ──
$q = db()->prepare("SELECT gs.seat, gs.role, gs.plus, g.id AS gid, g.winner, g.day_id, g.tournament_id,
        g.first_killed_seat, g.vote0_seat, g.bm_seat1, g.bm_seat2, g.bm_seat3,
        g.vote0_bm1, g.vote0_bm2, g.vote0_bm3,
        COALESCE(d.date, t.date_from) AS gdate, COALESCE(d.title, t.title) AS ev_title
    FROM game_seats gs
    JOIN games g ON g.id = gs.game_id
    LEFT JOIN game_days d ON d.id = g.day_id
    LEFT JOIN tournaments t ON t.id = g.tournament_id
    WHERE gs.player_id = ? AND g.status = 'finished'
      AND COALESCE(d.date, t.date_from) BETWEEN ? AND ?
    ORDER BY g.id");
$q->execute([$pid, $from, $to]);
$rows = $q->fetchAll();

page_head('Итоги сезона — ' . $player['nickname'], '');

if (!$rows) {
    empty_state('В этом сезоне игр пока нет', 'Сыграй хотя бы одну — и здесь появится твоя история сезона.');
    echo '<p style="text-align:center;"><a class="btn" href="/days.php">К игровым вечерам</a></p>';
    page_foot();
    exit;
}

// ── Подсчёты ──
$roleRu = ['civ' => 'Мирный', 'sheriff' => 'Шериф', 'maf' => 'Мафия', 'don' => 'Дон'];
$games = count($rows);
$wins = 0;
$roleG = [];
$roleW = [];
$puCnt = 0;
$dopSum = 0.0;
$evenings = [];
$tourns = [];
foreach ($rows as $r) {
    $isRed = in_array($r['role'], ['civ', 'sheriff'], true);
    $won = ($r['winner'] === 'red' && $isRed) || ($r['winner'] === 'black' && !$isRed);
    $wins += $won ? 1 : 0;
    $roleG[$r['role']] = ($roleG[$r['role']] ?? 0) + 1;
    if ($won) {
        $roleW[$r['role']] = ($roleW[$r['role']] ?? 0) + 1;
    }
    if ((int)$r['first_killed_seat'] === (int)$r['seat']) {
        $puCnt++;
    }
    $dopSum += (float)$r['plus'];
    if ($r['day_id']) {
        $evenings[(int)$r['day_id']] = 1;
    }
    if ($r['tournament_id']) {
        $tourns[(int)$r['tournament_id']] = 1;
    }
}
$wr = $games ? (int)round($wins / $games * 100) : 0;
arsort($roleG);
$favRole = array_key_first($roleG);
$favRoleWr = ($roleG[$favRole] ?? 0) > 0 ? (int)round(($roleW[$favRole] ?? 0) / $roleG[$favRole] * 100) : 0;
$hours = (int)round($games * 40 / 60); // ~40 минут на игру

// Точность ЛХ за сезон
$lhMade = 0;
$lhHits = 0;
$lhGids = [];
foreach ($rows as $r) {
    $isPuEvt = (int)$r['first_killed_seat'] === (int)$r['seat'];
    $isV0Evt = (int)$r['vote0_seat'] === (int)$r['seat'];
    if ($isPuEvt || $isV0Evt) {
        $lhGids[(int)$r['gid']] = 1;
    }
}
if ($lhGids) {
    $in = implode(',', array_fill(0, count($lhGids), '?'));
    $rq = db()->prepare("SELECT game_id, seat, role FROM game_seats WHERE game_id IN ($in)");
    $rq->execute(array_keys($lhGids));
    $rolesByGame = [];
    foreach ($rq->fetchAll() as $rr) {
        $rolesByGame[(int)$rr['game_id']][(int)$rr['seat']] = $rr['role'];
    }
    foreach ($rows as $r) {
        $isPuEvt = (int)$r['first_killed_seat'] === (int)$r['seat'];
        $isV0Evt = (int)$r['vote0_seat'] === (int)$r['seat'];
        if (!$isPuEvt && !$isV0Evt) {
            continue;
        }
        [$s1, $s2, $s3] = $isPuEvt
            ? [(int)$r['bm_seat1'], (int)$r['bm_seat2'], (int)$r['bm_seat3']]
            : [(int)$r['vote0_bm1'], (int)$r['vote0_bm2'], (int)$r['vote0_bm3']];
        $given = array_values(array_unique(array_filter([$s1, $s2, $s3], fn($n) => $n >= 1 && $n <= 10)));
        if (!$given) {
            continue;
        }
        $lhMade++;
        foreach ($given as $sn) {
            if (in_array($rolesByGame[(int)$r['gid']][$sn] ?? '', ['maf', 'don'], true)) {
                $lhHits++;
            }
        }
    }
}

// ELO за сезон: старт → пик → сейчас, лучший вечер по дельте
$eloStart = null;
$eloPeak = null;
$eloEnd = null;
$eloNet = 0.0;
$bestDay = null; // ['date'=>..., 'delta'=>...]
try {
    $eq = db()->prepare('SELECT gdate, elo_after, delta FROM elo_history
        WHERE player_id = ? AND gdate BETWEEN ? AND ? ORDER BY id');
    $eq->execute([$pid, $from, $to]);
    $eh = $eq->fetchAll();
    if ($eh) {
        $eloStart = (float)$eh[0]['elo_after'] - (float)$eh[0]['delta'];
        $eloEnd = (float)$eh[count($eh) - 1]['elo_after'];
        $byDate = [];
        foreach ($eh as $e) {
            $eloPeak = max($eloPeak ?? 0, (float)$e['elo_after']);
            $eloNet += (float)$e['delta'];
            $byDate[$e['gdate']] = ($byDate[$e['gdate']] ?? 0) + (float)$e['delta'];
        }
        arsort($byDate);
        $bd = array_key_first($byDate);
        if ($bd !== null && $byDate[$bd] > 0) {
            $bestDay = ['date' => $bd, 'delta' => $byDate[$bd]];
        }
    }
} catch (Throwable $e) {
}

// Напарник и немезида сезона
$mate = null;    // с кем чаще всего выигрывал в одном цвете
$nemesis = null; // кому чаще всего проигрывал (он в другом цвете)
try {
    $gids = array_column($rows, 'gid');
    $in = implode(',', array_fill(0, count($gids), '?'));
    $sq = db()->prepare("SELECT gs.game_id, gs.player_id, gs.role, p.nickname
        FROM game_seats gs JOIN players p ON p.id = gs.player_id WHERE gs.game_id IN ($in)");
    $sq->execute($gids);
    $others = [];
    foreach ($sq->fetchAll() as $s) {
        $others[(int)$s['game_id']][] = $s;
    }
    $mateW = [];
    $nemL = [];
    $nickBy = [];
    foreach ($rows as $r) {
        $gid = (int)$r['gid'];
        $meRed = in_array($r['role'], ['civ', 'sheriff'], true);
        $won = ($r['winner'] === 'red' && $meRed) || ($r['winner'] === 'black' && !$meRed);
        foreach ($others[$gid] ?? [] as $o) {
            $oid = (int)$o['player_id'];
            if ($oid === $pid) {
                continue;
            }
            $oRed = in_array($o['role'], ['civ', 'sheriff'], true);
            $nickBy[$oid] = (string)$o['nickname'];
            if ($oRed === $meRed && $won) {
                $mateW[$oid] = ($mateW[$oid] ?? 0) + 1;
            } elseif ($oRed !== $meRed && !$won && $r['winner'] !== 'draw') {
                $nemL[$oid] = ($nemL[$oid] ?? 0) + 1;
            }
        }
    }
    arsort($mateW);
    arsort($nemL);
    $mk = array_key_first($mateW);
    if ($mk !== null && $mateW[$mk] >= 3) {
        $mate = ['nick' => $nickBy[$mk], 'id' => $mk, 'n' => $mateW[$mk]];
    }
    $nk = array_key_first($nemL);
    if ($nk !== null && $nemL[$nk] >= 3) {
        $nemesis = ['nick' => $nickBy[$nk], 'id' => $nk, 'n' => $nemL[$nk]];
    }
} catch (Throwable $e) {
}

// «Топ X% клуба» (общий рейтинг)
$pctile = null;
try {
    $mainId = (int)db()->query('SELECT id FROM ratings WHERE is_main = 1 LIMIT 1')->fetchColumn();
    $cs = db()->prepare('SELECT club_score FROM rating_cache WHERE rating_id = ? AND player_id = ?');
    $cs->execute([$mainId, $pid]);
    $myScore = $cs->fetchColumn();
    if ($myScore !== false && $myScore !== null) {
        $tt = db()->prepare('SELECT COUNT(*) FROM rating_cache WHERE rating_id = ? AND club_score IS NOT NULL');
        $tt->execute([$mainId]);
        $total = (int)$tt->fetchColumn();
        $bt = db()->prepare('SELECT COUNT(*) FROM rating_cache WHERE rating_id = ? AND club_score > ?');
        $bt->execute([$mainId, (float)$myScore]);
        if ($total > 0) {
            $pctile = max(1, (int)ceil(((int)$bt->fetchColumn() + 1) / $total * 100));
        }
    }
} catch (Throwable $e) {
}

$fmt0 = fn($v) => number_format((float)$v, 0, '.', ' ');
?>
<style>
.wr-wrap { margin: -10px -16px 0; }
.wr-slide { min-height: 78vh; display: flex; align-items: center; justify-content: center; padding: 30px 18px; }
.wr-inner { max-width: 620px; width: 100%; text-align: center; opacity: 0; transform: translateY(26px); transition: opacity .6s ease, transform .6s ease; }
.wr-inner.on { opacity: 1; transform: none; }
.wr-kicker { font-size: 13px; letter-spacing: 2.5px; text-transform: uppercase; color: var(--tx3); margin-bottom: 14px; }
.wr-big { font-size: clamp(56px, 14vw, 118px); font-weight: 800; line-height: 1; margin: 8px 0; }
.wr-title { font-size: clamp(22px, 5vw, 34px); font-weight: 750; line-height: 1.25; margin: 6px 0; }
.wr-sub { font-size: 15.5px; color: var(--tx2); line-height: 1.55; margin-top: 12px; }
.wr-red { color: var(--ac); } .wr-ok { color: #3fbe6e; } .wr-gold { color: #e8b830; }
.wr-grad { background: linear-gradient(100deg, #e8332a, #e8b830); -webkit-background-clip: text; background-clip: text; color: transparent; }
.wr-chip { display: inline-block; background: var(--sf); border: 1px solid var(--bd); border-radius: 12px; padding: 12px 18px; margin: 6px 4px; }
.wr-chip b { font-size: 22px; display: block; }
.wr-chip span { font-size: 12px; color: var(--tx2); }
.wr-final { background: var(--sf); border: 1px solid var(--bd); border-radius: 18px; padding: 26px 20px; }
.wr-final .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 12px; margin-top: 16px; }
.wr-final .cell b { display: block; font-size: 24px; }
.wr-final .cell span { font-size: 11.5px; color: var(--tx2); }
.wr-avatar { width: 96px; height: 96px; border-radius: 50%; object-fit: cover; border: 3px solid var(--ac); }
</style>
<div class="wr-wrap">

<div class="wr-slide"><div class="wr-inner">
  <div class="wr-kicker"><?= esc($seasonLabel) ?> · Триада Менделеева</div>
  <?php if (!empty($player['avatar']) && is_file(ROOT . '/public_html' . $player['avatar'])): ?>
    <img class="wr-avatar" src="<?= esc($player['avatar']) ?>" alt="">
  <?php endif; ?>
  <div class="wr-title">Твой год в мафии,<br><span class="wr-grad"><?= esc($player['nickname']) ?></span></div>
  <div class="wr-sub">Листай вниз — соберём твой сезон по кусочкам ↓</div>
</div></div>

<div class="wr-slide"><div class="wr-inner">
  <div class="wr-kicker">за столом</div>
  <div class="wr-big wr-red"><?= $games ?></div>
  <div class="wr-title">игр за сезон</div>
  <div class="wr-sub"><?= count($evenings) ?> вечеров<?= count($tourns) ? ' · ' . count($tourns) . ' турнир(а/ов)' : '' ?> · это примерно <b><?= $hours ?> часов</b> взглядов, блефа и «я мирный, честно»</div>
</div></div>

<div class="wr-slide"><div class="wr-inner">
  <div class="wr-kicker">победы</div>
  <div class="wr-big <?= $wr >= 50 ? 'wr-ok' : 'wr-red' ?>"><?= $wr ?>%</div>
  <div class="wr-title">винрейт сезона</div>
  <div class="wr-sub"><?= $wins ?> побед из <?= $games ?> игр<?= $dopSum > 0 ? ' · допов заработано: <b>' . number_format($dopSum, 1) . '</b>' : '' ?></div>
</div></div>

<div class="wr-slide"><div class="wr-inner">
  <div class="wr-kicker">твоя стихия</div>
  <div class="wr-title">Любимая роль — <span class="wr-grad"><?= $roleRu[$favRole] ?? $favRole ?></span></div>
  <div class="wr-sub">выпала <?= (int)$roleG[$favRole] ?> раз · винрейт за неё <b><?= $favRoleWr ?>%</b></div>
  <div style="margin-top:14px;">
    <?php foreach ($roleG as $rk => $rg): ?>
      <span class="wr-chip"><b><?= $rg ?></b><span><?= $roleRu[$rk] ?? $rk ?></span></span>
    <?php endforeach; ?>
  </div>
</div></div>

<?php if ($eloStart !== null): ?>
<div class="wr-slide"><div class="wr-inner">
  <div class="wr-kicker">путь ELO</div>
  <div class="wr-big <?= $eloNet >= 0 ? 'wr-ok' : 'wr-red' ?>"><?= ($eloNet >= 0 ? '+' : '−') . $fmt0(abs($eloNet)) ?></div>
  <div class="wr-title">ELO за сезон</div>
  <div class="wr-sub">старт <b><?= $fmt0($eloStart) ?></b> → пик <b class="wr-gold"><?= $fmt0($eloPeak) ?></b> → сейчас <b><?= $fmt0($eloEnd) ?></b></div>
  <?php if ($bestDay): ?>
    <div class="wr-sub">лучший вечер: <?= esc(date('d.m.Y', strtotime($bestDay['date']))) ?> — <b class="wr-ok">+<?= $fmt0($bestDay['delta']) ?> ELO</b> за один вечер 🔥</div>
  <?php endif; ?>
</div></div>
<?php endif; ?>

<?php if ($puCnt > 0 || $lhMade > 0): ?>
<div class="wr-slide"><div class="wr-inner">
  <div class="wr-kicker">ночные сюжеты</div>
  <div class="wr-title"><?= $puCnt > 0 ? 'Тебя отстреливали первым <b class="wr-red">' . $puCnt . '</b> раз' : 'Ни одного первого отстрела' ?></div>
  <div class="wr-sub"><?= $puCnt >= 5 ? 'мафия считает тебя опасным — это комплимент 🔪' : ($puCnt > 0 ? 'бывает — зато есть ЛХ' : 'тихая и незаметная угроза 👀') ?></div>
  <?php if ($lhMade > 0): ?>
    <div class="wr-sub">ЛХ оставлен <b><?= $lhMade ?></b> раз · в среднем <b class="wr-gold"><?= number_format($lhHits / $lhMade, 1) ?></b> чёрных из 3</div>
  <?php endif; ?>
</div></div>
<?php endif; ?>

<?php if ($mate || $nemesis): ?>
<div class="wr-slide"><div class="wr-inner">
  <div class="wr-kicker">люди сезона</div>
  <?php if ($mate): ?>
    <div class="wr-title">🤝 Напарник года — <a class="wr-grad" style="text-decoration:none;" href="/player.php?id=<?= (int)$mate['id'] ?>"><?= esc($mate['nick']) ?></a></div>
    <div class="wr-sub"><?= (int)$mate['n'] ?> совместных побед в одном цвете</div>
  <?php endif; ?>
  <?php if ($nemesis): ?>
    <div class="wr-title" style="margin-top:18px;">⚔ Немезида — <a class="wr-red" style="text-decoration:none;" href="/player.php?id=<?= (int)$nemesis['id'] ?>"><?= esc($nemesis['nick']) ?></a></div>
    <div class="wr-sub">обыграл(а) тебя <?= (int)$nemesis['n'] ?> раз — пора отомстить: <a href="/versus.php?a=<?= $pid ?>&b=<?= (int)$nemesis['id'] ?>">открыть дуэль →</a></div>
  <?php endif; ?>
</div></div>
<?php endif; ?>

<?php if ($pctile !== null): ?>
<div class="wr-slide"><div class="wr-inner">
  <div class="wr-kicker">место в клубе</div>
  <div class="wr-big wr-grad">топ <?= $pctile ?>%</div>
  <div class="wr-title">клуба по клубному счёту</div>
  <div class="wr-sub">весь рейтинг — на <a href="/rating.php">странице рейтинга</a></div>
</div></div>
<?php endif; ?>

<div class="wr-slide"><div class="wr-inner">
  <div class="wr-final">
    <div class="wr-kicker"><?= esc($seasonLabel) ?> · итоги</div>
    <div class="wr-title wr-grad"><?= esc($player['nickname']) ?></div>
    <div class="grid">
      <div class="cell"><b><?= $games ?></b><span>игр</span></div>
      <div class="cell"><b><?= $wr ?>%</b><span>винрейт</span></div>
      <div class="cell"><b><?= $roleRu[$favRole] ?? '—' ?></b><span>любимая роль</span></div>
      <?php if ($eloStart !== null): ?><div class="cell"><b><?= ($eloNet >= 0 ? '+' : '−') . $fmt0(abs($eloNet)) ?></b><span>ELO за сезон</span></div><?php endif; ?>
      <div class="cell"><b><?= $puCnt ?></b><span>раз ПУ</span></div>
      <?php if ($pctile !== null): ?><div class="cell"><b>топ <?= $pctile ?>%</b><span>клуба</span></div><?php endif; ?>
    </div>
    <div class="wr-sub" style="margin-top:18px;">Сделай скриншот и кинь в чат клуба 😉<br>triada-mendeleeva.ru</div>
  </div>
  <div class="wr-sub" style="margin-top:16px;"><a class="btn btn-ghost" href="/my_games.php">Мои игры</a> <a class="btn btn-ghost" href="/player.php?id=<?= $pid ?>">Мой профиль</a></div>
</div></div>

</div>
<script>
(function () {
  var els = document.querySelectorAll('.wr-inner');
  if (!('IntersectionObserver' in window)) { els.forEach(function (e) { e.classList.add('on'); }); return; }
  var io = new IntersectionObserver(function (entries) {
    entries.forEach(function (en) { if (en.isIntersecting) { en.target.classList.add('on'); io.unobserve(en.target); } });
  }, { threshold: 0.25 });
  els.forEach(function (e) { io.observe(e); });
})();
</script>
<?php page_foot(); ?>
