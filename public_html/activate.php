<?php
// Активация аккаунта по ссылке из принятой заявки: новичок задаёт пароль → создаётся
// аккаунт, привязанный к его игроку. Часть единого потока «заявка → аккаунт».
require dirname(__DIR__) . '/inc/bootstrap.php';

if (current_user()) {
    redirect('/cabinet.php');
}

$token = trim((string)($_REQUEST['token'] ?? ''));
$app = null;
$player = null;
$err = '';

if ($token !== '') {
    $st = db()->prepare("SELECT * FROM club_applications WHERE activation_token = ? AND state = 'approved' AND activated_at IS NULL");
    $st->execute([$token]);
    $app = $st->fetch() ?: null;
    if ($app && $app['player_id']) {
        $ps = db()->prepare('SELECT * FROM players WHERE id = ?');
        $ps->execute([(int)$app['player_id']]);
        $player = $ps->fetch() ?: null;
    }
}
if (!$app || !$player) {
    $err = 'Ссылка активации недействительна или уже использована.';
} elseif (!empty($player['user_id'])) {
    $err = 'Этот аккаунт уже активирован — просто войдите.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $err === '') {
    csrf_check();
    $p1 = (string)($_POST['password'] ?? '');
    $p2 = (string)($_POST['password2'] ?? '');
    if (mb_strlen($p1) < 6) {
        flash_set('err', 'Пароль — минимум 6 символов');
        redirect('/activate.php?token=' . urlencode($token));
    }
    if ($p1 !== $p2) {
        flash_set('err', 'Пароли не совпадают');
        redirect('/activate.php?token=' . urlencode($token));
    }
    $nick = (string)$player['nickname'];
    $c = db()->prepare('SELECT id FROM users WHERE LOWER(nickname) = LOWER(?)');
    $c->execute([$nick]);
    if ($c->fetch()) {
        flash_set('err', 'Аккаунт с таким ником уже существует — войдите.');
        redirect('/login.php');
    }
    $pdo = db();
    $pdo->beginTransaction();
    $pdo->prepare('INSERT INTO users (nickname, password_hash, role) VALUES (?,?,?)')
        ->execute([$nick, password_hash($p1, PASSWORD_DEFAULT), 'player']);
    $uid = (int)$pdo->lastInsertId();
    $pdo->prepare('UPDATE players SET user_id = ? WHERE id = ? AND user_id IS NULL')->execute([$uid, (int)$player['id']]);
    $pdo->prepare('UPDATE club_applications SET activated_at = NOW() WHERE id = ?')->execute([(int)$app['id']]);
    $pdo->commit();
    $_SESSION['uid'] = $uid;
    log_action($uid, 'account_activated', ['player_id' => (int)$player['id'], 'application' => (int)$app['id']]);
    flash_set('ok', 'Аккаунт создан. Добро пожаловать в клуб! 🎉');
    redirect('/cabinet.php');
}

page_head('Активация аккаунта', '');
echo '<div class="form-narrow"><h1 style="text-align:center;">Активация аккаунта</h1>';
if ($err !== '') {
    echo '<div class="form-card"><p style="color:var(--tx2);text-align:center;margin:0 0 14px;">' . esc($err) . '</p>'
        . '<p style="text-align:center;margin:0;"><a class="btn" href="/login.php">Войти</a></p></div>';
} else {
    echo '<div class="form-card">';
    echo '<p style="color:var(--tx2);text-align:center;margin-top:0;line-height:1.6;">Заявка принята! 🎉<br>Задайте пароль для входа под ником <b style="color:var(--tx);">' . esc($player['nickname']) . '</b>.</p>';
    echo '<form method="post" action="/activate.php?token=' . esc($token) . '">' . csrf_field();
    echo '<div class="field"><label>Пароль <span style="color:var(--tx2);">(минимум 6 символов)</span></label>'
        . '<input type="password" name="password" required autocomplete="new-password"></div>';
    echo '<div class="field"><label>Пароль ещё раз</label>'
        . '<input type="password" name="password2" required autocomplete="new-password"></div>';
    echo '<button class="btn btn-block" type="submit">Создать аккаунт и войти</button>';
    echo '</form></div>';
}
echo '</div>';
page_foot();
