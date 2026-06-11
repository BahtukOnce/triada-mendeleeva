<?php
require dirname(__DIR__) . '/inc/bootstrap.php';

http_response_code(404);
page_head('Страница не найдена', '');
empty_state('404 — страница не найдена', 'Возможно, ссылка устарела. Загляните на главную или в навигацию сверху.');
echo '<p style="text-align:center;"><a class="btn btn-ghost" href="/index.php">На главную</a></p>';
page_foot();
