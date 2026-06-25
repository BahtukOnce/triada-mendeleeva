<?php
require dirname(__DIR__) . '/inc/bootstrap.php';

if (current_user()) {
    redirect('/cabinet.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $res = auth_login((string)($_POST['nickname'] ?? ''), (string)($_POST['password'] ?? ''));
    if (is_array($res)) {
        $nick = $res['nickname'];
        $greetings = [
            'Тихо! Мафия проснулась… а, это просто ' . $nick . '.',
            'Город просыпается. С возвращением, ' . $nick . '!',
            'Ночь была долгой. Рад видеть, ' . $nick . '.',
            $nick . ', твой выход — город ждёт.',
            'Добро пожаловать за стол, ' . $nick . '.',
            'Маска надета, ' . $nick . '. Поехали.',
            'Раздаём роли, ' . $nick . '. Ты в игре.',
        ];
        flash_set('ok', $greetings[array_rand($greetings)]);
        redirect('/index.php');
    }
    flash_set('err', $res);
    redirect('/login.php');
}

page_head('Вход', '');
?>
<div class="form-narrow">
  <h1 style="text-align:center;">Вход</h1>
  <div class="form-card">
    <form method="post" action="/login.php">
      <?= csrf_field() ?>
      <div class="field">
        <label for="nickname">Игровой ник</label>
        <input id="nickname" name="nickname" type="text" required autocomplete="username"
               value="<?= esc($_GET['nick'] ?? '') ?>">
      </div>
      <div class="field">
        <label for="password">Пароль</label>
        <input id="password" name="password" type="password" required autocomplete="current-password">
      </div>
      <button class="btn btn-block" type="submit">Войти</button>
    </form>
  </div>
  <div class="form-foot">Нет аккаунта? <a href="/register.php">Зарегистрироваться</a></div>
  <div class="form-foot" style="font-size:12.5px;">Забыли пароль? Его сбросит любой администратор клуба — обратитесь к ним.</div>
</div>
<?php page_foot(); ?>
