<?php
require dirname(__DIR__) . '/inc/bootstrap.php';

// Внутри самого приложения эта страница ни к чему — уводим на главную.
if (is_app()) {
    redirect('/index.php');
}

page_head('Приложение для Android', '', [
    'title_tag'   => 'Приложение «Триада Менделеева» для Android — скачать APK',
    'description' => 'Официальное приложение клуба «Триада Менделеева» для Android: тот же сайт как приложение — иконка на экране, полноэкранный режим, обновления внутри.',
]);

$android = '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true"><path d="M17.6 9.48l1.84-3.18a.4.4 0 0 0-.15-.55.4.4 0 0 0-.54.15l-1.87 3.23A11.4 11.4 0 0 0 12 8c-1.73 0-3.36.4-4.88 1.13L5.25 5.9a.4.4 0 0 0-.54-.15.4.4 0 0 0-.15.55L6.4 9.48A10.8 10.8 0 0 0 1 18h22a10.8 10.8 0 0 0-5.4-8.52zM7 15.25a1.25 1.25 0 1 1 0-2.5 1.25 1.25 0 0 1 0 2.5zm10 0a1.25 1.25 0 1 1 0-2.5 1.25 1.25 0 0 1 0 2.5z"/></svg>';
?>
<style>
  .app-dl { max-width: 560px; margin: 8px auto 0; text-align: center; }
  .app-dl .app-dl-logo { width: 96px; margin: 4px auto 10px; }
  .app-dl h1 { margin: 6px 0 4px; }
  .app-dl-sub { color: var(--tx2); margin: 0 auto 22px; max-width: 460px; }
  .app-dl-btn { display: inline-flex; align-items: center; gap: 10px; font-size: 17px; font-weight: 700;
    padding: 14px 26px; border-radius: 14px; }
  .app-dl-hint { color: var(--tx3, var(--tx2)); font-size: 13px; margin: 14px 0 0; }
  .app-dl-hint b { color: var(--tx); }
  .app-dl-card { text-align: left; background: var(--sf); border: 1px solid var(--bd); border-radius: 14px;
    padding: 18px 20px; margin: 26px 0 0; }
  .app-dl-card h3 { margin: 0 0 10px; font-size: 15px; }
  .app-dl-card ol { margin: 0; padding-left: 20px; color: var(--tx2); line-height: 1.7; }
  .app-dl-card ol b { color: var(--tx); }
  .app-dl-note { color: var(--tx2); font-size: 13px; margin-top: 18px; }
  .app-dl-note b { color: var(--ac); }
</style>
<div class="app-dl">
  <div class="app-dl-logo"><?= logo_svg(96) ?></div>
  <h1>Приложение для Android</h1>
  <p class="app-dl-sub">Тот же клуб, но как приложение: своя иконка на экране, полноэкранный режим, нижняя навигация и обновления прямо внутри — без Google Play.</p>

  <a class="btn app-dl-btn" href="/app/download.php" rel="nofollow"><?= $android ?> Скачать APK</a>

  <p class="app-dl-hint">С компьютера — открой эту страницу на телефоне: <b>triada-mendeleeva.ru/app.php</b></p>

  <div class="app-dl-card">
    <h3>Как установить</h3>
    <ol>
      <li>Нажми <b>«Скачать APK»</b> — файл загрузится в «Загрузки».</li>
      <li>Открой скачанный файл <b>triada-mendeleeva.apk</b>.</li>
      <li>Android попросит <b>разрешить установку из этого источника</b> — разреши (это нормально для приложений вне Google Play).</li>
      <li>Нажми <b>«Установить»</b> — иконка «Триада» появится на экране.</li>
    </ol>
  </div>

  <p class="app-dl-note">Дальше обновляться проще некуда: когда выйдет новая версия, приложение само предложит <b>«Обновить»</b> и скачает её внутри себя.</p>
</div>
<?php
page_foot();
