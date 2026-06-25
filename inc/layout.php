<?php
declare(strict_types=1);

function nav_items(bool $authed = true): array
{
    $items = [
        'index'       => ['index.php', 'Главная'],
        'news'        => ['news.php', 'Новости'],
        'days'        => ['days.php', 'Игры'],
        'tournaments' => ['tournaments.php', 'Турниры'],
        'rating'      => ['rating.php', 'Рейтинг'],
        'players'     => ['players.php', 'Игроки'],
    ];
    // Фото и Тесты — только для вошедших; Правила переехали в Новости (кнопкой)
    if ($authed) {
        $items['photos'] = ['photos.php', 'Фото'];
        $items['tests'] = ['tests.php', 'Тесты'];
    }
    return $items;
}

function logo_svg(int $width = 34): string
{
    // Если в репозитории лежит фирменный PNG — используем его (белая версия под тёмную тему)
    if (is_file(ROOT . '/public_html/assets/img/logo.png')) {
        return '<img src="/assets/img/logo.png?v=1" alt="" class="logo-svg" '
            . 'style="width:' . $width . 'px;height:auto;display:block;">';
    }
    $h = (int)round($width * 150 / 120);
    return '<svg viewBox="0 0 120 150" width="' . $width . '" height="' . $h . '" aria-hidden="true" class="logo-svg">'
        // шляпа
        . '<path d="M41 45 C41 21 48 12 60 12 C72 12 79 21 79 45 Q60 51 41 45 Z" fill="currentColor"/>'
        . '<path d="M42 38 Q60 44 78 38 L78 43 Q60 49 42 43 Z" fill="#e8332a"/>'
        . '<ellipse cx="60" cy="47" rx="41" ry="7" fill="currentColor"/>'
        // кольцо + серьги + шея
        . '<g fill="none" stroke="currentColor" stroke-width="4.5" stroke-linejoin="round" stroke-linecap="round">'
        . '<path d="M37 54 v7"/><circle cx="37" cy="66" r="4.2"/>'
        . '<path d="M83 54 v7"/><circle cx="83" cy="66" r="4.2"/>'
        . '<path d="M60 56 L76 65 V82 L60 91 L44 82 V65 Z"/>'
        . '<path d="M44 96 L60 106 L76 96"/></g>'
        // бабочка
        . '<path d="M43 113 L58 119 L43 125 Q40.5 119 43 113 Z" fill="currentColor"/>'
        . '<path d="M77 113 L62 119 L77 125 Q79.5 119 77 113 Z" fill="currentColor"/>'
        . '<rect x="56" y="111" width="8" height="16" rx="2.5" fill="#e8332a"/></svg>';
}

