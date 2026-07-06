<?php
require dirname(__DIR__) . '/inc/bootstrap.php';

http_response_code(404);
page_head('Страница не найдена', '');
empty_state('404 — страница не найдена', 'Возможно, ссылка устарела. Загляните на главную или в навигацию сверху.');
$tg = ltrim((string)cfg('contact_tg', 'triada_mendeleeva'), '@');
echo '<p style="text-align:center;display:flex;gap:10px;justify-content:center;flex-wrap:wrap;">'
    . '<a class="btn" href="/index.php">На главную</a>'
    . '<a class="btn btn-ghost" href="https://t.me/' . esc($tg) . '" target="_blank" rel="noopener">Написать руководителю</a></p>';
page_foot();
