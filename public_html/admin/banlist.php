<?php
require dirname(__DIR__, 2) . '/inc/bootstrap.php';
$me = require_role('admin'); // админ (3) и руководитель/owner (4)

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $pid = (int)($_POST['player_id'] ?? 0);
    if ($pid > 0 && $action === 'ban') {
        $reason = mb_substr(trim((string)($_POST['reason'] ?? '')), 0, 255);
        db()->prepare('UPDATE players SET banned_at = NOW(), ban_reason = ?, banned_by = ? WHERE id = ?')
            ->execute([$reason !== '' ? $reason : null, (int)$me['id'], $pid]);
        log_action((int)$me['id'], 'player_ban', ['player_id' => $pid, 'reason' => $reason]);
        flash_set('ok', 'Игрок забанен');
    } elseif ($pid > 0 && $action === 'unban') {
        db()->prepare('UPDATE players SET banned_at = NULL, ban_reason = NULL, banned_by = NULL WHERE id = ?')
            ->execute([$pid]);
        log_action((int)$me['id'], 'player_unban', ['player_id' => $pid]);
        flash_set('ok', 'Бан снят');
    }
    redirect('/admin/banlist.php' . (!empty($_POST['q']) ? '?q=' . urlencode((string)$_POST['q']) : ''));
}