function page_head(string $title, string $active = ''): void
{
    $u = current_user();
    $env = cfg('env', 'test');
    $robots = $env === 'prod' ? '' : '<meta name="robots" content="noindex, nofollow">' . "\n";
    echo '<!doctype html><html lang="ru" data-theme="dark"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n";
    echo $robots;
    echo '<title>' . esc($title) . ' — Триада Менделеева</title>';
    $base = rtrim((string)cfg('base_url', 'https://triada-mendeleeva.ru'), '/');
    echo '<meta name="description" content="Клуб спортивной мафии «Триада Менделеева» (РХТУ): игровые вечера, турниры, клубный рейтинг и статистика игроков.">';
    echo '<meta property="og:title" content="' . esc($title) . ' — Триада Менделеева">';
    echo '<meta property="og:site_name" content="Триада Менделеева">';
    echo '<meta property="og:type" content="website">';
    echo '<meta property="og:description" content="Клуб спортивной мафии РХТУ: вечера, турниры, рейтинг и статистика.">';
    echo '<meta property="og:image" content="' . esc($base) . '/assets/img/favicon.png">';
    echo '<link rel="icon" href="/assets/img/favicon.png?v=3" type="image/png">';
    echo '<link rel="stylesheet" href="/assets/css/style.css?v=45">';
    echo '</head><body>';

    echo '<header class="site-header"><div class="header-inner header-row">';
    echo '<a class="brand" href="/index.php">' . logo_svg(34);
    echo '<span class="brand-text"><b>Триада Менделеева</b><i>клуб спортивной мафии · РХТУ</i></span></a>';

    echo '<button class="burger" id="nav-burger" aria-label="Меню"><span></span><span></span><span></span></button>';

    echo '<nav class="nav" id="site-nav">';
    foreach (nav_items($u !== null) as $key => [$href, $label]) {
        $cls = $key === $active ? ' class="active"' : '';
        echo '<a href="/' . $href . '"' . $cls . '>' . $label . '</a>';
    }
    if ($u && role_level($u['role']) >= 3) {
        $al = admin_alerts();
        echo '<a href="/admin/" class="nav-admin' . ($active === 'admin' ? ' active' : '') . '">Админка'
            . ($al > 0 ? ' <span class="nav-badge">' . $al . '</span>' : '') . '</a>';
    }
    echo '</nav>';

    echo '<div class="header-right">';

    if ($u) {
        $roleCls = role_level($u['role']) >= 3 ? 'role-admin' : (user_can_judge($u) ? 'role-judge' : 'role-player');
        $pillDot = (role_level($u['role']) >= 3 && admin_alerts() > 0) ? '<span class="pill-dot" title="есть новые предложения/заявки"></span>' : '';
        echo '<div class="user-pill-wrap"><button class="user-pill ' . $roleCls . '" id="user-pill">' . esc($u['nickname']) . $pillDot . '</button>';
        echo '<div class="user-menu" id="user-menu">';
        echo '<span class="user-menu-role">' . esc(role_label($u['role'])) . '</span>';
        echo '<a href="/cabinet.php">Личный кабинет</a>';
        echo '<a href="/my_games.php">Мои игры</a>';
        echo '<a href="/my_stats.php">Моя статистика</a>';
        echo '<a href="/suggest.php">Предложить идею</a>';
        if (role_level($u['role']) >= 3) {
            $al = admin_alerts();
            echo '<a href="/admin/">Админка' . ($al > 0 ? ' <span class="nav-badge">' . $al . '</span>' : '') . '</a>';
        } else {
            if (user_can_judge($u)) {
                echo '<a href="/admin/days.php">Вечера и игры</a>';
            }
            if (user_can_photo($u)) {
                echo '<a href="/admin/albums.php">Фотоальбомы</a>';
            }
        }
        echo '<form method="post" action="/logout.php">' . csrf_field()
           . '<button type="submit" class="linklike danger">Выйти</button></form>';
        echo '</div></div>';
    } else {
        echo '<a class="btn btn-ghost" href="/login.php">Войти</a>';
    }
    echo '</div></div></header>';

    if ($env !== 'prod') {
        echo '<div class="env-ribbon">тестовый контур — данные могут стираться</div>';
    }

    echo '<main class="container">';

    if (!db_ready()) {
        echo '<div class="flash flash-err">База данных не настроена или миграции не применены. '
           . 'Проверьте config.php и откройте <code>/migrate.php?key=…</code></div>';
    }

    foreach (flash_pull() as $f) {
        $cls = $f['t'] === 'ok' ? 'flash-ok' : 'flash-err';
        echo '<div class="flash ' . $cls . '">' . esc($f['m']) . '</div>';
    }
}

function page_foot(): void
{
    echo '</main>';
    echo '<footer class="site-footer"><div class="container footer-row">';
    echo '<span>© Триада Менделеева, ' . date('Y') . '</span>';
    echo '<span class="footer-links">'
       . '<a href="https://t.me/triada_mendeleeva" rel="noopener" target="_blank">Telegram</a>'
       . '<a href="https://vk.com/triada_mendeleev" rel="noopener" target="_blank">VK</a>'
       . '</span>';
    echo '</div></footer>';
    echo '<script src="/assets/js/app.js?v=10"></script>';
    echo '</body></html>';
}

// Карточка-заглушка для разделов, которые появятся на следующих этапах
function empty_state(string $title, string $hint): void
{
    echo '<div class="empty-state">';
    echo '<div class="empty-icon">' . logo_svg(46) . '</div>';
    echo '<h2>' . esc($title) . '</h2>';
    echo '<p>' . esc($hint) . '</p>';
    echo '</div>';
}
