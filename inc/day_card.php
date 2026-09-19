<?php
declare(strict_types=1);
// Шэрибл-карточка «Мой вечер» (PNG, PHP GD). Тёмный фирменный стиль сайта.
// Используется ботом при рассылке итогов вечера; при недоступном GD/шрифте — просто null.
//
// Вид согласован с руководителем (сентябрь 2026): логотип рядом с названием клуба, узкий
// плакатный шрифт названия (PT Sans Narrow), без строки «спортивная мафия», ник со смайликом
// из профиля, ELO парой «за вечер → сейчас», игры и победы, адрес сайта.

function day_card_font(bool $bold = false): string
{
    return ROOT . '/public_html/assets/fonts/DejaVuSans' . ($bold ? '-Bold' : '') . '.ttf';
}

// Шрифт названия клуба на карточке — Montserrat Light (выбор руководителя; только карточка,
// сайт не трогаем). Если файла нет (старый деплой) — узкий PT Sans Narrow, затем жирный DejaVu.
function day_card_brand_font(): string
{
    foreach (['Montserrat-Light', 'PTSansNarrow-Bold'] as $name) {
        $f = ROOT . '/public_html/assets/fonts/' . $name . '.ttf';
        if (is_file($f)) {
            return $f;
        }
    }
    return day_card_font(true);
}

function day_card_available(): bool
{
    return function_exists('imagecreatetruecolor') && function_exists('imagettftext')
        && is_file(day_card_font()) && is_file(day_card_font(true));
}

// Ширина строки с межбуквенным интервалом (GD его не умеет — считаем по символам).
function day_card_text_w(float $size, string $font, string $text, float $spacing = 0.0): float
{
    if ($spacing <= 0) {
        $b = imagettfbbox($size, 0, $font, $text);
        return (float)($b[2] - $b[0]);
    }
    $w = 0.0;
    foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
        $w += day_card_char_w($size, $font, $ch) + $spacing;
    }
    return $w > 0 ? $w - $spacing : 0.0;
}

function day_card_char_w(float $size, string $font, string $ch): float
{
    if ($ch === ' ') {
        return $size * 0.33;   // у пробела bbox нулевой — иначе буквы слипнутся
    }
    $b = imagettfbbox($size, 0, $font, $ch);
    return (float)($b[2] - $b[0]);
}

// Текст с межбуквенным интервалом. Возвращает x после последнего символа.
function day_card_text($im, float $size, float $x, int $y, int $color, string $font, string $text, float $spacing = 0.0): float
{
    if ($spacing <= 0) {
        imagettftext($im, $size, 0, (int)round($x), $y, $color, $font, $text);
        return $x + day_card_text_w($size, $font, $text);
    }
    $cx = $x;
    foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
        if ($ch !== ' ') {
            imagettftext($im, $size, 0, (int)round($cx), $y, $color, $font, $ch);
        }
        $cx += day_card_char_w($size, $font, $ch) + $spacing;
    }
    return $cx - $spacing;
}

// Скруглённый прямоугольник (GD такого не умеет).
function day_card_round($im, int $x1, int $y1, int $x2, int $y2, int $r, int $color): void
{
    imagefilledrectangle($im, $x1 + $r, $y1, $x2 - $r, $y2, $color);
    imagefilledrectangle($im, $x1, $y1 + $r, $x2, $y2 - $r, $color);
    $d = $r * 2;
    imagefilledellipse($im, $x1 + $r, $y1 + $r, $d, $d, $color);
    imagefilledellipse($im, $x2 - $r, $y1 + $r, $d, $d, $color);
    imagefilledellipse($im, $x1 + $r, $y2 - $r, $d, $d, $color);
    imagefilledellipse($im, $x2 - $r, $y2 - $r, $d, $d, $color);
}

/**
 * Смайлик профиля картинкой: шрифтов с цветными эмодзи GD не рисует, поэтому берём готовый
 * PNG из открытого набора Twemoji и держим его в storage/emoji. Скачивается один раз на смайлик;
 * не получилось — просто рисуем карточку без него.
 */
