<?php
require dirname(__DIR__, 2) . '/inc/bootstrap.php';
require ROOT . '/inc/rating.php';
require ROOT . '/inc/elo.php';
$u = require_judge();

// Игра турнира + сам турнир (для прав и заголовка)
$gid = (int)($_GET['game'] ?? 0);
$gq = db()->prepare("SELECT g.*, t.id AS t_id, t.title AS t_title, t.main_judge_player_id, t.table_judges
    FROM games g JOIN tournaments t ON t.id = g.tournament_id
    WHERE g.id = ? AND g.context = 'tournament'");
$gq->execute([$gid]);
$g = $gq->fetch();
if (!$g) {
    page_head('Протокол игры турнира', '');
    empty_state('Игра не найдена', 'Сгенерируй игры турнира в админке.');
    page_foot();
    exit;
}
$tid = (int)$g['t_id'];

// Право вести протокол: админ/владелец — всегда; судья — если он главный судья
// турнира ИЛИ назначен судьёй на этот стол.
$isAdmin = role_level($u['role']) >= 3;
$myPid = 0;
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
    flash_set('err', 'Вести протоколы этого турнира может админ или его судья.');
    redirect('/tournament.php?id=' . $tid);
}

// ── Сохранение результата игры ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if ((string)($_POST['form'] ?? '') === 'save_game') {
        $judgePid = (int)($_POST['judge'] ?? 0) ?: null;
        $winner = (string)($_POST['winner'] ?? '');
        $winner = in_array($winner, ['red', 'black', 'draw'], true) ? $winner : null;
        $pu = (int)($_POST['pu'] ?? 0);
        $pu = ($pu >= 1 && $pu <= 10) ? $pu : null;
        $bm = [];
        foreach (['bm1', 'bm2', 'bm3'] as $k) {
            $v = (int)($_POST[$k] ?? 0);
            $bm[] = ($v >= 1 && $v <= 10) ? $v : null;
        }
        // второй ЛХ — заголосованного на 0-м круге (независим от ЛХ ПУ)
        $vote0 = (int)($_POST['vote0_seat'] ?? 0);
        $vote0 = ($vote0 >= 1 && $vote0 <= 10) ? $vote0 : null;
        $v0bm = [];
        foreach (['v0bm1', 'v0bm2', 'v0bm3'] as $k) {
            $v = (int)($_POST[$k] ?? 0);
            $v0bm[] = ($v >= 1 && $v <= 10) ? $v : null;
        }
        // нельзя называть одно и то же место дважды в ЛХ
        $bf = array_filter($bm, fn($v) => $v !== null);
        $vf = array_filter($v0bm, fn($v) => $v !== null);
        if (count($bf) !== count(array_unique($bf)) || count($vf) !== count(array_unique($vf))) {
            flash_set('err', 'В ЛХ нельзя указывать одно и то же место дважды.');
            redirect('/admin/tournament_protocol.php?game=' . $gid);
        }

        // существующие места этой игры (игроки зафиксированы рассадкой)
        $sq = db()->prepare('SELECT seat FROM game_seats WHERE game_id = ? ORDER BY seat');
        $sq->execute([$gid]);
        $seatNos = array_map('intval', $sq->fetchAll(PDO::FETCH_COLUMN));

        $upd = [];
        $cnt = ['civ' => 0, 'maf' => 0, 'sheriff' => 0, 'don' => 0];
        foreach ($seatNos as $i) {
            $role = (string)($_POST["role$i"] ?? 'civ');
            $role = in_array($role, ['civ', 'maf', 'sheriff', 'don'], true) ? $role : 'civ';
            $cnt[$role]++;
            $upd[$i] = [
                'role' => $role,
                'fouls' => max(0, min(4, (int)($_POST["fouls$i"] ?? 0))),
                'tech_fouls' => max(0, min(2, (int)($_POST["tech$i"] ?? 0))),
                'big_tech' => max(0, min(2, (int)($_POST["bigtech$i"] ?? 0))),
                'removal' => max(0, min(2, (int)($_POST["removal$i"] ?? 0))),
                'plus' => max(0, (float)str_replace(',', '.', (string)($_POST["plus$i"] ?? '0'))),
                'minus' => max(0, (float)str_replace(',', '.', (string)($_POST["minus$i"] ?? '0'))),
            ];
        }
        if (!$winner) {
            flash_set('err', 'Не указан победитель');
            redirect('/admin/tournament_protocol.php?game=' . $gid);
        }
        if (count($seatNos) === 10 && ($cnt['don'] !== 1 || $cnt['sheriff'] !== 1 || $cnt['maf'] !== 2 || $cnt['civ'] !== 6)) {
            flash_set('err', 'Неверный расклад ролей: нужно 1 дон, 1 шериф, 2 мафии, 6 мирных (сейчас: дон '
                . $cnt['don'] . ', шериф ' . $cnt['sheriff'] . ', мафия ' . $cnt['maf'] . ', мирн ' . $cnt['civ'] . ')');
            redirect('/admin/tournament_protocol.php?game=' . $gid);
        }

        $pdo = db();
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE games SET judge_player_id=?, winner=?, first_killed_seat=?,
            bm_seat1=?, bm_seat2=?, bm_seat3=?, vote0_seat=?, vote0_bm1=?, vote0_bm2=?, vote0_bm3=?,
            comment=?, status='finished', finished_at=NOW()
            WHERE id=? AND context='tournament'")
            ->execute([$judgePid, $winner, $pu, $bm[0], $bm[1], $bm[2],
                $vote0, $v0bm[0], $v0bm[1], $v0bm[2],
                trim((string)($_POST['comment'] ?? '')) ?: null, $gid]);
        $us = $pdo->prepare('UPDATE game_seats SET role=?, fouls=?, tech_fouls=?, big_tech=?, removal=?, plus=?, minus=?
            WHERE game_id=? AND seat=?');
        foreach ($upd as $seat => $s) {
            $us->execute([$s['role'], $s['fouls'], $s['tech_fouls'], $s['big_tech'], $s['removal'], $s['plus'], $s['minus'], $gid, $seat]);
        }
        $pdo->commit();

        rating_recompute_all();
        elo_recompute();
        log_action((int)$u['id'], 'tournament_game_save', ['game_id' => $gid, 'tournament_id' => $tid]);
        flash_set('ok', 'Результат сохранён, таблица турнира и ELO обновлены');
        redirect('/tournament.php?id=' . $tid . '#game-' . $gid);
    }
}

