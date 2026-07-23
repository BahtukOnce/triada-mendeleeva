<?php
/**
 * Скачивание приложения: всегда отдаёт ПОСЛЕДНИЙ опубликованный APK.
 * GitHub Releases держит стабильный permalink /releases/latest/download/<asset>,
 * который сам редиректит на файл актуального релиза — поэтому здесь просто 302.
 * Standalone (без bootstrap), чтобы не попадать под app_require_login и работать всем.
 */
$url = 'https://github.com/BahtukOnce/triada-mendeleeva/releases/latest/download/triada-mendeleeva.apk';
header('Location: ' . $url, true, 302);
header('Cache-Control: no-store');
echo 'Загрузка приложения… Если не началась — <a href="' . htmlspecialchars($url, ENT_QUOTES) . '">нажмите сюда</a>.';