function day_card_emoji_png(string $flair): ?string
{
    $flair = trim($flair);
    if ($flair === '' || !preg_match('/\X/u', $flair, $m)) {
        return null;
    }
    $grapheme = $m[0];
    $cps = [];
    foreach (preg_split('//u', $grapheme, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
        $cp = mb_ord($ch, 'UTF-8');
        if ($cp === false) {
            return null;
        }
        $cps[] = $cp;
    }
    if (!$cps || $cps[0] < 0x200D) {
        return null;   // не эмодзи (буква, цифра, знак) — рисовать нечего
    }
    $hasZwj = in_array(0x200D, $cps, true);
    $parts = [];
    foreach ($cps as $cp) {
        if ($cp === 0xFE0F && !$hasZwj) {
            continue;   // как в именах файлов Twemoji: одиночный вариант-селектор отбрасывается
        }
        $parts[] = dechex($cp);
    }
    $name = implode('-', $parts);
    if (!preg_match('/^[0-9a-f-]{2,60}$/', $name)) {
        return null;
    }
    $dir = ROOT . '/storage/emoji';
    $file = $dir . '/' . $name . '.png';
    if (is_file($file)) {
        return filesize($file) > 0 ? $file : null;
    }
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return null;
    }
    $url = 'https://cdn.jsdelivr.net/gh/jdecked/twemoji@15.1.0/assets/72x72/' . $name . '.png';
    $png = false;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_FOLLOWLOCATION => true]);
        $png = curl_exec($ch);
        if ((int)curl_getinfo($ch, CURLINFO_HTTP_CODE) !== 200) {
            $png = false;
        }
        curl_close($ch);
    } else {
        $png = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 6]]));
    }
    if (!is_string($png) || strncmp($png, "\x89PNG", 4) !== 0) {
        return null;
    }
    return @file_put_contents($file, $png) ? $file : null;
}

/**
 * $d: nickname, flair (смайлик профиля или ''), avatar (веб-путь или null), day_title,
 *     day_date (строка «14 июня»), games (int), wins (int), net (float), elo (float),
 *     record (bool), top (bool — лучший ELO вечера).
 * Возвращает путь к временному PNG либо null.
 */