// ── Данные для формы ──
$seatsSt = db()->prepare('SELECT gs.*, p.nickname, p.avatar FROM game_seats gs
    JOIN players p ON p.id = gs.player_id WHERE gs.game_id = ? ORDER BY gs.seat');
$seatsSt->execute([$gid]);
$seats = $seatsSt->fetchAll();
$allPlayers = db()->query('SELECT id, nickname FROM players WHERE banned_at IS NULL ORDER BY nickname')->fetchAll();
$roleOpts = ['civ' => 'Мирный', 'maf' => 'Мафия', 'sheriff' => 'Шериф', 'don' => 'Дон'];
$maxSeat = $seats ? max(array_map(fn($s) => (int)$s['seat'], $seats)) : 10;

page_head('Протокол — ' . $g['t_title'], '');
?>
<p><a href="/tournament.php?id=<?= $tid ?>">← к турниру «<?= esc($g['t_title']) ?>»</a>
   &nbsp;·&nbsp; <a href="/admin/tournaments.php">Турниры</a>
   &nbsp;·&nbsp; <a href="/admin/">Админка</a></p>
<h1>Стол <?= (int)$g['table_no'] ?> · круг <?= (int)$g['game_no'] ?></h1>
<p style="color:var(--tx2);margin-top:-6px;">Игроки рассажены автоматически (ротация). Проставь роли и результат — таблица турнира пересчитается сразу.</p>

<!-- Таймер ведущего -->
<div class="card timer-card">
  <div class="timer-display" id="tm-display">0:30</div>
  <div class="timer-controls">
    <button type="button" class="tm-btn" data-sec="15">15</button>
    <button type="button" class="tm-btn" data-sec="20">20</button>
    <button type="button" class="tm-btn" data-sec="30">30</button>
    <button type="button" class="tm-btn" data-sec="45">45</button>
    <button type="button" class="tm-btn" data-sec="60">60</button>
    <button type="button" class="tm-btn" id="tm-add">+30</button>
    <button type="button" class="tm-btn" data-sec="20" id="tm-lh">ЛХ 20</button>
    <button type="button" class="tm-btn tm-stop" id="tm-stop">Стоп</button>
  </div>
</div>

<form method="post" action="/admin/tournament_protocol.php?game=<?= $gid ?>" id="game-form">
  <?= csrf_field() ?>
  <input type="hidden" name="form" value="save_game">

  <div class="card">
    <div class="section-head" style="margin-bottom:10px;">
      <h2 style="margin:0;">Протокол игры<?= $g['status'] === 'finished' ? ' <span class="tag" style="opacity:.7;">уже внесена — правка</span>' : '' ?></h2>
      <div style="display:flex;gap:8px;align-items:center;">
        <label style="font-size:13px;color:var(--tx2);">Судья:</label>
        <select name="judge" style="background:var(--sf2);color:var(--tx);border:1px solid var(--bd);border-radius:7px;padding:6px 10px;">
          <option value="0">—</option>
          <?php foreach ($allPlayers as $p): ?>
            <option value="<?= (int)$p['id'] ?>" <?= (int)$g['judge_player_id'] === (int)$p['id'] ? 'selected' : '' ?>><?= esc($p['nickname']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div style="overflow-x:auto;">
      <table class="tbl protocol-tbl">
        <tr>
          <th>#</th><th>Игрок</th><th>Роль</th><th>Фолы</th><th>Тех</th><th title="большой тех.фол: −0.6 каждый, макс 2">Бол.<br>тех</th>
          <th title="удаление: −0.6; на критический круг: −1.2">Удал.</th>
          <th>+</th><th>−</th><th class="num">Итог</th>
        </tr>
        <?php foreach ($seats as $es): $i = (int)$es['seat']; ?>
        <tr data-seat="<?= $i ?>">
          <td><?= $i ?></td>
          <td style="white-space:nowrap;"><?= avatar_html(['nickname' => $es['nickname'], 'avatar' => $es['avatar']], 24, 'margin-right:7px;') ?><b><?= esc($es['nickname']) ?></b></td>
          <td>
            <select name="role<?= $i ?>" class="f-role">
              <?php foreach ($roleOpts as $rk => $rl): ?>
                <option value="<?= $rk ?>" <?= ($es['role'] ?? 'civ') === $rk ? 'selected' : '' ?>><?= $rl ?></option>
              <?php endforeach; ?>
            </select>
          </td>
          <td><select name="fouls<?= $i ?>" class="f-fouls"><?php for ($f = 0; $f <= 4; $f++): ?>
            <option value="<?= $f ?>" <?= (int)($es['fouls'] ?? 0) === $f ? 'selected' : '' ?>><?= $f ?></option>
          <?php endfor; ?></select></td>
          <td><select name="tech<?= $i ?>" class="f-tech"><?php for ($f = 0; $f <= 2; $f++): ?>
            <option value="<?= $f ?>" <?= (int)($es['tech_fouls'] ?? 0) === $f ? 'selected' : '' ?>><?= $f ?></option>
          <?php endfor; ?></select></td>
          <td><select name="bigtech<?= $i ?>" class="f-bigtech"><?php for ($f = 0; $f <= 2; $f++): ?>
            <option value="<?= $f ?>" <?= (int)($es['big_tech'] ?? 0) === $f ? 'selected' : '' ?>><?= $f ?></option>
          <?php endfor; ?></select></td>
          <td><select name="removal<?= $i ?>" class="f-removal" title="удаление / на критический круг">
            <option value="0" <?= (int)($es['removal'] ?? 0) === 0 ? 'selected' : '' ?>>—</option>
            <option value="1" <?= (int)($es['removal'] ?? 0) === 1 ? 'selected' : '' ?>>уд</option>
            <option value="2" <?= (int)($es['removal'] ?? 0) === 2 ? 'selected' : '' ?>>уд!</option>
          </select></td>
          <td><input type="text" name="plus<?= $i ?>" class="f-plus" inputmode="decimal"
              value="<?= (float)$es['plus'] ? rtrim(rtrim(number_format((float)$es['plus'], 2, '.', ''), '0'), '.') : '' ?>" style="width:46px;"></td>
          <td><input type="text" name="minus<?= $i ?>" class="f-minus" inputmode="decimal"
              value="<?= (float)$es['minus'] ? rtrim(rtrim(number_format((float)$es['minus'], 2, '.', ''), '0'), '.') : '' ?>" style="width:46px;"></td>
          <td class="num"><b class="f-total">0</b></td>
        </tr>
        <?php endforeach; ?>
      </table>
    </div>

    <div id="dop-pad" style="margin-top:10px;padding:9px 11px;background:var(--sf2);border-radius:9px;display:flex;flex-wrap:wrap;gap:6px;align-items:center;">
      <span style="font-size:12px;color:var(--tx2);">Быстрый ввод → <b id="dop-target" style="color:var(--ac);">кликни поле «+» или «−»</b>:</span>
      <?php for ($d = 1; $d <= 15; $d++): $vv = number_format($d / 10, 1, '.', ''); ?>
        <button type="button" class="btn btn-ghost dop-b" data-v="<?= $vv ?>" style="padding:3px 9px;font-size:12.5px;"><?= $vv ?></button>
      <?php endfor; ?>
      <button type="button" class="btn btn-ghost dop-b" data-v="0" style="padding:3px 11px;font-size:12.5px;" title="очистить">×</button>
    </div>

    <div style="display:flex;gap:18px;flex-wrap:wrap;margin-top:14px;align-items:end;">
      <div class="field" style="margin:0;">
        <label>Первоубиенный (ПУ)</label>
        <select name="pu" id="f-pu" style="background:var(--sf2);color:var(--tx);border:1px solid var(--bd);border-radius:7px;padding:7px 10px;">
          <option value="0">промах / нет</option>
          <?php for ($o = 1; $o <= $maxSeat; $o++): ?>
            <option value="<?= $o ?>" <?= (int)($g['first_killed_seat'] ?? 0) === $o ? 'selected' : '' ?>><?= $o ?></option>
          <?php endfor; ?>
        </select>
      </div>
      <div class="field" style="margin:0;">
        <label>ЛХ первоубиенного (3 места)</label>
        <div style="display:flex;gap:6px;align-items:center;">
          <?php foreach (['bm1' => 'bm_seat1', 'bm2' => 'bm_seat2', 'bm3' => 'bm_seat3'] as $f => $col): ?>
          <select name="<?= $f ?>" class="f-bm" style="background:var(--sf2);color:var(--tx);border:1px solid var(--bd);border-radius:7px;padding:7px 8px;">
            <option value="0">—</option>
            <?php for ($o = 1; $o <= $maxSeat; $o++): ?>
              <option value="<?= $o ?>" <?= (int)($g[$col] ?? 0) === $o ? 'selected' : '' ?>><?= $o ?></option>
            <?php endfor; ?>
          </select>
          <?php endforeach; ?>
          <span id="bm-readout" style="margin-left:4px;font-size:13px;font-weight:600;white-space:nowrap;color:var(--tx3);">—</span>
        </div>
      </div>
      <div class="field" style="margin:0;">
        <label>Победа</label>
        <select name="winner" id="f-winner" required style="background:var(--sf2);color:var(--tx);border:1px solid var(--bd);border-radius:7px;padding:7px 10px;">
          <option value="">—</option>
          <option value="red" <?= ($g['winner'] ?? '') === 'red' ? 'selected' : '' ?>>Красные</option>
          <option value="black" <?= ($g['winner'] ?? '') === 'black' ? 'selected' : '' ?>>Чёрные</option>
          <option value="draw" <?= ($g['winner'] ?? '') === 'draw' ? 'selected' : '' ?>>Ничья</option>
        </select>
      </div>
    </div>

    <div style="display:flex;gap:18px;flex-wrap:wrap;margin-top:10px;align-items:end;">
      <div class="field" style="margin:0;">
        <label>Заголосован на 0-м круге <span style="color:var(--tx3);font-weight:400;font-size:11px;">(одиночный → его ЛХ)</span></label>
        <select name="vote0_seat" style="background:var(--sf2);color:var(--tx);border:1px solid var(--bd);border-radius:7px;padding:7px 10px;">
          <option value="0">— нет —</option>
          <?php for ($o = 1; $o <= $maxSeat; $o++): ?>
            <option value="<?= $o ?>" <?= (int)($g['vote0_seat'] ?? 0) === $o ? 'selected' : '' ?>><?= $o ?></option>
          <?php endfor; ?>
        </select>
      </div>
      <div class="field" style="margin:0;">
        <label>Его ЛХ (3 места)</label>
        <div style="display:flex;gap:6px;align-items:center;">
          <?php foreach (['v0bm1' => 'vote0_bm1', 'v0bm2' => 'vote0_bm2', 'v0bm3' => 'vote0_bm3'] as $f => $col): ?>
          <select name="<?= $f ?>" style="background:var(--sf2);color:var(--tx);border:1px solid var(--bd);border-radius:7px;padding:7px 8px;">
            <option value="0">—</option>
            <?php for ($o = 1; $o <= $maxSeat; $o++): ?>
              <option value="<?= $o ?>" <?= (int)($g[$col] ?? 0) === $o ? 'selected' : '' ?>><?= $o ?></option>
            <?php endfor; ?>
          </select>
          <?php endforeach; ?>
          <span id="v0-readout" style="margin-left:4px;font-size:13px;font-weight:600;white-space:nowrap;color:var(--tx3);">—</span>
        </div>
      </div>
    </div>

    <div class="field" style="margin:12px 0 0;">
      <label>Комментарий к игре</label>
      <input type="text" name="comment" value="<?= esc($g['comment'] ?? '') ?>">
    </div>

    <div style="margin-top:14px;display:flex;gap:10px;">
      <button class="btn" type="submit">Сохранить результат</button>
      <a class="btn btn-ghost" href="/tournament.php?id=<?= $tid ?>">К турниру</a>
    </div>
    <p style="font-size:12px;color:var(--tx2);margin:10px 0 0;">Ci (компенсация ПУ) считается автоматически. Итог в таблице — предварительный, без Ci.</p>
  </div>
</form>

<script>
(function () {
  // ── Таймер ──
  var disp = document.getElementById('tm-display');
  var remain = 30, timer = null;
  function fmt(s) { var m = Math.floor(s / 60); var ss = s % 60; return m + ':' + (ss < 10 ? '0' : '') + ss; }
  function render() { disp.textContent = fmt(Math.max(0, remain)); disp.classList.toggle('tm-low', remain <= 5 && remain > 0); disp.classList.toggle('tm-zero', remain <= 0); }
  function stop() { if (timer) { clearInterval(timer); timer = null; } }
  function start(sec) {
    stop(); remain = sec; render();
    timer = setInterval(function () { remain--; render(); if (remain <= 0) { stop(); try { beepSound(); } catch (e) {} } }, 1000);
  }
  function beepSound() {
    var ctx = new (window.AudioContext || window.webkitAudioContext)();
    var o = ctx.createOscillator(); var gg = ctx.createGain();
    o.connect(gg); gg.connect(ctx.destination); o.frequency.value = 880; o.start();
    gg.gain.setValueAtTime(0.3, ctx.currentTime); gg.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.4);
    o.stop(ctx.currentTime + 0.4);
  }
  document.querySelectorAll('.tm-btn[data-sec]').forEach(function (b) { b.addEventListener('click', function () { start(parseInt(b.dataset.sec, 10)); }); });
  document.getElementById('tm-add').addEventListener('click', function () { remain += 30; render(); if (!timer) start(remain); });
  document.getElementById('tm-stop').addEventListener('click', function () { stop(); remain = 0; render(); });
  render();

  // ── Живой подсчёт итога (как на вечерах) ──
  var RED = ['civ', 'sheriff'], BLACK = ['maf', 'don'];
  function roles() { var r = {}; document.querySelectorAll('tr[data-seat]').forEach(function (tr) { r[tr.dataset.seat] = tr.querySelector('.f-role').value; }); return r; }
  // Бонус ЛХ по тройке названных мест (names — имена трёх select-ов): сколько из них чёрные → 0.1/0.3/0.6
  function lhBonus(names) {
    var rs = roles(), hits = 0, given = 0;
    names.forEach(function (n) {
      var el = document.querySelector('[name=' + n + ']');
      var v = el ? el.value : '0';
      if (v !== '0') { given++; if (BLACK.indexOf(rs[v]) >= 0) hits++; }
    });
    if (given === 0) return 0;
    return { 1: 0.1, 2: 0.3, 3: 0.6 }[hits] || 0;
  }
  // Живой индикатор у блока ЛХ: бонус + идёт ли он в зачёт (ЛХ только красным/шерифу)
  function setReadout(elId, seatVal, bonus, role) {
    var el = document.getElementById(elId); if (!el) return;
    if (!seatVal || seatVal === '0') { el.textContent = '—'; el.style.color = 'var(--tx3)'; return; }
    if (bonus <= 0) { el.textContent = 'ЛХ +0'; el.style.color = 'var(--tx3)'; return; }
    var red = RED.indexOf(role) >= 0;
    el.textContent = 'ЛХ +' + bonus.toFixed(1) + (red ? '' : ' · чёрный — не в зачёт');
    el.style.color = red ? 'var(--ok)' : 'var(--ac)';
  }
  function recompute() {
    var winner = document.getElementById('f-winner').value;
    var puSeat = document.getElementById('f-pu').value;
    var v0el = document.querySelector('[name=vote0_seat]');
    var v0Seat = v0el ? v0el.value : '0';
    // Два независимых ЛХ: первоубиенного ночью и заголосованного на 0-м круге — оба учитываются сразу
    var puBonus = lhBonus(['bm1', 'bm2', 'bm3']);
    var v0Bonus = lhBonus(['v0bm1', 'v0bm2', 'v0bm3']);
    document.querySelectorAll('tr[data-seat]').forEach(function (tr) {
      var seat = tr.dataset.seat;
      var role = tr.querySelector('.f-role').value;
      var plus = parseFloat((tr.querySelector('.f-plus').value || '0').replace(',', '.')) || 0;
      var minus = parseFloat((tr.querySelector('.f-minus').value || '0').replace(',', '.')) || 0;
      var fouls = parseInt(tr.querySelector('.f-fouls').value, 10) || 0;
      var tech = parseInt(tr.querySelector('.f-tech').value, 10) || 0;
      var bigtech = parseInt((tr.querySelector('.f-bigtech') || {}).value, 10) || 0;
      var removal = parseInt((tr.querySelector('.f-removal') || {}).value, 10) || 0;
      var isPu = (puSeat === seat), isV0 = (v0Seat !== '0' && v0Seat === seat);
      var lh = isPu ? puBonus : (isV0 ? v0Bonus : 0);
      var lhCounts = (isPu || isV0) && RED.indexOf(role) >= 0 && lh > 0; // ЛХ — только красным/шерифу
      var total = 0;
      if (winner === 'draw') {
        total = plus - minus + (lhCounts ? lh : 0);
      } else if (winner === 'red' || winner === 'black') {
        var team = winner === 'black' ? BLACK : RED;
        total = (team.indexOf(role) >= 0 ? 1 : 0) + plus - minus;
        if (lhCounts) total += lh;
      } else {
        total = plus - minus;
      }
      if (fouls >= 4) total -= 0.6;
      total -= 0.3 * tech;
      total -= 0.6 * bigtech;
      if (removal === 1) total -= 0.6; else if (removal === 2) total -= 1.2;
      tr.querySelector('.f-total').textContent = Math.round(total * 100) / 100;
    });
    // Оба ЛХ показываем одновременно — рядом со своими блоками
    var rsR = roles();
    setReadout('bm-readout', puSeat, puBonus, rsR[puSeat]);
    setReadout('v0-readout', v0Seat, v0Bonus, rsR[v0Seat]);
  }
  document.getElementById('game-form').addEventListener('input', recompute);
  document.getElementById('game-form').addEventListener('change', recompute);
  recompute();

  // ── Быстрые кнопки (применяются к последнему выбранному полю «+» или «−») ──
  var lastField = null, dopTarget = document.getElementById('dop-target');
  function bindQuick(sel, kind) {
    document.querySelectorAll(sel).forEach(function (inp) {
      inp.addEventListener('focus', function () {
        lastField = inp;
        var tr = inp.closest('tr[data-seat]');
        if (dopTarget && tr) {
          var ni = tr.querySelector('input[name^="nick"]');
          var nm = ni ? ni.value.trim() : ((tr.querySelector('td:nth-child(2)') || {}).textContent || '').trim();
          dopTarget.textContent = kind + ' · место ' + tr.dataset.seat + (nm ? ' · ' + nm : '');
        }
      });
    });
  }
  bindQuick('.f-plus', 'доп +');
  bindQuick('.f-minus', 'минус −');
  document.querySelectorAll('.dop-b').forEach(function (b) {
    b.addEventListener('mousedown', function (e) { e.preventDefault(); }); // не терять фокус поля
    b.addEventListener('click', function () {
      if (!lastField) return;
      var v = b.getAttribute('data-v');
      lastField.value = (v === '0') ? '' : v;
      lastField.dispatchEvent(new Event('input', { bubbles: true }));
      lastField.focus();
    });
  });
})();
</script>
<?php page_foot(); ?>
