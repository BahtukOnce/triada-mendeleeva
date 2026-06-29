<?php
require dirname(__DIR__, 2) . '/inc/bootstrap.php';
$u = require_judge();

// Игра турнира + турнир (для прав и рассадки)
$gid = (int)($_GET['game'] ?? 0);
$gq = db()->prepare("SELECT g.*, t.id AS t_id, t.title AS t_title, t.main_judge_player_id, t.table_judges
    FROM games g JOIN tournaments t ON t.id = g.tournament_id
    WHERE g.id = ? AND g.context = 'tournament'");
$gq->execute([$gid]);
$g = $gq->fetch();
if (!$g) {
    page_head('Ведение игры', '');
    empty_state('Игра не найдена', 'Сгенерируй игры турнира в админке.');
    page_foot();
    exit;
}
$tid = (int)$g['t_id'];

// Право: админ/владелец, главный судья турнира или судья этого стола
$isAdmin = role_level($u['role']) >= 3;
$mp = db()->prepare('SELECT id FROM players WHERE user_id = ? LIMIT 1');
$mp->execute([(int)$u['id']]);
$myPid = (int)($mp->fetchColumn() ?: 0);
$tableJudges = [];
if (!empty($g['table_judges'])) {
    $dec = json_decode((string)$g['table_judges'], true);
    if (is_array($dec)) {
        $tableJudges = array_map('intval', $dec);
    }
}
$myTableJudge = $myPid && (int)($tableJudges[(int)$g['table_no'] - 1] ?? 0) === $myPid;
$canEdit = $isAdmin || ($myPid && (int)$g['main_judge_player_id'] === $myPid) || $myTableJudge;
if (!$canEdit) {
    flash_set('err', 'Вести игру может админ или судья турнира.');
    redirect('/tournament.php?id=' . $tid);
}

// ── Сохранение хронологии → проставляем выбывание/ПУ/ЛХ, дальше роли вносятся в протоколе ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['form'] ?? '') === 'save_chrono') {
    csrf_check();
    $chronoRaw = (string)($_POST['chronology'] ?? '');
    $chrono = json_decode($chronoRaw, true);
    if (!is_array($chrono)) {
        flash_set('err', 'Не удалось разобрать хронологию.');
        redirect('/admin/tournament_live.php?game=' . $gid);
    }
    $firstKill = (int)($_POST['first_kill'] ?? 0) ?: null;
    $lhSeat = (int)($_POST['lh_seat'] ?? 0) ?: null;
    $bm = [];
    foreach (['bm1', 'bm2', 'bm3'] as $k) {
        $v = (int)($_POST[$k] ?? 0);
        $bm[] = ($v >= 1 && $v <= 10) ? $v : null;
    }
    // порядок выбывания: out_order[seat] = номер
    $outRaw = (array)($_POST['out_order'] ?? []);
    $pdo = db();
    $pdo->beginTransaction();
    $pdo->prepare("UPDATE games SET chronology=?, first_killed_seat=?, lh_seat=?, bm_seat1=?, bm_seat2=?, bm_seat3=?
        WHERE id=? AND context='tournament'")
        ->execute([$chronoRaw, $firstKill, $lhSeat, $bm[0], $bm[1], $bm[2], $gid]);
    // сбросить прежний порядок и проставить заново
    $pdo->prepare('UPDATE game_seats SET out_order=NULL WHERE game_id=?')->execute([$gid]);
    $us = $pdo->prepare('UPDATE game_seats SET out_order=? WHERE game_id=? AND seat=?');
    foreach ($outRaw as $seat => $ord) {
        $seat = (int)$seat;
        $ord = (int)$ord;
        if ($seat >= 1 && $seat <= 10 && $ord >= 1) {
            $us->execute([$ord, $gid, $seat]);
        }
    }
    $pdo->commit();
    log_action((int)$u['id'], 'tournament_chrono_save', ['game_id' => $gid]);
    flash_set('ok', 'Хронология сохранена. Теперь проставь роли и итоги в протоколе.');
    redirect('/admin/tournament_protocol.php?game=' . $gid);
}

$seatsSt = db()->prepare('SELECT gs.seat, gs.player_id, p.nickname FROM game_seats gs
    JOIN players p ON p.id = gs.player_id WHERE gs.game_id = ? ORDER BY gs.seat');
$seatsSt->execute([$gid]);
$players = [];
foreach ($seatsSt->fetchAll() as $r) {
    $players[] = ['s' => (int)$r['seat'], 'n' => (string)$r['nickname']];
}
$existing = $g['chronology'] ?? '';

page_head('Ведение игры — ' . $g['t_title'], '');
?>
<p><a href="/tournament.php?id=<?= $tid ?>">← к турниру</a>
   &nbsp;·&nbsp; <a href="/admin/tournament_protocol.php?game=<?= $gid ?>">сразу к протоколу (без хронологии)</a></p>
<h1>Ведение игры · стол <?= (int)$g['table_no'] ?> · игра <?= (int)$g['game_no'] ?></h1>
<p style="color:var(--tx2);margin-top:-6px;">Веди игру по кругам — выбывшие, ничьи/подъёмы и ночные отстрелы. В конце сохрани: порядок выбывания, ПУ и ЛХ автоматически попадут в протокол, там проставишь роли и баллы.</p>

<div class="card">
  <div id="lv-board"></div>
  <div id="lv-status" style="margin:14px 0 10px;font-weight:600;font-size:16px;"></div>
  <div id="lv-controls"></div>
  <div id="lv-log" style="margin-top:16px;font-size:13px;color:var(--tx2);"></div>
  <div style="margin-top:16px;display:flex;gap:10px;flex-wrap:wrap;">
    <button type="button" class="btn" id="lv-finish">✓ Завершить и сохранить</button>
    <button type="button" class="btn btn-ghost" id="lv-undo">↶ Отменить последнее</button>
    <button type="button" class="btn btn-ghost" id="lv-reset" style="color:var(--ac);">Начать заново</button>
  </div>
</div>

<form method="post" action="/admin/tournament_live.php?game=<?= $gid ?>" id="lv-form" style="display:none;">
  <?= csrf_field() ?>
  <input type="hidden" name="form" value="save_chrono">
  <input type="hidden" name="chronology" id="lv-chrono">
  <input type="hidden" name="first_kill" id="lv-firstkill">
  <input type="hidden" name="lh_seat" id="lv-lhseat">
  <input type="hidden" name="bm1" id="lv-bm1"><input type="hidden" name="bm2" id="lv-bm2"><input type="hidden" name="bm3" id="lv-bm3">
  <div id="lv-outorder"></div>
</form>

<script>
(function () {
  var PL = <?= json_encode($players, JSON_UNESCAPED_UNICODE) ?>;
  var SEATS = PL.map(function (p) { return p.s; });
  var nickOf = {}; PL.forEach(function (p) { nickOf[p.s] = p.n; });

  var S = null;
  function fresh() {
    return { phase: 'vote', round: 0, alive: {}, elim: [], firstKill: null,
             lhSeat: null, bm: [], lhAssigned: false, log: [], tie: null };
  }
  function init() {
    S = fresh();
    SEATS.forEach(function (s) { S.alive[s] = true; });
    render();
  }
  function aliveSeats() { return SEATS.filter(function (s) { return S.alive[s]; }); }
  function outIndex(s) { for (var i = 0; i < S.elim.length; i++) if (S.elim[i].seat === s) return i + 1; return 0; }

  function eliminate(seat, type) {
    S.alive[seat] = false;
    S.elim.push({ seat: seat, type: type, round: S.round });
  }

  // ЛХ: даётся первому выбывшему — одиночному заголосованному на нулевом круге,
  // иначе первоубиенному ночью. Спрашиваем 3 номера.
  function askLh(seat, cb) {
    var html = '<div style="background:var(--sf2);padding:12px;border-radius:9px;">'
      + '<div style="font-weight:600;margin-bottom:8px;">Лучший ход игрока «' + esc(nickOf[seat]) + '» (место ' + seat + ') — назови 3 номера:</div>'
      + '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">'
      + sel('lh-a') + sel('lh-b') + sel('lh-c')
      + '<button type="button" class="btn" id="lh-ok">ОК</button>'
      + '<button type="button" class="btn btn-ghost" id="lh-skip">Без ЛХ</button>'
      + '</div></div>';
    function sel(id) {
      var o = '<option value="0">—</option>';
      SEATS.forEach(function (s) { o += '<option value="' + s + '">' + s + ' · ' + esc(nickOf[s]) + '</option>'; });
      return '<select id="' + id + '" style="background:var(--bg);color:var(--tx);border:1px solid var(--bd);border-radius:7px;padding:6px 8px;">' + o + '</select>';
    }
    ctl(html);
    document.getElementById('lh-ok').onclick = function () {
      var a = +document.getElementById('lh-a').value, b = +document.getElementById('lh-b').value, c = +document.getElementById('lh-c').value;
      S.lhSeat = seat; S.bm = [a || null, b || null, c || null]; S.lhAssigned = true;
      S.log.push('ЛХ: место ' + seat + ' (' + nickOf[seat] + ') → ' + [a, b, c].filter(Boolean).join(', '));
      cb();
    };
    document.getElementById('lh-skip').onclick = function () { S.lhAssigned = true; cb(); };
  }

  function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]; }); }
  function board() {
    var h = '<div style="display:flex;flex-wrap:wrap;gap:7px;">';
    SEATS.forEach(function (s) {
      var dead = !S.alive[s], oi = outIndex(s);
      h += '<div style="flex:1 1 90px;min-width:90px;padding:7px 9px;border-radius:9px;border:1px solid var(--bd);'
        + (dead ? 'opacity:.45;background:var(--sf2);' : 'background:var(--sf);') + '">'
        + '<div style="font-size:12px;color:var(--tx3);">место ' + s + (oi ? ' · вышел #' + oi : '') + '</div>'
        + '<div style="font-weight:600;' + (dead ? 'text-decoration:line-through;' : '') + '">' + esc(nickOf[s]) + '</div></div>';
    });
    return h + '</div>';
  }
  function ctl(html) { document.getElementById('lv-controls').innerHTML = html; }
  function render() {
    document.getElementById('lv-board').innerHTML = board();
    document.getElementById('lv-log').innerHTML = S.log.length ? ('<b>Хронология:</b><br>' + S.log.map(esc).join('<br>')) : '';
    var st = document.getElementById('lv-status');
    if (S.phase === 'vote') st.textContent = (S.round === 0 ? 'Нулевой круг' : 'Круг ' + S.round) + ' — голосование';
    else if (S.phase === 'night') st.textContent = 'Ночь ' + (S.round + 1) + ' — отстрел';
    else st.textContent = 'Игра окончена';
    if (S.phase === 'vote') renderVote();
    else if (S.phase === 'night') renderNight();
    else ctl('<p style="color:var(--ok);">Готово — жми «Завершить и сохранить».</p>');
  }

  function aliveSel(id) {
    var o = '<option value="0">— место —</option>';
    aliveSeats().forEach(function (s) { o += '<option value="' + s + '">' + s + ' · ' + esc(nickOf[s]) + '</option>'; });
    return '<select id="' + id + '" style="background:var(--bg);color:var(--tx);border:1px solid var(--bd);border-radius:7px;padding:6px 9px;">' + o + '</select>';
  }

  function renderVote() {
    var h = '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">'
      + '<span>Выбыл один: </span>' + aliveSel('v-one') + '<button type="button" class="btn" id="v-one-ok">Подтвердить</button>'
      + ' <button type="button" class="btn btn-ghost" id="v-tie">Ничья…</button>'
      + ' <button type="button" class="btn btn-ghost" id="v-none">Никто не выбыл → ночь</button>'
      + '</div>';
    ctl(h);
    document.getElementById('v-one-ok').onclick = function () {
      var s = +document.getElementById('v-one').value; if (!s) return;
      var wasFirst = S.elim.length === 0;
      eliminate(s, 'vote');
      S.log.push((S.round === 0 ? 'Круг 0' : 'Круг ' + S.round) + ': голосованием вышел ' + s + ' (' + nickOf[s] + ')');
      // ЛХ — только одиночному заголосованному на нулевом круге
      if (S.round === 0 && wasFirst && !S.lhAssigned) { askLh(s, function () { toNight(); }); return; }
      toNight();
    };
    document.getElementById('v-tie').onclick = renderTie;
    document.getElementById('v-none').onclick = function () {
      S.log.push((S.round === 0 ? 'Круг 0' : 'Круг ' + S.round) + ': никто не вышел');
      toNight();
    };
  }

  function renderTie() {
    var chips = aliveSeats().map(function (s) {
      return '<label style="display:inline-flex;align-items:center;gap:5px;padding:4px 9px;border:1px solid var(--bd);border-radius:7px;cursor:pointer;">'
        + '<input type="checkbox" class="tie-c" value="' + s + '"> ' + s + ' · ' + esc(nickOf[s]) + '</label>';
    }).join(' ');
    ctl('<div><div style="margin-bottom:7px;">Кто в ничьей (отметь): дано доп. время и переголосование.</div>'
      + '<div style="display:flex;flex-wrap:wrap;gap:6px;">' + chips + '</div>'
      + '<div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;">'
      + '<button type="button" class="btn" id="tie-one">После переголоса вышел один…</button>'
      + '<button type="button" class="btn btn-ghost" id="tie-revote">Снова ничья (сузить круг)</button>'
      + '<button type="button" class="btn btn-ghost" id="tie-raise">Снова ничья → подъём</button>'
      + '</div></div>');
    function tied() { return [].map.call(document.querySelectorAll('.tie-c:checked'), function (c) { return +c.value; }); }
    document.getElementById('tie-one').onclick = function () {
      var t = tied(); if (!t.length) { alert('Отметь, кто был в ничьей'); return; }
      var pick = prompt('Кто вышел после переголосования? Введи номер места из: ' + t.join(', '));
      var s = parseInt(pick, 10);
      if (t.indexOf(s) < 0) { alert('Это место не в ничьей'); return; }
      var wasFirst = S.elim.length === 0;
      eliminate(s, 'vote');
      S.log.push('Круг ' + S.round + ': ничья (' + t.join(',') + '), переголос → вышел ' + s + ' (' + nickOf[s] + ')');
      if (S.round === 0 && wasFirst && !S.lhAssigned) { askLh(s, function () { toNight(); }); return; }
      toNight();
    };
    document.getElementById('tie-revote').onclick = function () {
      // просто остаёмся в выборе ничьей — судья отметит меньший круг и снова выберет исход
      alert('Отметь меньший круг (после сужения) и снова выбери исход.');
    };
    document.getElementById('tie-raise').onclick = function () {
      var t = tied(); if (!t.length) { alert('Отметь, кто был в ничьей'); return; }
      ctl('<div>Вопрос о подъёме между: <b>' + t.join(', ') + '</b> (нужно большинство).'
        + '<div style="margin-top:10px;display:flex;gap:8px;">'
        + '<button type="button" class="btn" id="raise-yes">Подняли — все выходят</button>'
        + '<button type="button" class="btn btn-ghost" id="raise-no">Не подняли — никто</button></div></div>');
      document.getElementById('raise-yes').onclick = function () {
        t.forEach(function (s) { eliminate(s, 'vote'); });
        S.log.push('Круг ' + S.round + ': ничья (' + t.join(',') + ') → подъём: вышли все (' + t.join(',') + ')');
        toNight();
      };
      document.getElementById('raise-no').onclick = function () {
        S.log.push('Круг ' + S.round + ': ничья (' + t.join(',') + ') → не подняли, никто не вышел');
        toNight();
      };
    };
  }

  function toNight() {
    if (aliveSeats().length <= 2) { S.phase = 'done'; render(); return; }
    S.phase = 'night'; render();
  }
  function renderNight() {
    ctl('<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">'
      + '<span>Убит ночью: </span>' + aliveSel('n-one')
      + '<button type="button" class="btn" id="n-ok">Подтвердить</button>'
      + ' <button type="button" class="btn btn-ghost" id="n-miss">Промах (нет убитого)</button></div>');
    document.getElementById('n-ok').onclick = function () {
      var s = +document.getElementById('n-one').value; if (!s) return;
      var firstNight = S.firstKill === null;
      if (firstNight) S.firstKill = s; // ПУ — первый ночной отстрел
      eliminate(s, 'night');
      S.log.push('Ночь ' + (S.round + 1) + ': убит ' + s + ' (' + nickOf[s] + ')' + (firstNight ? ' — ПУ' : ''));
      // ЛХ первоубиенному, если ещё не отдан (на нулевом круге не было одиночного голосования)
      if (firstNight && !S.lhAssigned) { askLh(s, nextRound); return; }
      nextRound();
    };
    document.getElementById('n-miss').onclick = function () {
      S.log.push('Ночь ' + (S.round + 1) + ': промах');
      nextRound();
    };
  }
  function nextRound() {
    S.round++;
    if (aliveSeats().length <= 2) { S.phase = 'done'; } else { S.phase = 'vote'; }
    render();
  }

  document.getElementById('lv-undo').onclick = function () {
    if (!S.elim.length && !S.log.length) return;
    var last = S.elim.pop();
    if (last) { S.alive[last.seat] = true; if (S.firstKill === last.seat) S.firstKill = null; }
    S.log.pop();
    if (S.phase === 'done') S.phase = 'vote';
    render();
  };
  document.getElementById('lv-reset').onclick = function () { if (confirm('Начать ведение заново?')) init(); };

  document.getElementById('lv-finish').onclick = function () {
    document.getElementById('lv-chrono').value = JSON.stringify({ elim: S.elim, log: S.log, firstKill: S.firstKill, lhSeat: S.lhSeat, bm: S.bm });
    document.getElementById('lv-firstkill').value = S.firstKill || 0;
    document.getElementById('lv-lhseat').value = S.lhSeat || 0;
    document.getElementById('lv-bm1').value = (S.bm[0] || 0);
    document.getElementById('lv-bm2').value = (S.bm[1] || 0);
    document.getElementById('lv-bm3').value = (S.bm[2] || 0);
    var oo = '';
    S.elim.forEach(function (e, i) { oo += '<input type="hidden" name="out_order[' + e.seat + ']" value="' + (i + 1) + '">'; });
    document.getElementById('lv-outorder').innerHTML = oo;
    document.getElementById('lv-form').submit();
  };

  init();
})();
</script>
<?php page_foot(); ?>