function day_card_png(array $d): ?string
{
    if (!day_card_available()) {
        return null;
    }
    $W = 1000;
    $H = 560;
    $im = imagecreatetruecolor($W, $H);
    imagealphablending($im, true);
    $bg  = imagecolorallocate($im, 14, 14, 17);    // --bg
    $sf  = imagecolorallocate($im, 22, 22, 27);    // карточка-подложка
    $sf2 = imagecolorallocate($im, 29, 29, 36);    // плашка ELO
    $bd  = imagecolorallocate($im, 43, 43, 51);    // --bd
    $tx  = imagecolorallocate($im, 241, 241, 243); // --tx
    $tx2 = imagecolorallocate($im, 156, 156, 166); // --tx2
    $tx3 = imagecolorallocate($im, 105, 105, 115);
    $ac  = imagecolorallocate($im, 232, 51, 42);   // --ac
    $ok  = imagecolorallocate($im, 63, 190, 110);  // ярче --ok для читаемости
    $gold = imagecolorallocate($im, 232, 184, 48);
    $F  = day_card_font();
    $FB = day_card_font(true);
    $FBR = day_card_brand_font();

    imagefilledrectangle($im, 0, 0, $W, $H, $bg);
    imagefilledrectangle($im, 24, 24, $W - 24, $H - 24, $sf);
    imagerectangle($im, 24, 24, $W - 24, $H - 24, $bd);
    imagefilledrectangle($im, 24, 24, 32, $H - 24, $ac); // акцентная полоса слева

    // Шапка: логотип клуба + название плакатным шрифтом
    $textX = 62.0;
    $logo = ROOT . '/public_html/assets/img/logo.png';
    if (is_file($logo)) {
        $src = @imagecreatefrompng($logo);
        if ($src) {
            $side = 66;
            $sw = imagesx($src);
            $sh = imagesy($src);
            $dw = $sw >= $sh ? $side : (int)round($side * $sw / $sh);
            $dh = $sw >= $sh ? (int)round($side * $sh / $sw) : $side;
            imagecopyresampled($im, $src, 58, 40 + (int)round(($side - $dh) / 2), 0, 0, $dw, $dh, $sw, $sh);
            imagedestroy($src);
            $textX = 58.0 + $dw + 16;
        }
    }
    // Обе строки опущены на 6px: логотип выше их блока, и так он смотрится соразмерно.
    // Размер названия подгоняем под свободное место — Montserrat шире прежнего узкого шрифта.
    $brandMax = $W - 62 - 148 - 40 - $textX;   // до аватара справа
    $brandSize = 29.0;
    while ($brandSize > 18 && day_card_text_w($brandSize, $FBR, 'ТРИАДА МЕНДЕЛЕЕВА', 2.5) > $brandMax) {
        $brandSize -= 1;
    }
    day_card_text($im, $brandSize, $textX, 80, $tx, $FBR, 'ТРИАДА МЕНДЕЛЕЕВА', 2.5);
    day_card_text($im, 13, $textX + 2, 107, $ac, $FBR, 'ИТОГИ ВЕЧЕРА', 4.5);

    // Аватар справа (круглый)
    $avSize = 148;
    $avX = $W - 62 - $avSize;
    $avY = 48;
    $avFile = !empty($d['avatar']) ? ROOT . '/public_html' . $d['avatar'] : '';
    $hasAva = false;
    if ($avFile !== '' && is_file($avFile)) {
        $raw = @file_get_contents($avFile);
        $src = $raw !== false ? @imagecreatefromstring($raw) : false;
        if ($src) {
            $sq = imagecreatetruecolor($avSize, $avSize);
            imagecopyresampled($sq, $src, 0, 0, 0, 0, $avSize, $avSize, imagesx($src), imagesy($src));
            $r2 = ($avSize / 2) ** 2;
            for ($yy = 0; $yy < $avSize; $yy++) {
                for ($xx = 0; $xx < $avSize; $xx++) {
                    $dx = $xx - $avSize / 2;
                    $dy = $yy - $avSize / 2;
                    if ($dx * $dx + $dy * $dy <= $r2) {
                        imagesetpixel($im, $avX + $xx, $avY + $yy, imagecolorat($sq, $xx, $yy));
                    }
                }
            }
            imagedestroy($sq);
            imagedestroy($src);
            $hasAva = true;
        }
    }
    if (!$hasAva) {
        // Кружок с первой буквой ника — как аватар-заглушка на сайте
        imagefilledellipse($im, $avX + $avSize / 2, $avY + $avSize / 2, $avSize, $avSize, $sf2);
        $letter = mb_strtoupper(mb_substr(trim((string)$d['nickname']), 0, 1));
        if ($letter !== '') {
            $lw = day_card_text_w(56, $FB, $letter);
            imagettftext($im, 56, 0, (int)round($avX + $avSize / 2 - $lw / 2), $avY + $avSize / 2 + 20, $tx3, $FB, $letter);
        }
    }

    // Дата вечера. Название обычно и есть дата («19 сентября» при «19 сентября 2026») —
    // тогда не дублируем; показываем только по-настоящему другое название.
    $dTitle = trim((string)$d['day_title']);
    $dDate = trim((string)$d['day_date']);
    $when = ($dTitle === '' || mb_strpos($dDate, $dTitle) !== false) ? $dDate : $dDate . ' · ' . $dTitle;
    imagettftext($im, 16, 0, 62, 168, $tx2, $F, $when);

    // Ник + смайлик профиля (картинкой: цветные эмодзи GD не рисует)
    $nick = (string)$d['nickname'];
    $emoji = day_card_emoji_png((string)($d['flair'] ?? ''));
    $maxNickW = $avX - 62 - 26 - ($emoji ? 58 : 0);
    $sizeNick = 44;   // как в прежней карточке — размер руководитель просил вернуть
    while ($sizeNick > 20 && day_card_text_w($sizeNick, $FB, $nick) > $maxNickW) {
        $sizeNick -= 2;
    }
    $nickBase = 248;   // ник чуть ниже — так он не жмётся к дате
    $nickEnd = day_card_text($im, $sizeNick, 62, $nickBase, $tx, $FB, $nick);
    if ($emoji) {
        $em = @imagecreatefrompng($emoji);
        if ($em) {
            // Смайлик стоит на базовой линии ника, ростом примерно с заглавную букву
            $es = (int)round($sizeNick * 0.9);
            imagecopyresampled($im, $em, (int)round($nickEnd) + 14, $nickBase - $es, 0, 0, $es, $es, imagesx($em), imagesy($em));
            imagedestroy($em);
        }
    }

    // ELO: «за вечер» и «сейчас» одной плашкой
    $net = (float)$d['net'];
    $col = $net > 0 ? $ok : ($net < 0 ? $ac : $tx2);
    $sign = $net > 0 ? '+' : ($net < 0 ? '−' : '±');
    $arrow = $net > 0 ? ' ▲' : ($net < 0 ? ' ▼' : '');
    $left = $sign . round(abs($net)) . $arrow;
    $right = (string)round((float)$d['elo']);
    // Размер цифр — 36: при 52 и 46 они упирались в края плашки.
    $numSize = 36;
    $pad = 30;
    $wL = max(day_card_text_w($numSize, $FB, $left), day_card_text_w(14, $F, 'ELO за вечер'));
    $wR = max(day_card_text_w($numSize, $FB, $right), day_card_text_w(14, $F, 'ELO сейчас'));
    $x1 = 60;
    $y1 = 282;
    $y2 = 400;
    $x2 = (int)round($x1 + $pad + $wL + $pad + 1 + $pad + $wR + $pad);
    day_card_round($im, $x1, $y1, $x2, $y2, 16, $bd);
    day_card_round($im, $x1 + 1, $y1 + 1, $x2 - 1, $y2 - 1, 15, $sf2);
    $cxL = $x1 + $pad;
    $sepX = (int)round($cxL + $wL + $pad);
    imagefilledrectangle($im, $sepX, $y1 + 14, $sepX, $y2 - 14, $bd);
    $cxR = $sepX + $pad;
    imagettftext($im, $numSize, 0, (int)round($cxL + ($wL - day_card_text_w($numSize, $FB, $left)) / 2), $y1 + 62, $col, $FB, $left);
    imagettftext($im, 14, 0, (int)round($cxL + ($wL - day_card_text_w(14, $F, 'ELO за вечер')) / 2), $y1 + 96, $tx2, $F, 'ELO за вечер');
    imagettftext($im, $numSize, 0, (int)round($cxR + ($wR - day_card_text_w($numSize, $FB, $right)) / 2), $y1 + 62, $tx, $FB, $right);
    imagettftext($im, 14, 0, (int)round($cxR + ($wR - day_card_text_w(14, $F, 'ELO сейчас')) / 2), $y1 + 96, $tx2, $F, 'ELO сейчас');

    // Игры и победы
    imagettftext($im, 22, 0, 62, 452, $tx, $FB, 'Игр: ' . (int)$d['games'] . ' · Побед: ' . (int)$d['wins']);

    // Бейджи — справа, напротив плашки ELO
    $by = 330;
    foreach ([[!empty($d['record']), '★ НОВЫЙ РЕКОРД ELO'], [!empty($d['top']), '♛ ЛУЧШИЙ ELO ВЕЧЕРА']] as [$show, $label]) {
        if (!$show) {
            continue;
        }
        $bw = day_card_text_w(16, $FB, $label);
        imagettftext($im, 16, 0, (int)round($W - 62 - $bw), $by, $gold, $FB, $label);
        $by += 38;
    }

    // Футер
    imagettftext($im, 13, 0, 62, $H - 44, $tx3, $F, 'triada-mendeleeva.ru');

    $tmp = tempnam(sys_get_temp_dir(), 'tmcard');
    if ($tmp === false) {
        imagedestroy($im);
        return null;
    }
    $png = $tmp . '.png';
    @rename($tmp, $png);
    if (!imagepng($im, $png, 6)) {
        imagedestroy($im);
        @unlink($png);
        return null;
    }
    imagedestroy($im);
    return $png;
}
