<?php
require dirname(__DIR__) . '/inc/bootstrap.php';

if (current_user()) {
    redirect('/cabinet.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $res = auth_register(
        (string)($_POST['nickname'] ?? ''),
        (string)($_POST['password'] ?? ''),
        (string)($_POST['password2'] ?? '')
    );
    if (is_array($res)) {
        $msg = $res['role'] === 'owner'
            ? 'Аккаунт создан. Вы — руководитель клуба.'
            : 'Аккаунт создан. Добро пожаловать в клуб!';
        if (($res['linked'] ?? '') === 'pending') {
            $msg .= ' Ваш ник найден в истории игр — статистика подтянется после подтверждения админом.';
        }
        flash_set('ok', $msg);
        redirect('/cabinet.php');
    }
    flash_set('err', $res);
    redirect('/register.php');
}

page_head('Регистрация', '');
?>
<div class="form-narrow">
  <h1 style="text-align:center;">Регистрация</h1>
  <div class="form-card">
    <form method="post" action="/register.php">
      <?= csrf_field() ?>
      <div class="field">
        <label for="nickname">Игровой ник</label>
        <input id="nickname" name="nickname" type="text" required autocomplete="username"
               placeholder="как вас называют за столом">
      </div>
      <div class="field">
        <label for="password">Пароль <span style="color:var(--tx2);">(минимум 6 символов)</span></label>
        <input id="password" name="password" type="password" required autocomplete="new-password">
      </div>
      <div class="field">
        <label for="password2">Пароль ещё раз</label>
        <input id="password2" name="password2" type="password" required autocomplete="new-password">
      </div>
      <button class="btn btn-block" type="submit">Зарегистрироваться</button>
    </form>
  </div>
  <div class="form-foot">Уже есть аккаунт? <a href="/login.php">Войти</a></div>
</div>
<?php page_foot(); ?>
