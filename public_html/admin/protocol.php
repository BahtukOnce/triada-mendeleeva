<?php
require dirname(__DIR__, 2) . '/inc/bootstrap.php';
require ROOT . '/inc/rating.php';
require ROOT . '/inc/elo.php';
$u = require_judge();

$dayId = (int)($_GET['day'] ?? 0);
$st = db()->prepare('SELECT * FROM game_days WHERE id = ?');
$st->execute([$dayId]);
$day = $st->fetch();
if (!$day) {
    page_head('Вести игры', '');
    empty_state('Вечер не найден', 'Создайте вечер в разделе «Игровые вечера».');
    page_foot();
    exit;
}

// ── Сохранение игры ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $form = (string)($_POST['form'] ?? '');

    if ($form === 'delete_game') {
        $gid = (int)($_POST['game_id'] ?? 0);
        db()->prepare('DELETE FROM games WHERE id = ? AND day_id = ?')->execute([$gid, $dayId]);
        log_action((int)$u['id'], 'game_delete', ['game_id' => $gid]);
        recompute_all_locked();
        flash_set('ok', 'Игра удалена, рейтинг пересчитан');
        redirect('/admin/protocol.php?day=' . $dayId);
    }

    if ($form === 'save_game') {
        $gid = (int)($_POST['game_id'] ?? 0);
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

        // Собираем места
        $seats = [];
        $errors = [];
        $usedPlayers = [];
        for ($i = 1; $i <= 10; $i++) {
            $nick = trim((string)($_POST["nick$i"] ?? ''));
            if ($nick === '') {
                continue;
            }
            $pid = player_id_by_nick($nick);
            if (!$pid) {
                $errors[] = "Место $i: игрок «" . esc($nick) . "» не найден";
                continue;
            }
            if (isset($usedPlayers[$pid])) {
                $errors[] = "Место $i: игрок «" . esc($nick) . "» уже за столом";
                continue;
            }
            $usedPlayers[$pid] = true;
            $role = (string)($_POST["role$i"] ?? 'civ');
            $role = in_array($role, ['civ', 'maf', 'sheriff', 'don'], true) ? $role : 'civ';
            $seats[$i] = [
                'player_id' => $pid,
                'role' => $role,
                'fouls' => max(0, min(4, (int)($_POST["fouls$i"] ?? 0))),
                'tech_fouls' => max(0, min(2, (int)($_POST["tech$i"] ?? 0))),
                'big_tech' => max(0, min(2, (int)($_POST["bigtech$i"] ?? 0))),
                'removal' => max(0, min(2, (int)($_POST["removal$i"] ?? 0))),
                // Верхний предел — защита от опечатки (15 вместо 1.5), реальные баллы столько не набирают
                'plus' => min(9.9, max(0, (float)str_replace(',', '.', (string)($_POST["plus$i"] ?? '0')))),
                'minus' => min(9.9, max(0, (float)str_replace(',', '.', (string)($_POST["minus$i"] ?? '0')))),
                'out_order' => (int)($_POST["out$i"] ?? 0) ?: null,
            ];
        }

        if (count($seats) < 6) {
            $errors[] = 'Слишком мало игроков (нужно хотя бы 6)';
        }
        if (!$winner) {
            $errors[] = 'Не указан победитель';
        }
        // Жёсткая проверка расклада: ровно 1 дон, 1 шериф, 2 мафии, 6 мирных — иначе не сохраняем
        if (count($seats) === 10) {
            $cnt = ['civ' => 0, 'maf' => 0, 'sheriff' => 0, 'don' => 0];
            foreach ($seats as $s) {
                $cnt[$s['role']]++;
            }
            if ($cnt['don'] !== 1 || $cnt['sheriff'] !== 1 || $cnt['maf'] !== 2 || $cnt['civ'] !== 6) {
                $errors[] = 'Неверный расклад ролей: нужно 1 дон, 1 шериф, 2 мафии, 6 мирных (сейчас: дон '
                    . $cnt['don'] . ', шериф ' . $cnt['sheriff'] . ', мафия ' . $cnt['maf'] . ', мирн ' . $cnt['civ'] . ')';
            }
        }

        // Игра, если редактируем, должна принадлежать ЭТОМУ вечеру — иначе последующие
        // DELETE/INSERT game_seats по game_id из POST затрут чужую игру (UPDATE с
        // «AND day_id» молча не сработает, но seats всё равно перезапишутся).
        if ($gid) {
            $own = db()->prepare('SELECT 1 FROM games WHERE id = ? AND day_id = ?');
            $own->execute([$gid, $dayId]);
            if (!$own->fetchColumn()) {
                $errors[] = 'Игра не принадлежит этому вечеру';
            }
        }

        if ($errors) {
            flash_set('err', implode('; ', $errors));
            redirect('/admin/protocol.php?day=' . $dayId . ($gid ? '&game=' . $gid : ''));
        }

        $pdo = db();
        $pdo->beginTransaction();
        if ($gid) {
            $pdo->prepare('UPDATE games SET judge_player_id=?, winner=?, first_killed_seat=?,
                bm_seat1=?, bm_seat2=?, bm_seat3=?, comment=?, status=\'finished\', finished_at=NOW()
                WHERE id=? AND day_id=?')
                ->execute([$judgePid, $winner, $pu, $bm[0], $bm[1], $bm[2],
                    trim((string)($_POST['comment'] ?? '')) ?: null, $gid, $dayId]);
            $pdo->prepare('DELETE FROM game_seats WHERE game_id = ?')->execute([$gid]);
        } else {
            $stmt = $pdo->prepare('SELECT COALESCE(MAX(game_no),0)+1 FROM games WHERE day_id = ?');
            $stmt->execute([$dayId]);
            $nextNo = (int)$stmt->fetchColumn();
            $pdo->prepare("INSERT INTO games (context, day_id, table_no, game_no, judge_player_id, winner,
                first_killed_seat, bm_seat1, bm_seat2, bm_seat3, comment, status, finished_at)
                VALUES ('day', ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, 'finished', NOW())")
                ->execute([$dayId, $nextNo, $judgePid, $winner, $pu, $bm[0], $bm[1], $bm[2],
                    trim((string)($_POST['comment'] ?? '')) ?: null]);
            $gid = (int)$pdo->lastInsertId();
        }
        $insS = $pdo->prepare('INSERT INTO game_seats
            (game_id, seat, player_id, role, fouls, tech_fouls, big_tech, removal, plus, minus, out_order)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        foreach ($seats as $seat => $s) {
            $insS->execute([$gid, $seat, $s['player_id'], $s['role'], $s['fouls'],
                $s['tech_fouls'], $s['big_tech'], $s['removal'], $s['plus'], $s['minus'], $s['out_order']]);
        }
        $pdo->commit();

        recompute_all_locked();
        log_action((int)$u['id'], 'game_save', ['game_id' => $gid, 'day_id' => $dayId]);
        flash_set('ok', 'Игра сохранена, рейтинг обновлён');
        redirect('/admin/protocol.php?day=' . $dayId);
    }
}

// ── Данные для формы ──
$roster = db()->prepare('SELECT p.id, p.nickname FROM day_registrations r
    JOIN players p ON p.id = r.player_id
    WHERE r.day_id = ? AND r.cancelled_at IS NULL ORDER BY p.nickname');
$roster->execute([$dayId]);
$rosterList = $roster->fetchAll();
$allPlayers = db()->query('SELECT id, nickname FROM players WHERE banned_at IS NULL ORDER BY nickname')->fetchAll();

// Игры вечера
$gamesSt = db()->prepare("SELECT g.*, jp.nickname AS judge_nick FROM games g
    LEFT JOIN players jp ON jp.id = g.judge_player_id
    WHERE g.day_id = ? ORDER BY g.game_no");
$gamesSt->execute([$dayId]);
$games = $gamesSt->fetchAll();

// Редактируемая игра
$editGame = null;
$editSeats = [];
$editGid = (int)($_GET['game'] ?? 0);
if ($editGid) {
    foreach ($games as $g) {
        if ((int)$g['id'] === $editGid) {
            $editGame = $g;
        }
    }
    if ($editGame) {
        $s = db()->prepare('SELECT gs.*, p.nickname FROM game_seats gs JOIN players p ON p.id = gs.player_id
            WHERE gs.game_id = ? ORDER BY gs.seat');
        $s->execute([$editGid]);
        foreach ($s->fetchAll() as $row) {
            $editSeats[(int)$row['seat']] = $row;
        }
    }
}

$roleOpts = ['civ' => 'Мирный', 'maf' => 'Мафия', 'sheriff' => 'Шериф', 'don' => 'Дон'];

page_head('Ведение игры — ' . $day['title'], '');
?>
<p><a href="/admin/days.php">← Вечера</a></p>
<h1>Ведение игры: <?= esc($day['title']) ?> · <?= date('d.m.Y', strtotime($day['date'])) ?></h1>

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

<!-- Форма игры -->
<form method="post" action="/admin/protocol.php?day=<?= $dayId ?>" id="game-form">
  <?= csrf_field() ?>
  <input type="hidden" name="form" value="save_game">
  <input type="hidden" name="game_id" value="<?= $editGid ?>">

  <datalist id="players-dl">
    <?php foreach ($allPlayers as $p): ?>
      <option value="<?= esc($p['nickname']) ?>"></option>
    <?php endforeach; ?>
  </datalist>

  <div class="card">
    <div class="section-head" style="margin-bottom:10px;">
      <h2 style="margin:0;"><?= $editGame ? 'Игра ' . (int)$editGame['game_no'] : 'Новая игра ' . (count($games) + 1) ?></h2>
      <div style="display:flex;gap:8px;align-items:center;">
        <label style="font-size:13px;color:var(--tx2);">Судья:</label>
        <select name="judge" style="background:var(--sf2);color:var(--tx);border:1px solid var(--bd);border-radius:7px;padding:6px 10px;">
          <option value="0">—</option>
          <?php foreach ($allPlayers as $p): ?>
            <option value="<?= (int)$p['id'] ?>" <?= $editGame && (int)$editGame['judge_player_id'] === (int)$p['id'] ? 'selected' : '' ?>><?= esc($p['nickname']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <?php if ($rosterList): ?>
      <p style="font-size:12.5px;color:var(--tx2);margin:0 0 10px;">Записаны:
        <?= implode(', ', array_map(fn($r) => esc($r['nickname']), $rosterList)) ?></p>
    <?php endif; ?>

    <div style="overflow-x:auto;">
      <table class="tbl protocol-tbl">
        <tr>
          <th>#</th><th>Игрок</th><th>Роль</th><th>Фолы</th><th>Тех</th><th title="большой тех.фол: −0.6 каждый, макс 2">Бол.<br>тех</th>
          <th title="удаление: −0.6; на критический круг: −1.2">Удал.</th>
          <th>+</th><th>−</th><th class="num">Итог</th><th>Выб.</th>
        </tr>
        <?php for ($i = 1; $i <= 10; $i++): $es = $editSeats[$i] ?? null; ?>
        <tr data-seat="<?= $i ?>">
          <td><?= $i ?></td>
          <td><input type="text" name="nick<?= $i ?>" list="players-dl" autocomplete="off"
              value="<?= esc($es['nickname'] ?? '') ?>" style="width:120px;"></td>
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
              value="<?= $es && (float)$es['plus'] ? rtrim(rtrim(number_format((float)$es['plus'], 1, '.', ''), '0'), '.') : '' ?>" style="width:42px;"></td>
          <td><input type="text" name="minus<?= $i ?>" class="f-minus" inputmode="decimal"
              value="<?= $es && (float)$es['minus'] ? rtrim(rtrim(number_format((float)$es['minus'], 1, '.', ''), '0'), '.') : '' ?>" style="width:42px;"></td>
          <td class="num"><b class="f-total">0</b></td>
          <td><select name="out<?= $i ?>" style="width:48px;"><option value="0">—</option>
            <?php for ($o = 1; $o <= 10; $o++): ?>
              <option value="<?= $o ?>" <?= (int)($es['out_order'] ?? 0) === $o ? 'selected' : '' ?>><?= $o ?></option>
            <?php endfor; ?></select></td>
        </tr>
        <?php endfor; ?>
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
          <?php for ($o = 1; $o <= 10; $o++): ?>
            <option value="<?= $o ?>" <?= (int)($editGame['first_killed_seat'] ?? 0) === $o ? 'selected' : '' ?>><?= $o ?></option>
          <?php endfor; ?>
        </select>
      </div>
      <div class="field" style="margin:0;">
        <label>Лучший ход (3 места)</label>
        <div style="display:flex;gap:6px;">
          <?php foreach (['bm1' => 'bm_seat1', 'bm2' => 'bm_seat2', 'bm3' => 'bm_seat3'] as $f => $col): ?>
          <select name="<?= $f ?>" class="f-bm" style="background:var(--sf2);color:var(--tx);border:1px solid var(--bd);border-radius:7px;padding:7px 8px;">
            <option value="0">—</option>
            <?php for ($o = 1; $o <= 10; $o++): ?>
              <option value="<?= $o ?>" <?= (int)($editGame[$col] ?? 0) === $o ? 'selected' : '' ?>><?= $o ?></option>
            <?php endfor; ?>
          </select>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="field" style="margin:0;">
        <label>Победа</label>
        <select name="winner" id="f-winner" required style="background:var(--sf2);color:var(--tx);border:1px solid var(--bd);border-radius:7px;padding:7px 10px;">
          <option value="">—</option>
          <option value="red" <?= ($editGame['winner'] ?? '') === 'red' ? 'selected' : '' ?>>Красные</option>
          <option value="black" <?= ($editGame['winner'] ?? '') === 'black' ? 'selected' : '' ?>>Чёрные</option>
          <option value="draw" <?= ($editGame['winner'] ?? '') === 'draw' ? 'selected' : '' ?>>Ничья</option>
        </select>
      </div>
    </div>

    <div class="field" style="margin:12px 0 0;">
      <label>Комментарий к игре</label>
      <input type="text" name="comment" value="<?= esc($editGame['comment'] ?? '') ?>">
    </div>

    <div style="margin-top:14px;display:flex;gap:10px;">
      <button class="btn" type="submit"><?= $editGame ? 'Сохранить изменения' : 'Сохранить игру' ?></button>
      <?php if ($editGame): ?><a class="btn btn-ghost" href="/admin/protocol.php?day=<?= $dayId ?>">Отмена</a><?php endif; ?>
    </div>
    <p style="font-size:12px;color:var(--tx2);margin:10px 0 0;">Ci (компенсация ПУ) считается автоматически при сохранении. Итог в таблице — предварительный, без Ci.</p>
  </div>
</form>

<?php if ($games): ?>
<div class="card">
  <h2 style="margin-top:0;">Игры вечера (<?= count($games) ?>)</h2>
  <table class="tbl">
    <tr><th>№</th><th>Победа</th><th>Судья</th><th>ПУ</th><th></th></tr>
    <?php $winLbl = ['red' => 'Красные', 'black' => 'Чёрные', 'draw' => 'Ничья'];
    foreach ($games as $g): ?>
      <tr>
        <td><?= (int)$g['game_no'] ?></td>
        <td><?= $g['winner'] ? $winLbl[$g['winner']] : '—' ?></td>
        <td><?= esc($g['judge_nick'] ?? '—') ?></td>
        <td><?= $g['first_killed_seat'] ? 'место ' . (int)$g['first_killed_seat'] : 'промах' ?></td>
        <td>
          <a class="btn btn-ghost" style="padding:4px 10px;font-size:12px;" href="/admin/protocol.php?day=<?= $dayId ?>&game=<?= (int)$g['id'] ?>">Изменить</a>
          <form method="post" action="/admin/protocol.php?day=<?= $dayId ?>" style="display:inline;" onsubmit="return confirm('Удалить игру?');"><?= csrf_field() ?>
            <input type="hidden" name="form" value="delete_game"><input type="hidden" name="game_id" value="<?= (int)$g['id'] ?>">
            <button class="btn btn-ghost" style="padding:4px 10px;font-size:12px;color:var(--ac);" type="submit">Удалить</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>
<?php endif; ?>

<script>
(function () {
  // ── Таймер ──
  var disp = document.getElementById('tm-display');
  var remain = 30, timer = null, beep = null;
  function fmt(s) { var m = Math.floor(s / 60); var ss = s % 60; return m + ':' + (ss < 10 ? '0' : '') + ss; }
  function render() { disp.textContent = fmt(Math.max(0, remain)); disp.classList.toggle('tm-low', remain <= 5 && remain > 0); disp.classList.toggle('tm-zero', remain <= 0); }
  function stop() { if (timer) { clearInterval(timer); timer = null; } }
  function start(sec) {
    stop(); remain = sec; render();
    timer = setInterval(function () {
      remain--; render();
      if (remain <= 0) { stop(); try { beepSound(); } catch (e) {} }
    }, 1000);
  }
  function beepSound() {
    var ctx = new (window.AudioContext || window.webkitAudioContext)();
    var o = ctx.createOscillator(); var g = ctx.createGain();
    o.connect(g); g.connect(ctx.destination); o.frequency.value = 880; o.start();
    g.gain.setValueAtTime(0.3, ctx.currentTime); g.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.4);
    o.stop(ctx.currentTime + 0.4);
  }
  document.querySelectorAll('.tm-btn[data-sec]').forEach(function (b) {
    b.addEventListener('click', function () { start(parseInt(b.dataset.sec, 10)); });
  });
  document.getElementById('tm-add').addEventListener('click', function () { remain += 30; render(); if (!timer) start(remain); });
  document.getElementById('tm-stop').addEventListener('click', function () { stop(); remain = 0; render(); });
  render();

  // ── Живой подсчёт итога ──
  var RED = ['civ', 'sheriff'], BLACK = ['maf', 'don'];
  function roles() { var r = {}; document.querySelectorAll('tr[data-seat]').forEach(function (tr) { r[tr.dataset.seat] = tr.querySelector('.f-role').value; }); return r; }
  function bmBonus() {
    var rs = roles(), hits = 0, given = 0;
    ['bm1', 'bm2', 'bm3'].forEach(function (n) {
      var v = document.querySelector('[name=' + n + ']').value;
      if (v !== '0') { given++; if (BLACK.indexOf(rs[v]) >= 0) hits++; }
    });
    if (given === 0) return 0;
    return { 1: 0.1, 2: 0.3, 3: 0.6 }[hits] || 0;
  }
  function recompute() {
    var winner = document.getElementById('f-winner').value;
    var pu = document.getElementById('f-pu').value;
    var bonus = bmBonus();
    document.querySelectorAll('tr[data-seat]').forEach(function (tr) {
      var seat = tr.dataset.seat;
      var role = tr.querySelector('.f-role').value;
      var plus = parseFloat((tr.querySelector('.f-plus').value || '0').replace(',', '.')) || 0;
      var minus = parseFloat((tr.querySelector('.f-minus').value || '0').replace(',', '.')) || 0;
      var fouls = parseInt(tr.querySelector('.f-fouls').value, 10) || 0;
      var tech = parseInt(tr.querySelector('.f-tech').value, 10) || 0;
      var bigtech = parseInt((tr.querySelector('.f-bigtech') || {}).value, 10) || 0;
      var removal = parseInt((tr.querySelector('.f-removal') || {}).value, 10) || 0;
      var isPu = (pu === seat);
      var total = 0;
      if (winner === 'draw') {
        total = plus - minus + (isPu && RED.indexOf(role) >= 0 && bonus > 0 ? bonus : 0);
      } else if (winner === 'red' || winner === 'black') {
        var team = winner === 'black' ? BLACK : RED;
        total = (team.indexOf(role) >= 0 ? 1 : 0) + plus - minus;
        if (isPu && RED.indexOf(role) >= 0 && bonus > 0) total += bonus;
      } else {
        total = plus - minus;
      }
      if (fouls >= 4) total -= 0.6;
      total -= 0.3 * tech;
      total -= 0.6 * bigtech;
      if (removal === 1) total -= 0.6; else if (removal === 2) total -= 1.2;
      var nick = tr.querySelector('[name=nick' + seat + ']').value.trim();
      tr.querySelector('.f-total').textContent = nick ? (Math.round(total * 100) / 100) : '0';
    });
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
          var nick = ni ? ni.value.trim() : '';
          dopTarget.textContent = kind + ' · место ' + tr.dataset.seat + (nick ? ' · ' + nick : '');
        }
      });
    });
  }
  bindQuick('.f-plus', 'доп +');
  bindQuick('.f-minus', 'минус −');
  document.querySelectorAll('.dop-b').forEach(function (b) {
    b.addEventListener('mousedown', function (e) { e.preventDefault(); });
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
