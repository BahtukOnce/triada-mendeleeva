<?php
// Шаблон конфигурации. Скопируйте файл как config.php в КОРЕНЬ сайта
// (рядом с папкой public_html, НЕ внутри неё) и заполните значения.
// config.php в git не попадает.
return [
    // Окружение: test — тестовый контур (закрыт от поисковиков), prod — боевой
    'env'      => 'test',
    'base_url' => 'https://test.triada-mendeleeva.ru',

    // Доступы к MySQL (создаются в панели Beget → MySQL)
    'db' => [
        'host' => 'localhost',
        'name' => 'ИМЯ_БД',
        'user' => 'ИМЯ_БД', // на Beget совпадает с именем БД
        'pass' => 'ПАРОЛЬ_БД',
    ],

    // Секрет GitHub-вебхука (тот же, что указан в настройках webhook на GitHub)
    'deploy_secret' => 'СЕКРЕТ_ВЕБХУКА',
    // Какая ветка деплоится в этот каталог
    'deploy_branch' => 'test',

    // Ник, который при регистрации получает роль главы клуба (пока главы нет)
    'owner_nickname' => 'Бант.',

    // Telegram-бот (вебхук). Токен от @BotFather; секрет — любая длинная случайная строка
    // (Telegram шлёт его в заголовке X-Telegram-Bot-Api-Secret-Token, бот сверяет).
    // Вебхук ставится один раз: /setup_webhook.php?key=<deploy_secret>&go=1
    'bot_token'  => '',
    'bot_secret' => '',
];
