<?php
require dirname(__DIR__) . '/inc/bootstrap.php';

$dbok = db_ready();
$nextDay = null;
$regCount = 0;
$stats = ['players' => 0, 'games' => 0, 'days' => 0, 'tournaments' => 0];
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
        $admins = db()->query("SELECT nickname, role FROM users
            WHERE role IN ('owner','admin')
            ORDER BY FIELD(role,'owner','admin'), nickname LIMIT 12")->fetchAll();
    } catch (Throwable $e) {
        // каркас не падает из-за БД
    }
}

page_head('Главная', 'index');
?>

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
  <div class="stat"><div class="lbl">игроков</div><div class="val"><?= $stats['players'] ?></div></div>
  <div class="stat"><div class="lbl">сыграно игр</div><div class="val"><?= $stats['games'] ?></div></div>
  <div class="stat"><div class="lbl">вечеров</div><div class="val"><?= $stats['days'] ?></div></div>
  <div class="stat"><div class="lbl">турниров</div><div class="val"><?= $stats['tournaments'] ?></div></div>
</div>

<div class="grid-2">
  <div class="card">
    <div class="section-head">
      <h2 style="margin-top:0;">Новости</h2>
      <a class="more" href="/news.php">все новости →</a>
    </div>
    <?php if ($news): $first = true; foreach ($news as $n): ?>
      <div class="news-item<?= $first ? ' first' : '' ?>">
        <div class="ttl"><a href="/news.php?id=<?= (int)$n['id'] ?>" style="color:var(--tx);"><?= esc($n['title']) ?></a></div>
        <div class="dt"><?= esc(date('d.m.Y', strtotime($n['published_at']))) ?></div>
      </div>
    <?php $first = false; endforeach; else: ?>
      <p style="color:var(--tx2);font-size:14px;">Новостей пока нет — скоро появятся.</p>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2 style="margin-top:0;">Администрация клуба</h2>
    <?php if ($admins): ?>
      <div class="admin-list">
        <?php foreach ($admins as $a):
            $isOwner = $a['role'] === 'owner';
            $letter = mb_strtoupper(mb_substr($a['nickname'], 0, 1)); ?>
          <div class="admin-item">
            <span class="avatar-circle<?= $isOwner ? ' accent' : '' ?>"><?= esc($letter) ?></span>
            <div>
              <div class="nm"><?= esc($a['nickname']) ?></div>
              <div class="rl<?= $isOwner ? ' accent' : '' ?>"><?= esc(role_label($a['role'])) ?></div>
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
