<?php
require dirname(__DIR__) . '/inc/bootstrap.php';

$dbok = db_ready();
$nextDay = null;
$regCount = 0;
$stats = ['players' => 0, 'games' => 0, 'days' => 0, 'tournaments' => 0];
$balance = ['red' => 0, 'black' => 0, 'draw' => 0];
$top5 = [];
$news = [];
$admins = [];

if ($dbok) {
    try {
        $st = db()->query("SELECT * FROM game_days
            WHERE status IN ('reg_open','reg_closed','live') AND date >= CURDATE() - INTERVAL 1 DAY
            ORDER BY date LIMIT 1");
        $nextDay = $st->fetch() ?: null;
        if ($nextDay) {
            $st = db()->prepare('SELECT COUNT(*) FROM day_registrations WHERE day_id = ? AND cancelled_at IS NULL');
            $st->execute([$nextDay['id']]);
            $regCount = (int)$st->fetchColumn();
        }
        $stats['players']     = (int)db()->query('SELECT COUNT(*) FROM players')->fetchColumn();
        $stats['games']       = (int)db()->query("SELECT COUNT(*) FROM games WHERE status = 'finished'")->fetchColumn();
        $stats['days']        = (int)db()->query('SELECT COUNT(*) FROM game_days')->fetchColumn();
        $stats['tournaments'] = (int)db()->query('SELECT COUNT(*) FROM tournaments')->fetchColumn();
        $news = db()->query('SELECT id, title, published_at FROM news
            WHERE published_at IS NOT NULL ORDER BY pinned DESC, published_at DESC LIMIT 3')->fetchAll();
        $mainId = (int)db()->query('SELECT id FROM ratings WHERE is_main = 1 LIMIT 1')->fetchColumn();
        $top5 = [];
        if ($mainId) {
            $st = db()->prepare('SELECT rc.player_id, rc.club_score, rc.sum_total, p.nickname, p.avatar, p.flair, p.elo
                FROM rating_cache rc JOIN players p ON p.id = rc.player_id
                WHERE rc.rating_id = ? AND rc.club_score IS NOT NULL
                ORDER BY rc.club_score DESC LIMIT 5');
            $st->execute([$mainId]);
            $top5 = $st->fetchAll();
        }
        foreach (db()->query("SELECT winner, COUNT(*) c FROM games WHERE status = 'finished' GROUP BY winner")->fetchAll() as $b) {
            if (isset($balance[$b['winner']])) {
                $balance[$b['winner']] = (int)$b['c'];
            }
        }
        $admins = db()->query("SELECT u.nickname, u.role, u.is_judge, u.is_photographer, p.id AS player_id, p.avatar
            FROM users u LEFT JOIN players p ON p.user_id = u.id
            WHERE u.role IN ('owner','admin') OR u.is_judge = 1 OR u.is_photographer = 1
            ORDER BY FIELD(u.role,'owner','admin','player'), u.is_judge DESC, u.nickname LIMIT 30")->fetchAll();
    } catch (Throwable $e) {
        // каркас не падает из-за БД
    }
}

page_head('Главная', 'index');
?>

<?php if (!current_user()): ?>
<section class="guest-hero">
  <div class="guest-hero-inner">
    <div class="guest-logo"><?= logo_svg(72) ?></div>
    <h1 class="guest-title">Триада Менделеева</h1>
    <p class="guest-sub">Клуб спортивной мафии РХТУ. Игровые вечера, турниры, клубный рейтинг
      с подробной статистикой по каждому игроку.</p>
    <div class="guest-cta">
      <a class="btn" href="/register.php">Присоединиться к клубу</a>
      <a class="btn btn-ghost" href="/login.php">Войти</a>
    </div>
    <div class="guest-stats">
      <a href="/players.php"><b><?= $stats['players'] ?></b><span>игроков</span></a>
      <a href="/rating.php"><b><?= $stats['games'] ?></b><span>игр сыграно</span></a>
      <a href="/days.php"><b><?= $stats['days'] ?></b><span>вечеров</span></a>
      <a href="/tournaments.php"><b><?= $stats['tournaments'] ?></b><span>турниров</span></a>
    </div>
    <p class="guest-hint">Уже играли в клубе? Зарегистрируйтесь под своим ником — вся ваша
      статистика и история игр подтянутся автоматически.</p>
    <div class="guest-social">
      <a href="https://t.me/triada_mendeleeva" rel="noopener" target="_blank">Telegram</a>
      <a href="https://vk.com/triada_mendeleev" rel="noopener" target="_blank">VK</a>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if ($nextDay): ?>
<div class="card card-accent hero">
  <div class="hero-info">
    <span class="tag <?= $nextDay['status'] === 'reg_open' ? 'tag-open' : '' ?>">
      <?= $nextDay['status'] === 'reg_open' ? 'запись открыта' : 'игровой вечер' ?>
    </span>
    <div class="hero-date"><?= esc($nextDay['title']) ?> · <?= esc(date('d.m.Y', strtotime($nextDay['date']))) ?></div>
    <div class="hero-place"><?= esc($nextDay['location'] ?: 'место уточняется') ?></div>
    <?php if ($regCount > 0): ?>
      <div style="margin-top:10px;display:flex;align-items:center;gap:10px;max-width:300px;">
        <div class="progress" style="flex:1;"><div style="width:<?= min(100, $regCount * 10) ?>%"></div></div>
        <span style="font-size:12.5px;color:var(--tx2);">записалось: <?= $regCount ?></span>
      </div>
    <?php endif; ?>
  </div>
  <a class="btn" href="/cabinet.php">Записаться</a>
</div>
<?php else: ?>
<div class="card card-accent hero">
  <div class="hero-info">
    <span class="tag">игровой вечер</span>
    <div class="hero-date">Ближайший вечер пока не объявлен</div>
    <div class="hero-place">Анонс появится здесь и в новостях</div>
  </div>
</div>
<?php endif; ?>

<div class="grid-stats">
  <a class="stat" href="/players.php"><div class="lbl">игроков</div><div class="val"><?= $stats['players'] ?></div></a>
  <a class="stat" href="/rating.php"><div class="lbl">сыграно игр</div><div class="val"><?= $stats['games'] ?></div></a>
  <a class="stat" href="/days.php"><div class="lbl">вечеров</div><div class="val"><?= $stats['days'] ?></div></a>
  <a class="stat" href="/tournaments.php"><div class="lbl">турниров</div><div class="val"><?= $stats['tournaments'] ?></div></a>
</div>

<div class="grid-2">
  <div>
  <?php if (!empty($top5)): ?>
  <div class="card">
    <div class="section-head">
      <h2 style="margin-top:0;">Рейтинг — топ 5</h2>
      <a class="more" href="/rating.php">весь рейтинг →</a>
    </div>
    <table class="tbl row-link">
      <tr><th>#</th><th>Игрок</th><th class="num">~Σ×Σ</th><th class="num">ELO</th></tr>
      <?php $pos = 0; foreach ($top5 as $t5): $pos++;
          $medal = $pos === 1 ? '🥇' : ($pos === 2 ? '🥈' : ($pos === 3 ? '🥉' : '')); ?>
      <tr data-href="/player.php?id=<?= (int)$t5['player_id'] ?>"<?= $medal !== '' ? ' class="rt-top"' : '' ?>>
        <td><?= $medal !== '' ? '<span style="font-size:15px;">' . $medal . '</span>' : $pos ?></td>
        <td><?= avatar_html(['nickname' => $t5['nickname'], 'avatar' => $t5['avatar']], 26, 'margin-right:8px;') ?><span style="vertical-align:middle;"><?= player_label($t5) ?></span></td>
        <td class="num"><b><?= number_format((float)$t5['club_score'], 1) ?></b></td>
        <td class="num"><?= number_format((float)$t5['elo'], 0, '.', '') ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>
  <?php endif; ?>
  <?php $balTot = $balance['red'] + $balance['black'] + $balance['draw'];
  if ($balTot > 0):
      $rp = round($balance['red'] / $balTot * 100);
      $bp = round($balance['black'] / $balTot * 100);
      $dp = 100 - $rp - $bp; ?>
  <div class="card">
    <h2 style="margin-top:0;">Баланс игр клуба <span style="font-size:12px;color:var(--tx2);font-weight:400;">(<?= $balTot ?> игр)</span></h2>
    <div class="bal-bar">
      <span style="width:<?= $rp ?>%;background:#c0392b;"></span>
      <span style="width:<?= $bp ?>%;background:#33333c;"></span>
      <span style="width:<?= $dp ?>%;background:var(--tx3);"></span>
    </div>
    <div class="bal-legend">
      <span><i style="background:#c0392b;"></i>Красные победили <b><?= $balance['red'] ?></b> (<?= $rp ?>%)</span>
      <span><i style="background:#33333c;"></i>Чёрные <b><?= $balance['black'] ?></b> (<?= $bp ?>%)</span>
      <?php if ($balance['draw'] > 0): ?><span><i style="background:var(--tx3);"></i>Ничьи <b><?= $balance['draw'] ?></b></span><?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
  <div class="card">
    <div class="section-head">
      <h2 style="margin-top:0;">Новости</h2>
      <a class="more" href="/news.php">все новости →</a>
    </div>
    <?php if ($news): $first = true; foreach ($news as $n): ?>
      <a class="news-item<?= $first ? ' first' : '' ?>" href="/news.php?id=<?= (int)$n['id'] ?>" style="display:block;text-decoration:none;">
        <div class="ttl" style="color:var(--tx);"><?= esc($n['title']) ?></div>
        <div class="dt"><?= esc(date('d.m.Y', strtotime($n['published_at']))) ?></div>
      </a>
    <?php $first = false; endforeach; else: ?>
      <p style="color:var(--tx2);font-size:14px;">Новостей пока нет — скоро появятся.</p>
    <?php endif; ?>
  </div>
  </div>

  <?php $about = db_ready() ? trim(setting('about_text')) : ''; ?>
  <?php if ($about !== ''): ?>
  <div class="card">
    <h2 style="margin-top:0;">О клубе</h2>
    <div style="line-height:1.7;color:var(--tx2);"><?= nl2br(esc($about)) ?></div>
  </div>
  <?php endif; ?>

  <div class="card">
    <h2 style="margin-top:0;">Администрация клуба</h2>
    <?php if ($admins): ?>
      <div class="admin-list">
        <?php foreach ($admins as $a):
            $isLead = $a['role'] === 'owner' || $a['role'] === 'admin';
            $label = implode(' · ', user_role_badges($a));
            $nameHtml = $a['player_id']
                ? '<a href="/player.php?id=' . (int)$a['player_id'] . '" style="color:var(--tx);">' . esc($a['nickname']) . '</a>'
                : esc($a['nickname']); ?>
          <div class="admin-item">
            <?= avatar_html(['nickname' => $a['nickname'], 'avatar' => $a['avatar']], 30, $isLead ? 'background:var(--acsf);color:var(--ac);' : '') ?>
            <div>
              <div class="nm"><?= $nameHtml ?></div>
              <div class="rl<?= $isLead ? ' accent' : '' ?>"><?= esc($label) ?></div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <p style="color:var(--tx2);font-size:14px;">Состав появится после назначения ролей.</p>
    <?php endif; ?>
  </div>
</div>

<?php page_foot(); ?>
