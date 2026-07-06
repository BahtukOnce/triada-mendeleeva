<?php
// Временный эндпоинт для проверки стилизованной страницы ошибки. Удаляется сразу после.
require dirname(__DIR__) . '/inc/bootstrap.php';
throw new RuntimeException('errtest — проверка оформления страницы ошибки');
