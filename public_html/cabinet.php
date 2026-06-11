<?php
require dirname(__DIR__) . '/inc/bootstrap.php';

$u = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'password') {
    csrf_check();
    $old = (string)($_POST['old_password'] ?? '');
    $new1 = (string)($_POST['new_password'] ?? '');
    $new2 = (string)($_POST['new_password2'] ?? '');

    if (!password_verify($old, $u['password_hash'])) {
        flash_set('err', 'Текущий пароль неверный');
    } elseif (mb_strlen($new1) < 6) {
        flash_set('err', 'Новый пароль — минимум 6 символов');
    } elseif ($new1 !== $new2) {
        flash_set('err', 'Новые пароли не совпадают');
    } else {
        db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($new1, PASSWORD_DEFAULT), $u['id']]);
        log_action((int)$u['id'], 'password_change');
        flash_set('ok', 'Пароль обновлён');
    }
    redirect('/cabinet.php');
}

page_head('Личный кабинет', '');
?>
<h1>Личный кабинет</h1>

<div class="grid-2">
  <div class="card">
    <h2 style="margin-top:0;">Профиль</h2>
    <table class="tbl">
      <tr><td style="color:var(--tx2);width:40%;">Ник</td><td><?= esc($u['nickname']) ?></td></tr>
      <tr><td style="color:var(--tx2);">Роль</td><td><?= esc(role_label($u['role'])) ?></td></tr>
      <tr><td style="color:var(--tx2);">В клубе с</td><td><?= esc(date('d.m.Y', strtotime($u['created_at']))) ?></td></tr>
    </table>
    <p style="color:var(--tx2);font-size:13px;margin-bottom:0;">
      Аватар, анкета (ФИО, Telegram, дата рождения, факультет) и привязка игровой статистики появятся на этапе 3.
    </p>
  </div>

  <div class="card">
    <h2 style="margin-top:0;">Сменить пароль</h2>
    <form method="post" action="/cabinet.php">
      <?= csrf_field() ?>
      <input type="hidden" name="form" value="password">
      <div class="field">
        <label for="old_password">Текущий пароль</label>
        <input id="old_password" name="old_password" type="password" required autocomplete="current-password">
      </div>
      <div class="field">
        <label for="new_password">Новый пароль</label>
        <input id="new_password" name="new_password" type="password" required autocomplete="new-password">
      </div>
      <div class="field">
        <label for="new_password2">Новый пароль ещё раз</label>
        <input id="new_password2" name="new_password2" type="password" required autocomplete="new-password">
      </div>
      <button class="btn" type="submit">Сохранить</button>
    </form>
  </div>
</div>
<?php page_foot(); ?>
