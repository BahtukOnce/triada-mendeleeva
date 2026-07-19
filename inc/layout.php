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
        'records'     => ['records.php', 'Зал славы'],
    ];
    // Фото и Тесты временно убраны из меню (по просьбе — пока не нужны). Страницы живы,
    // вернуть = раскомментировать. Правила — кнопкой в Новостях.
    // if ($authed) {
    //     $items['photos'] = ['photos.php', 'Фото'];
    //     $items['tests'] = ['tests.php', 'Тесты'];
    // }
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

function page_head(string $title, string $active = '', array $meta = []): void
{
    $u = current_user();
    $env = cfg('env', 'test');
    $robots = $env === 'prod' ? '' : '<meta name="robots" content="noindex, nofollow">' . "\n";
    $base = rtrim((string)cfg('base_url', 'https://triada-mendeleeva.ru'), '/');

    // Мета для превью ссылок (Telegram/VK): по умолчанию — общая инфа о клубе,
    // отдельные страницы (игрок, турнир) передают свой image/description через $meta.
    $desc    = trim((string)($meta['description'] ?? 'Клуб спортивной мафии «Триада Менделеева» (РХТУ): игровые вечера, турниры, клубный рейтинг и статистика игроков.'));
    $ogTitle = (string)($meta['og_title'] ?? ($title . ' — Триада Менделеева'));
    $ogType  = (string)($meta['og_type'] ?? 'website');
    $card    = (string)($meta['twitter_card'] ?? 'summary');
    $img     = trim((string)($meta['image'] ?? '')) ?: '/assets/img/favicon.png';
    if (!preg_match('#^https?://#', $img)) {
        $img = $base . '/' . ltrim($img, '/');
    }
    $ogUrl = trim((string)($meta['url'] ?? ''));
    if ($ogUrl !== '' && !preg_match('#^https?://#', $ogUrl)) {
        $ogUrl = $base . '/' . ltrim($ogUrl, '/');
    }

    // Canonical: у страницы должен быть единственный «главный» адрес, иначе поисковик
    // считает ?utm=…/дубли отдельными страницами и размывает вес. Берём переданный url
    // либо текущий путь, очищенный от трекинговых параметров.
    $canonical = $ogUrl;
    if ($canonical === '') {
        $reqUri = (string)($_SERVER['REQUEST_URI'] ?? '/');
        [$cPath, $cQs] = array_pad(explode('?', $reqUri, 2), 2, '');
        if ($cQs !== '') {
            parse_str($cQs, $cParams);
            foreach (array_keys($cParams) as $ck) {
                if (preg_match('/^(utm_|fbclid|gclid|yclid|ysclid|_ga|from)/i', (string)$ck)) {
                    unset($cParams[$ck]);
                }
            }
            $cQs = http_build_query($cParams);
        }
        $canonical = $base . $cPath . ($cQs !== '' ? '?' . $cQs : '');
    }

    echo '<!doctype html><html lang="ru" data-theme="dark"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n";
    echo $robots;
    $titleTag = trim((string)($meta['title_tag'] ?? '')) ?: ($title . ' — Триада Менделеева');
    echo '<title>' . esc($titleTag) . '</title>';
    echo '<meta name="description" content="' . esc($desc) . '">';
    echo '<meta property="og:title" content="' . esc($ogTitle) . '">';
    echo '<meta property="og:site_name" content="Триада Менделеева">';
    echo '<meta property="og:type" content="' . esc($ogType) . '">';
    echo '<meta property="og:description" content="' . esc($desc) . '">';
    echo '<meta property="og:image" content="' . esc($img) . '">';
    if ($ogUrl !== '') {
        echo '<meta property="og:url" content="' . esc($ogUrl) . '">';
    }
    echo '<link rel="canonical" href="' . esc($canonical) . '">';
    echo '<meta name="twitter:card" content="' . esc($card) . '">';
    // Подтверждение прав в Google Search Console / Яндекс.Вебмастере (токены из config).
    $gsv = trim((string)cfg('google_site_verification', ''));
    if ($gsv !== '') {
        echo '<meta name="google-site-verification" content="' . esc($gsv) . '">';
    }
    $yv = trim((string)cfg('yandex_verification', ''));
    if ($yv !== '') {
        echo '<meta name="yandex-verification" content="' . esc($yv) . '">';
    }
    echo '<link rel="icon" href="/assets/img/favicon.png?v=3" type="image/png">';
    echo '<link rel="manifest" href="/manifest.webmanifest?v=1">';
    echo '<meta name="theme-color" content="#0e0e11">';
    echo '<link rel="apple-touch-icon" href="/assets/img/icon-192.png?v=1">';
    echo '<meta name="apple-mobile-web-app-capable" content="yes">';
    echo '<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">';
    echo '<meta name="apple-mobile-web-app-title" content="Триада">';
    echo '<link rel="stylesheet" href="/assets/css/style.css?v=107">';

    // Structured data (schema.org): помогает Google/Яндексу понять, что это за
    // организация, показать её как единый бренд и построить sitelinks-поиск.
    if ($env === 'prod') {
        $vk = trim((string)cfg('vk_url', 'https://vk.com/triada_mendeleev'));
        $sameAs = [];
        if ($vk !== '') {
            $sameAs[] = $vk;
        }
        $graph = [
            [
                '@type'         => 'SportsOrganization',
                '@id'           => $base . '/#org',
                'name'          => 'Триада Менделеева',
                'alternateName' => 'Клуб спортивной мафии «Триада Менделеева»',
                'url'           => $base . '/',
                'logo'          => $base . '/assets/img/icon-192.png',
                'description'   => 'Клуб спортивной мафии при РХТУ им. Д. И. Менделеева: игровые вечера, турниры, клубный рейтинг и статистика игроков.',
                'sport'         => 'Спортивная мафия',
            ],
            [
                '@type'           => 'WebSite',
                '@id'             => $base . '/#website',
                'url'             => $base . '/',
                'name'            => 'Триада Менделеева',
                'inLanguage'      => 'ru',
                'publisher'       => ['@id' => $base . '/#org'],
                'potentialAction' => [
                    '@type'       => 'SearchAction',
                    'target'      => ['@type' => 'EntryPoint', 'urlTemplate' => $base . '/players.php?q={search_term_string}'],
                    'query-input' => 'required name=search_term_string',
                ],
            ],
        ];
        if ($sameAs) {
            $graph[0]['sameAs'] = $sameAs;
        }
        echo '<script type="application/ld+json">'
            . json_encode(['@context' => 'https://schema.org', '@graph' => $graph], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            . '</script>';
    }
    echo '</head><body>';

    echo '<header class="site-header"><div class="header-inner header-row">';
    // «Живое» лого (только в шапке): PNG распилен на слои шляпа/лицо, поверх колец-«линз» —
    // живые глаза (склера + зрачок). Зрачки оглядываются, щурятся, моргают; правый глаз
    // подмигивает; шляпа приподнимается («поправил шляпу»). Анимации — .logo-anim в style.css.
    // Слои лого — SVG (вектор, трассирован с фирменного PNG): чёткие границы на любом
    // зуме и retina. PNG-слои остаются в репо как исходники для трассировки.
    $hasLayers = is_file(ROOT . '/public_html/assets/img/logo_hat.svg')
        && is_file(ROOT . '/public_html/assets/img/logo_face.svg');
    if ($hasLayers) {
        echo '<a class="brand" href="/index.php"><span class="logo-anim" aria-hidden="true">'
            . '<img class="logo-face" src="/assets/img/logo_face.svg?v=1" alt="">'
            . '<img class="logo-hat" src="/assets/img/logo_hat.svg?v=1" alt="">'
            . '<span class="logo-arm aL"><img src="/assets/img/logo_arm.svg?v=1" alt=""></span>'
            . '<span class="logo-arm aR"><img src="/assets/img/logo_arm.svg?v=1" alt=""></span>'
            . '<span class="logo-eye2 e2l"><i></i></span>'
            . '<span class="logo-eye2 e2r"><i></i></span>'
            . '</span>';
    } else {
        echo '<a class="brand" href="/index.php"><span class="logo-anim">' . logo_svg(60) . '</span>';
    }
    echo '<span class="brand-text"><b>Триада Менделеева</b><i>клуб спортивной мафии · РХТУ</i></span></a>';

    echo '<button class="burger" id="nav-burger" aria-label="Меню" aria-expanded="false"><span></span><span></span><span></span></button>';

    // Полупрозрачная подложка под выдвижным меню (мобильные)
    echo '<div class="nav-scrim" id="nav-scrim" hidden></div>';

    echo '<nav class="nav" id="site-nav" aria-label="Основное меню">';
    // Шапка выдвижного меню (видна только на мобильных)
    echo '<div class="nav-drawer-head"><span class="nav-drawer-brand">' . logo_svg(26) . '<b>Триада</b></span>'
        . '<button class="nav-close" id="nav-close" aria-label="Закрыть меню">&times;</button></div>';
    foreach (nav_items($u !== null) as $key => [$href, $label]) {
        $cls = $key === $active ? ' class="active"' : '';
        echo '<a href="/' . $href . '"' . $cls . '>' . $label . '</a>';
    }
    if ($u && role_level($u['role']) >= 3) {
        $al = admin_alerts();
        echo '<a href="/admin/" class="nav-admin' . ($active === 'admin' ? ' active' : '') . '">Админка'
            . ($al > 0 ? ' <span class="nav-badge">' . $al . '</span>' : '') . '</a>';
    }
    // Низ выдвижного меню: вход/анкета для гостя или кабинет/выход (только мобильные)
    if ($u) {
        echo '<div class="nav-drawer-auth">'
            . '<a class="nav-drawer-cab" href="/cabinet.php">Личный кабинет</a>'
            . '<form method="post" action="/logout.php" class="nav-drawer-logout">' . csrf_field()
            . '<button type="submit">Выйти</button></form></div>';
    } else {
        echo '<div class="nav-drawer-auth">'
            . '<a class="btn btn-block" href="/login.php">Войти</a>'
            . '<a class="btn btn-ghost btn-block" href="/join.php" style="margin-top:8px;">Подать заявку</a></div>';
    }
    echo '</nav>';

    echo '<div class="header-right">';

    if ($u) {
        $unread = app_notify_unread((int)$u['id']);
        echo '<a class="bell" href="/notifications.php" aria-label="Уведомления" title="Уведомления">'
            . '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>'
            . ($unread ? '<span class="bell-badge">' . ($unread > 99 ? '99+' : (int)$unread) . '</span>' : '')
            . '</a>';
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
    $tgIcon = '<svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor" aria-hidden="true"><path d="M11.944 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 12 0a12 12 0 0 0-.056 0zm4.962 7.224c.1-.002.321.023.465.14a.506.506 0 0 1 .171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.696.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.14-5.061 3.345-.48.33-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.245-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.627 4.476-1.635z"/></svg>';
    $vkIcon = '<svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor" aria-hidden="true"><path d="m9.489.004.729-.003h3.564l.73.003.914.01.433.007.418.011.403.014.388.016.374.021.36.025.345.03.333.033c1.74.196 2.933.616 3.833 1.516.9.9 1.32 2.092 1.516 3.833l.034.333.029.346.025.36.02.373.025.588.012.41.013.644.009.915.004.98-.001 3.313-.003.73-.01.914-.007.433-.011.418-.014.403-.016.388-.021.374-.025.36-.03.345-.033.333c-.196 1.74-.616 2.933-1.516 3.833-.9.9-2.092 1.32-3.833 1.516l-.333.034-.346.029-.36.025-.373.02-.588.025-.41.012-.644.013-.915.009-.98.004-3.313-.001-.73-.003-.914-.01-.433-.007-.418-.011-.403-.014-.388-.016-.374-.021-.36-.025-.345-.03-.333-.033c-1.74-.196-2.933-.616-3.833-1.516-.9-.9-1.32-2.092-1.516-3.833l-.034-.333-.029-.346-.025-.36-.02-.373-.025-.588-.012-.41-.013-.644-.009-.915-.004-.98.001-3.313.003-.73.01-.914.007-.433.011-.418.014-.403.016-.388.021-.374.025-.36.03-.345.033-.333c.196-1.74.616-2.933 1.516-3.833.9-.9 2.092-1.32 3.833-1.516l.333-.034.346-.029.36-.025.373-.02.588-.025.41-.012.644-.013.915-.009ZM6.79 7.3H4.05c.13 6.24 3.25 9.99 8.72 9.99h.31v-3.57c2.01.2 3.53 1.67 4.14 3.57h2.84c-.78-2.84-2.83-4.41-4.11-5.01 1.28-.74 3.08-2.54 3.51-4.98h-2.58c-.56 1.98-2.22 3.78-3.8 3.95V7.3H10.5v6.92c-1.6-.4-3.62-2.34-3.71-6.92Z"/></svg>';
    echo '<span class="footer-links">'
       . '<a class="soc tg" href="https://t.me/triada_mendeleeva" rel="noopener" target="_blank" aria-label="Telegram" title="Telegram">' . $tgIcon . '</a>'
       . '<a class="soc vk" href="https://vk.com/triada_mendeleev" rel="noopener" target="_blank" aria-label="VK" title="VK">' . $vkIcon . '</a>'
       . '</span>';
    echo '</div></footer>';
    echo '<script src="/assets/js/app.js?v=21"></script>';
    echo '<script>if("serviceWorker" in navigator){window.addEventListener("load",function(){navigator.serviceWorker.register("/sw.js").catch(function(){});});}</script>';
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