$q = trim((string)($_GET['q'] ?? ''));
$found = [];
if ($q !== '') {
    $st = db()->prepare('SELECT id, nickname, avatar FROM players WHERE banned_at IS NULL AND nickname LIKE ? ORDER BY nickname LIMIT 20');
    $st->execute(['%' . like_escape($q) . '%']);
    $found = $st->fetchAll();
}
$banned = db()->query('SELECT p.id, p.nickname, p.avatar, p.banned_at, p.ban_reason, u.nickname AS by_nick
    FROM players p LEFT JOIN users u ON u.id = p.banned_by
    WHERE p.banned_at IS NOT NULL ORDER BY p.banned_at DESC')->fetchAll();
// Все никнеймы (не забаненные) — для подсказок в поиске
$allNicks = db()->query('SELECT nickname FROM players WHERE banned_at IS NULL ORDER BY nickname')->fetchAll(PDO::FETCH_COLUMN);

$inp = 'background:var(--sf2);color:var(--tx);border:1px solid var(--bd);border-radius:8px;padding:8px 10px;';

page_head('Бан-лист', '');
echo '<p><a href="/admin/">← Админка</a></p><h1>Бан-лист</h1>';
echo '<p style="color:var(--tx2);font-size:13px;margin-top:-6px;">Доступно администраторам и руководителю. Забаненные скрываются из списка игроков.</p>';

echo '<div class="card"><h2 style="margin-top:0;">Забанить игрока</h2>';
// Ники для подсказок, без мусорных (только из чёрточек/символов, напр. «-----»)
$sugNicks = array_values(array_filter($allNicks, fn($n) => preg_match('/[\p{L}\p{N}]/u', (string)$n)));
echo '<style>'
    . '#ban-search{position:relative;}'
    . '.ban-sug{position:absolute;left:0;right:0;top:100%;z-index:40;margin-top:4px;background:var(--sf2);'
    . 'border:1px solid var(--bd);border-radius:8px;max-height:300px;overflow-y:auto;box-shadow:0 10px 28px rgba(0,0,0,.45);}'
    . '.ban-sug-item{padding:9px 12px;cursor:pointer;color:var(--tx);border-bottom:1px solid var(--bd);font-size:14px;}'
    . '.ban-sug-item:last-child{border-bottom:none;}'
    . '.ban-sug-item.active,.ban-sug-item:hover{background:var(--acsf);}'
    . '</style>';
echo '<form method="get" action="/admin/banlist.php" id="ban-search" style="max-width:340px;margin-bottom:10px;">';
echo '<div class="field" style="margin:0;"><input type="search" name="q" id="ban-q" placeholder="Поиск по нику — начните вводить" value="' . esc($q) . '" autocomplete="off"></div>';
echo '<div id="ban-sug" class="ban-sug" style="display:none;"></div>';
echo '</form>';
echo '<script>var BAN_NICKS = ' . json_encode($sugNicks, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) . ';</script>';
echo <<<'JS'
<script>
(function(){
  var inp=document.getElementById('ban-q'),box=document.getElementById('ban-sug'),form=document.getElementById('ban-search');
  if(!inp||!box||!form) return;
  var nicks=window.BAN_NICKS||[], active=-1, items=[];
  function render(){
    var q=inp.value.trim().toLowerCase();
    box.innerHTML=''; items=[]; active=-1;
    if(q.length<1){ box.style.display='none'; return; }   // подсказки только при вводе
    var starts=[],contains=[];
    for(var i=0;i<nicks.length;i++){
      var low=(''+nicks[i]).toLowerCase();
      if(low.indexOf(q)===0) starts.push(nicks[i]);
      else if(low.indexOf(q)!==-1) contains.push(nicks[i]);
    }
    var list=starts.concat(contains).slice(0,12);
    if(!list.length){ box.style.display='none'; return; }
    list.forEach(function(n){
      var el=document.createElement('div');
      el.className='ban-sug-item'; el.textContent=n; el.dataset.nick=n;
      box.appendChild(el); items.push(el);
    });
    box.style.display='block';
  }
  function choose(el){ inp.value=el.dataset.nick; box.style.display='none'; form.submit(); }
  inp.addEventListener('input',render);
  inp.addEventListener('focus',function(){ if(inp.value.trim()) render(); });
  inp.addEventListener('keydown',function(e){
    if(box.style.display==='none'||!items.length) return;
    if(e.key==='ArrowDown'){ e.preventDefault(); active=(active+1)%items.length; }
    else if(e.key==='ArrowUp'){ e.preventDefault(); active=(active-1+items.length)%items.length; }
    else if(e.key==='Enter'&&active>=0){ e.preventDefault(); choose(items[active]); return; }
    else return;
    items.forEach(function(el,i){ el.classList.toggle('active',i===active); });
    items[active].scrollIntoView({block:'nearest'});
  });
  box.addEventListener('mousedown',function(e){
    var it=e.target.closest('.ban-sug-item'); if(!it) return; e.preventDefault(); choose(it);
  });
  document.addEventListener('click',function(e){ if(!form.contains(e.target)) box.style.display='none'; });
})();
</script>
JS;
if ($q !== '') {
    if ($found) {
        echo '<div class="admin-list">';
        foreach ($found as $f) {
            echo '<form method="post" class="admin-item" style="gap:10px;align-items:center;flex-wrap:wrap;">' . csrf_field()
                . '<input type="hidden" name="action" value="ban"><input type="hidden" name="player_id" value="' . (int)$f['id'] . '">'
                . '<input type="hidden" name="q" value="' . esc($q) . '">'
                . avatar_html($f, 30)
                . '<div style="flex:1;min-width:110px;"><a href="/player.php?id=' . (int)$f['id'] . '" style="color:var(--tx);">' . esc($f['nickname']) . '</a></div>'
                . '<input type="text" name="reason" placeholder="причина (необязательно)" style="flex:2;min-width:150px;' . $inp . '">'
                . '<button class="btn btn-ghost" style="color:var(--ac);" type="submit" onclick="return confirm(\'Забанить ' . esc(addslashes($f['nickname'])) . '?\');">Забанить</button>'
                . '</form>';
        }
        echo '</div>';
    } else {
        echo '<p style="color:var(--tx2);">Никого не нашлось среди незабаненных.</p>';
    }
}
echo '</div>';

echo '<div class="card"><h2 style="margin-top:0;">Забанены (' . count($banned) . ')</h2>';
if ($banned) {
    echo '<div class="admin-list">';
    foreach ($banned as $b) {
        $when = $b['banned_at'] ? date('d.m.Y', strtotime($b['banned_at'])) : '';
        $reason = $b['ban_reason'] ? esc($b['ban_reason']) : '<span style="color:var(--tx3);">без причины</span>';
        $by = $b['by_nick'] ? ' · ' . esc($b['by_nick']) : '';
        echo '<div class="admin-item" style="gap:10px;align-items:center;">'
            . avatar_html($b, 30)
            . '<div style="flex:1;min-width:120px;"><div class="nm"><a href="/player.php?id=' . (int)$b['id'] . '" style="color:var(--tx);">' . esc($b['nickname']) . '</a></div>'
            . '<div class="rl" style="color:var(--tx2);font-size:12px;">' . $reason . ' <span style="color:var(--tx3);">· ' . esc($when) . $by . '</span></div></div>'
            . '<form method="post">' . csrf_field()
            . '<input type="hidden" name="action" value="unban"><input type="hidden" name="player_id" value="' . (int)$b['id'] . '">'
            . '<button class="btn btn-ghost" type="submit">Снять бан</button></form>'
            . '</div>';
    }
    echo '</div>';
} else {
    echo '<p style="color:var(--tx2);">Забаненных нет.</p>';
}
echo '</div>';
page_foot();
