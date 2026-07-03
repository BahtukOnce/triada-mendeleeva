<?php
require dirname(__DIR__, 2) . '/inc/bootstrap.php';
$u = require_judge();

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

// ── Сохранение хронологии → выбывание/ПУ/ЛХ; роли/баллы вносятся в протоколе ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['form'] ?? '') === 'save_chrono') {
    csrf_check();
    $chronoRaw = (string)($_POST['chronology'] ?? '');
    if (strlen($chronoRaw) > 60000 || !is_array(json_decode($chronoRaw, true))) {
        flash_set('err', 'Не удалось разобрать хронологию.');
        redirect('/admin/tournament_live.php?game=' . $gid);
    }
    $seat = fn($k) => (($v = (int)($_POST[$k] ?? 0)) >= 1 && $v <= 10) ? $v : null;
    $firstKill = $seat('first_kill');
    $bm = [$seat('bm1'), $seat('bm2'), $seat('bm3')];
    $vote0 = $seat('vote0_seat');
    $v0bm = [$seat('v0bm1'), $seat('v0bm2'), $seat('v0bm3')];
    $outRaw = (array)($_POST['out_order'] ?? []);

    $pdo = db();
    $pdo->beginTransaction();
    $pdo->prepare("UPDATE games SET chronology=?, first_killed_seat=?, bm_seat1=?, bm_seat2=?, bm_seat3=?,
        vote0_seat=?, vote0_bm1=?, vote0_bm2=?, vote0_bm3=? WHERE id=? AND context='tournament'")
        ->execute([$chronoRaw, $firstKill, $bm[0], $bm[1], $bm[2], $vote0, $v0bm[0], $v0bm[1], $v0bm[2], $gid]);
    $pdo->prepare('UPDATE game_seats SET out_order=NULL WHERE game_id=?')->execute([$gid]);
    $us = $pdo->prepare('UPDATE game_seats SET out_order=? WHERE game_id=? AND seat=?');
    foreach ($outRaw as $s => $ord) {
        $s = (int)$s;
        $ord = (int)$ord;
        if ($s >= 1 && $s <= 10 && $ord >= 1 && $ord <= 10) {
            $us->execute([$ord, $gid, $s]);
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

page_head('Ведение игры — ' . $g['t_title'], '');
?>
<p><a href="/tournament.php?id=<?= $tid ?>">← к турниру</a>
   &nbsp;·&nbsp; <a href="/admin/tournament_protocol.php?game=<?= $gid ?>">сразу к протоколу (без хронологии)</a></p>
<h1>Ведение игры · стол <?= (int)$g['table_no'] ?> · игра <?= (int)$g['game_no'] ?></h1>
<p style="color:var(--tx2);margin-top:-6px;">Кликай игроков, чтобы выставлять кандидатуры и фиксировать выбывших. Голоса считаются по порядку выставления (последнему — остаток). В конце ПУ и ЛХ попадут в протокол.</p>

<style>
.lv-card{flex:1 1 96px;min-width:96px;padding:8px 10px;border-radius:10px;border:1px solid var(--bd);background:var(--sf);transition:.12s;}
.lv-card.click{cursor:pointer;}
.lv-card.click:hover{border-color:var(--ac);}
.lv-card.dead{opacity:.4;}
.lv-card.sel{border-color:var(--ac);background:var(--acsf);}
.lv-badge{display:inline-block;margin-left:6px;background:var(--ac);color:#fff;font-size:11px;font-weight:700;padding:1px 7px;border-radius:10px;}
.lv-vote{background:var(--sf2);color:var(--tx);border:1px solid var(--bd);border-radius:7px;padding:6px 9px;}
</style>

<div class="card">
  <div id="lv-board"></div>
  <div id="lv-status" style="margin:16px 0 10px;font-weight:600;font-size:16px;"></div>
  <div id="lv-controls"></div>
  <div id="lv-log" style="margin-top:16px;font-size:13px;color:var(--tx2);line-height:1.6;"></div>
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
  <input type="hidden" name="bm1" id="lv-bm1"><input type="hidden" name="bm2" id="lv-bm2"><input type="hidden" name="bm3" id="lv-bm3">
  <input type="hidden" name="vote0_seat" id="lv-vote0">
  <input type="hidden" name="v0bm1" id="lv-v0bm1"><input type="hidden" name="v0bm2" id="lv-v0bm2"><input type="hidden" name="v0bm3" id="lv-v0bm3">
  <div id="lv-outorder"></div>
</form>

<script>
(function () {
  var PL = <?= json_encode($players, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
  var SEATS = PL.map(function (p) { return p.s; });
  var nickOf = {}; PL.forEach(function (p) { nickOf[p.s] = p.n; });
  function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]; }); }

  var S;
  function fresh() {
    return { phase: 'nominate', round: 0, alive: {}, cands: [], elim: [],
             firstKill: null, puBm: [], vote0Seat: null, vote0Bm: [], log: [], tied: null, lh: null };
  }
  function init() { S = fresh(); SEATS.forEach(function (s) { S.alive[s] = true; }); render(); }
  function aliveSeats() { return SEATS.filter(function (s) { return S.alive[s]; }); }
  function outIndex(s) { for (var i = 0; i < S.elim.length; i++) if (S.elim[i].seat === s) return i + 1; return 0; }
  function ctl(h) { document.getElementById('lv-controls').innerHTML = h; }

  // ── Клик по карточке игрока (поведение зависит от фазы) ──
  document.getElementById('lv-board').addEventListener('click', function (e) {
    var card = e.target.closest('[data-seat]'); if (!card) return;
    onCard(+card.getAttribute('data-seat'));
  });
  function onCard(seat) {
    if (S.lh) {
      var pi = S.lh.picks.indexOf(seat);
      if (pi >= 0) S.lh.picks.splice(pi, 1); else if (S.lh.picks.length < 3) S.lh.picks.push(seat);
      render(); return;
    }
    if (S.phase === 'nominate') {
      if (!S.alive[seat]) return;
      var ci = S.cands.indexOf(seat);
      if (ci >= 0) S.cands.splice(ci, 1); else S.cands.push(seat);
      render(); return;
    }
    if (S.phase === 'night') {
      if (!S.alive[seat]) return;
      nightKill(seat); return;
    }
  }

  function board() {
    var h = '<div style="display:flex;flex-wrap:wrap;gap:7px;">';
    SEATS.forEach(function (s) {
      var dead = !S.alive[s], oi = outIndex(s), badge = '', sel = false;
      if (S.lh) { var pi = S.lh.picks.indexOf(s); if (pi >= 0) { badge = '<span class="lv-badge">ЛХ' + (pi + 1) + '</span>'; sel = true; } }
      else if (S.phase === 'nominate') { var ci = S.cands.indexOf(s); if (ci >= 0) { badge = '<span class="lv-badge">' + (ci + 1) + '</span>'; sel = true; } }
      var clickable = S.lh ? true : ((S.phase === 'nominate' || S.phase === 'night') && !dead);
      h += '<div data-seat="' + s + '" class="lv-card' + (clickable ? ' click' : '') + (dead && !S.lh ? ' dead' : '') + (sel ? ' sel' : '') + '">'
        + '<div style="font-size:11px;color:var(--tx3);">место ' + s + (oi ? ' · вышел #' + oi : '') + '</div>'
        + '<div style="font-weight:600;' + (dead ? 'text-decoration:line-through;' : '') + '">' + esc(nickOf[s]) + badge + '</div></div>';
    });
    return h + '</div>';
  }

  function render() {
    document.getElementById('lv-board').innerHTML = board();
    document.getElementById('lv-log').innerHTML = S.log.length ? ('<b>Хронология:</b><br>' + S.log.map(esc).join('<br>')) : '';
    var st = document.getElementById('lv-status');
    if (S.lh) { st.textContent = 'ЛХ игрока «' + nickOf[S.lh.seat] + '» (место ' + S.lh.seat + ') — кликни 3 места'; renderLh(); return; }
    if (S.phase === 'nominate') { st.textContent = (S.round === 0 ? 'Нулевой круг' : 'Круг ' + S.round) + ' — выставление кандидатур'; renderNominate(); }
    else if (S.phase === 'vote') { st.textContent = (S.round === 0 ? 'Нулевой круг' : 'Круг ' + S.round) + ' — голосование'; renderVote(S.cands, false); }
    else if (S.phase === 'tie') { st.textContent = 'Ничья — переголосование'; renderVote(S.tied, true); }
    else if (S.phase === 'night') { st.textContent = 'Ночь ' + (S.round + 1) + ' — отстрел (кликни убитого)'; renderNight(); }
    else { st.textContent = 'Игра окончена'; ctl('<p style="color:var(--ok);">Готово — жми «Завершить и сохранить».</p>'); }
  }

  function renderNominate() {
    var h = '<p style="color:var(--tx2);margin:0 0 8px;">Кликай игроков в порядке выставления кандидатур.';
    if (S.cands.length) h += ' Выставлены: <b>' + S.cands.join(', ') + '</b>.';
    h += '</p><div style="display:flex;gap:8px;flex-wrap:wrap;">';
    if (S.cands.length) h += '<button type="button" class="btn" id="nm-vote">К голосованию →</button>';
    h += '<button type="button" class="btn btn-ghost" id="nm-none">Никто не выставлен → ночь</button></div>';
    ctl(h);
    if (S.cands.length) document.getElementById('nm-vote').onclick = function () { S.phase = 'vote'; render(); };
    document.getElementById('nm-none').onclick = function () { S.log.push((S.round === 0 ? 'Круг 0' : 'Круг ' + S.round) + ': кандидатур нет'); toNight(); };
  }

  function renderVote(cands, isTie) {
    var aliveN = aliveSeats().length;
    var h = '<div><div style="margin-bottom:8px;">Голосует: <b>' + aliveN + '</b> чел. Голоса по порядку (последнему — остаток автоматически):</div>';
    cands.forEach(function (s, idx) {
      var last = idx === cands.length - 1;
      h += '<div style="display:flex;gap:8px;align-items:center;margin:5px 0;">'
        + '<span style="width:180px;">за ' + s + ' · ' + esc(nickOf[s]) + ':</span>'
        + '<input type="number" min="0" class="lv-vote" data-seat="' + s + '"' + (last ? ' disabled value="0"' : ' value=""') + ' style="width:74px;">'
        + (last ? ' <span style="color:var(--tx3);">остаток</span>' : '') + '</div>';
    });
    h += '<div id="lv-remain" style="margin-top:6px;color:var(--tx3);"></div>';
    h += '<div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;"><button type="button" class="btn" id="vt-count">Подсчитать</button>';
    if (isTie) h += '<button type="button" class="btn btn-ghost" id="vt-raise">Снова ничья → подъём</button>';
    h += '</div></div>';
    ctl(h);
    var inputs = [].slice.call(document.querySelectorAll('.lv-vote'));
    var lastInp = inputs[inputs.length - 1];
    function recalc() {
      var sum = 0;
      for (var i = 0; i < inputs.length - 1; i++) sum += parseInt(inputs[i].value, 10) || 0;
      var rem = aliveN - sum;
      lastInp.value = rem < 0 ? 0 : rem;
      document.getElementById('lv-remain').textContent = rem < 0
        ? ('⚠ перебор голосов на ' + (-rem))
        : ('последнему (место ' + lastInp.getAttribute('data-seat') + '): ' + rem);
    }
    inputs.forEach(function (i) { i.addEventListener('input', recalc); });
    recalc();
    document.getElementById('vt-count').onclick = function () {
      var votes = {}, sum = 0;
      inputs.forEach(function (i) { var v = parseInt(i.value, 10) || 0; votes[+i.getAttribute('data-seat')] = v; sum += v; });
      if (sum !== aliveN) { alert('Сумма голосов (' + sum + ') ≠ числу голосующих (' + aliveN + ')'); return; }
      resolveVote(cands, votes);
    };
    if (isTie) document.getElementById('vt-raise').onclick = function () { raisePrompt(cands); };
  }

  function resolveVote(cands, votes) {
    var max = Math.max.apply(null, cands.map(function (s) { return votes[s] || 0; }));
    var top = cands.filter(function (s) { return (votes[s] || 0) === max; });
    S.log.push((S.round === 0 ? 'Круг 0' : 'Круг ' + S.round) + ': голоса ' + cands.map(function (s) { return s + '→' + (votes[s] || 0); }).join(', '));
    if (top.length === 1) { voteEliminate([top[0]]); }
    else { S.tied = top; S.phase = 'tie'; S.log.push('Ничья между: ' + top.join(', ')); render(); }
  }

  function raisePrompt(tied) {
    ctl('<div>Вопрос о подъёме между <b>' + tied.join(', ') + '</b> — нужно большинство.'
      + '<div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;">'
      + '<button type="button" class="btn" id="rs-yes">Подняли — выходят все</button>'
      + '<button type="button" class="btn btn-ghost" id="rs-no">Не подняли — никто</button></div></div>');
    document.getElementById('rs-yes').onclick = function () {
      S.log.push('Подъём: вышли все (' + tied.join(', ') + ')');
      voteEliminate(tied); // несколько — права на ЛХ нет (ЛХ только одиночному заголосованному)
    };
    document.getElementById('rs-no').onclick = function () { S.log.push('Подъём: не подняли, никто не вышел'); toNight(); };
  }

  function voteEliminate(seats) {
    seats.forEach(function (s) { S.alive[s] = false; S.elim.push({ seat: s, type: 'vote', round: S.round }); });
    S.tied = null;
    // право ЛХ — только одиночному заголосованному на нулевом круге
    if (S.round === 0 && seats.length === 1) { askLh('vote0', seats[0], toNight); return; }
    toNight();
  }

  function toNight() {
    S.tied = null;
    if (aliveSeats().length <= 2) { S.phase = 'done'; render(); return; }
    S.phase = 'night'; render();
  }
  function renderNight() {
    ctl('<p style="color:var(--tx2);margin:0 0 8px;">Кликни убитого ночью игрока, либо:</p>'
      + '<button type="button" class="btn btn-ghost" id="ni-miss">Промах (нет убитого)</button>');
    document.getElementById('ni-miss').onclick = function () { S.log.push('Ночь ' + (S.round + 1) + ': промах'); nextRound(); };
  }
  function nightKill(seat) {
    var firstNight = S.firstKill === null;
    S.alive[seat] = false; S.elim.push({ seat: seat, type: 'night', round: S.round });
    if (firstNight) S.firstKill = seat;
    S.log.push('Ночь ' + (S.round + 1) + ': убит ' + seat + ' (' + nickOf[seat] + ')' + (firstNight ? ' — ПУ' : ''));
    // ЛХ первоубиенного (если это первый ночной отстрел, не промах) — независимо от ЛХ голосования
    if (firstNight) { askLh('pu', seat, nextRound); return; }
    nextRound();
  }
  function nextRound() {
    S.round++;
    if (aliveSeats().length <= 2) { S.phase = 'done'; } else { S.phase = 'nominate'; S.cands = []; }
    render();
  }

  function askLh(target, seat, cb) { S.lh = { target: target, seat: seat, picks: [], cb: cb }; render(); }
  function renderLh() {
    ctl('<p style="color:var(--tx2);margin:0 0 8px;">Отмечены: <b>' + (S.lh.picks.join(', ') || '—') + '</b> (до 3).</p>'
      + '<div style="display:flex;gap:8px;flex-wrap:wrap;"><button type="button" class="btn" id="lh-ok">Готово</button>'
      + '<button type="button" class="btn btn-ghost" id="lh-skip">Без ЛХ</button></div>');
    document.getElementById('lh-ok').onclick = function () {
      var picks = S.lh.picks.slice(), seat = S.lh.seat, target = S.lh.target, cb = S.lh.cb;
      if (target === 'pu') { S.firstKill = seat; S.puBm = picks; }
      else { S.vote0Seat = seat; S.vote0Bm = picks; }
      S.log.push('ЛХ ' + (target === 'pu' ? 'ПУ' : 'заголос.') + ' (место ' + seat + ') → ' + (picks.join(', ') || '—'));
      S.lh = null; cb();
    };
    document.getElementById('lh-skip').onclick = function () { var cb = S.lh.cb; S.lh = null; cb(); };
  }

  document.getElementById('lv-undo').onclick = function () {
    if (S.elim.length) {
      var last = S.elim.pop();
      S.alive[last.seat] = true;
      if (S.firstKill === last.seat) { S.firstKill = null; S.puBm = []; }
      if (S.vote0Seat === last.seat) { S.vote0Seat = null; S.vote0Bm = []; }
    }
    S.log.pop();
    S.lh = null; S.tied = null; S.cands = [];
    S.phase = (S.round === 0 && !S.elim.length) ? 'nominate' : S.phase;
    if (S.phase === 'done') S.phase = 'nominate';
    render();
  };
  document.getElementById('lv-reset').onclick = function () { if (confirm('Начать ведение заново?')) init(); };

  document.getElementById('lv-finish').onclick = function () {
    document.getElementById('lv-chrono').value = JSON.stringify({ elim: S.elim, log: S.log, firstKill: S.firstKill, puBm: S.puBm, vote0Seat: S.vote0Seat, vote0Bm: S.vote0Bm });
    document.getElementById('lv-firstkill').value = S.firstKill || 0;
    document.getElementById('lv-bm1').value = S.puBm[0] || 0;
    document.getElementById('lv-bm2').value = S.puBm[1] || 0;
    document.getElementById('lv-bm3').value = S.puBm[2] || 0;
    document.getElementById('lv-vote0').value = S.vote0Seat || 0;
    document.getElementById('lv-v0bm1').value = S.vote0Bm[0] || 0;
    document.getElementById('lv-v0bm2').value = S.vote0Bm[1] || 0;
    document.getElementById('lv-v0bm3').value = S.vote0Bm[2] || 0;
    var oo = '';
    S.elim.forEach(function (e, i) { oo += '<input type="hidden" name="out_order[' + e.seat + ']" value="' + (i + 1) + '">'; });
    document.getElementById('lv-outorder').innerHTML = oo;
    document.getElementById('lv-form').submit();
  };

  init();
})();
</script>
<?php page_foot(); ?>
