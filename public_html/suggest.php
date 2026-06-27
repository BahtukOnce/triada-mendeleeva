<?php
require dirname(__DIR__) . '/inc/bootstrap.php';

$u = require_login();
$player = current_player();
$myNick = $player['nickname'] ?? $u['nickname'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $body = trim((string)($_POST['body'] ?? ''));
    // картинки (загружены через Ctrl+V → /api/upload_suggestion_images.php), приходят как JSON-массив путей
    $imgUrls = [];
    if (!empty($_POST['image_urls'])) {
        $dec = json_decode((string)$_POST['image_urls'], true);
        if (is_array($dec)) {
            foreach ($dec as $url) {
                if (is_string($url) && preg_match('#^/uploads/suggestions/[A-Za-z0-9_.\-]+$#', $url)) {
                    $imgUrls[] = $url;
                }
            }
            $imgUrls = array_slice($imgUrls, 0, 5);
        }
    }
    if (mb_strlen($body) < 5) {
        flash_set('err', 'Опишите идею чуть подробнее (минимум 5 символов)');
    } elseif (mb_strlen($body) > 2000) {
        flash_set('err', 'Слишком длинно (максимум 2000 символов)');
    } else {
        db()->prepare('INSERT INTO suggestions (user_id, nickname, body, images) VALUES (?,?,?,?)')
            ->execute([(int)$u['id'], $myNick, $body, $imgUrls ? json_encode($imgUrls, JSON_UNESCAPED_UNICODE) : null]);
        log_action((int)$u['id'], 'suggestion_add', ['images' => count($imgUrls)]);
        flash_set('ok', 'Спасибо! Ваше предложение отправлено администрации клуба.');
        redirect('/suggest.php');
    }
    redirect('/suggest.php');
}

// Мои предложения
$st = db()->prepare('SELECT * FROM suggestions WHERE user_id = ? ORDER BY created_at DESC LIMIT 30');
$st->execute([(int)$u['id']]);
$mine = $st->fetchAll();

$statusLabel = ['new' => 'на рассмотрении', 'planned' => 'в планах', 'done' => 'сделано', 'declined' => 'отклонено'];
$statusTag = ['new' => '', 'planned' => 'tag-open', 'done' => 'tag-ok', 'declined' => ''];

page_head('Предложить идею', '');
echo '<h1>Предложения по сайту</h1>';
echo '<p style="color:var(--tx2);margin-top:-6px;">Есть идея, как улучшить сайт или клуб? Напишите — администрация увидит каждое предложение.</p>';

echo '<div class="card">';
echo '<form method="post" action="/suggest.php" id="suggest-form">' . csrf_field();
echo '<div class="field"><label>Ваша идея</label><textarea name="body" id="suggest-body" rows="5" required placeholder="Например: добавить статистику по месяцам, или…"></textarea></div>';
echo '<div style="font-size:12.5px;color:var(--tx2);margin:-4px 0 10px;">💡 Можно вставлять скриншоты прямо в поле — <b>Ctrl+V</b></div>';
echo '<div id="sg-thumbs" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px;"></div>';
echo '<input type="hidden" name="image_urls" id="sg-image-urls" value="">';
echo '<button class="btn" type="submit">Отправить</button>';
echo '</form></div>';

if ($mine) {
    echo '<div class="card"><h2 style="margin-top:0;">Мои предложения</h2>';
    foreach ($mine as $s) {
        echo '<div style="border-left:2px solid var(--bd);padding:4px 0 4px 12px;margin:10px 0;">';
        echo '<div style="display:flex;justify-content:space-between;gap:10px;align-items:center;">';
        echo '<span style="font-size:12px;color:var(--tx2);">' . date('d.m.Y', strtotime($s['created_at'])) . '</span>';
        echo '<span class="tag ' . $statusTag[$s['status']] . '">' . $statusLabel[$s['status']] . '</span></div>';
        echo '<div style="margin-top:4px;">' . nl2br(esc($s['body'])) . '</div>';
        if (!empty($s['images'])) {
            $imgs = json_decode((string)$s['images'], true);
            if (is_array($imgs) && $imgs) {
                echo '<div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:6px;">';
                foreach ($imgs as $iu) {
                    if (is_string($iu) && strncmp($iu, '/uploads/', 9) === 0) {
                        echo '<a href="' . esc($iu) . '" target="_blank" rel="noopener"><img src="' . esc($iu) . '" alt="" loading="lazy" style="width:90px;height:90px;object-fit:cover;border-radius:6px;border:1px solid var(--bd);"></a>';
                    }
                }
                echo '</div>';
            }
        }
        if ($s['admin_note']) {
            echo '<div style="margin-top:6px;font-size:13px;color:var(--ac);">Ответ: ' . esc($s['admin_note']) . '</div>';
        }
        echo '</div>';
    }
    echo '</div>';
}
?>
<script>
(function () {
  var form = document.getElementById('suggest-form');
  if (!form) return;
  var ta = document.getElementById('suggest-body');
  var thumbs = document.getElementById('sg-thumbs');
  var hidden = document.getElementById('sg-image-urls');
  var csrfEl = form.querySelector('input[name=csrf]');
  var csrf = csrfEl ? csrfEl.value : '';
  var urls = [], MAX = 5;
  function render() {
    thumbs.innerHTML = '';
    urls.forEach(function (u, i) {
      var w = document.createElement('div');
      w.style.cssText = 'position:relative;width:84px;height:84px;border-radius:8px;overflow:hidden;border:1px solid var(--bd);';
      var img = document.createElement('img');
      img.src = u; img.style.cssText = 'width:100%;height:100%;object-fit:cover;';
      w.appendChild(img);
      var x = document.createElement('button');
      x.type = 'button'; x.textContent = '✕';
      x.style.cssText = 'position:absolute;top:2px;right:2px;width:20px;height:20px;line-height:18px;padding:0;border:none;border-radius:4px;background:rgba(0,0,0,.6);color:#fff;cursor:pointer;font-size:12px;';
      x.onclick = function () { urls.splice(i, 1); sync(); };
      w.appendChild(x);
      thumbs.appendChild(w);
    });
  }
  function sync() { hidden.value = JSON.stringify(urls); render(); }
  function upload(file) {
    if (urls.length >= MAX) { alert('Максимум ' + MAX + ' изображений'); return; }
    if (['image/jpeg', 'image/png', 'image/webp'].indexOf(file.type) < 0) { alert('Только JPG, PNG или WebP'); return; }
    if (file.size > 15 * 1024 * 1024) { alert('Файл больше 15 МБ'); return; }
    var ph = document.createElement('div');
    ph.textContent = '…';
    ph.style.cssText = 'width:84px;height:84px;border-radius:8px;border:1px dashed var(--bd);display:flex;align-items:center;justify-content:center;color:var(--tx2);';
    thumbs.appendChild(ph);
    var fd = new FormData();
    fd.append('image', file); fd.append('csrf', csrf);
    fetch('/api/upload_suggestion_images.php', { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d && d.ok && d.url) { urls.push(d.url); }
        else { alert('Не удалось загрузить: ' + ((d && d.error) || 'ошибка')); }
        sync();
      })
      .catch(function () { alert('Ошибка загрузки изображения'); sync(); });
  }
  ta.addEventListener('paste', function (e) {
    var items = (e.clipboardData || window.clipboardData).items;
    if (!items) return;
    for (var i = 0; i < items.length; i++) {
      if (items[i].kind === 'file') {
        var f = items[i].getAsFile();
        if (f && f.type.indexOf('image/') === 0) { e.preventDefault(); upload(f); }
      }
    }
  });
})();
</script>
<?php
page_foot();
