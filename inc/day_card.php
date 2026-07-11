<?php
declare(strict_types=1);
// Шэрибл-карточка «Мой вечер» (PNG, PHP GD + DejaVu). Тёмный фирменный стиль сайта.
// Используется ботом при рассылке итогов вечера; при недоступном GD/шрифте — просто null.

function day_card_font(bool $bold = false): string
{
    return ROOT . '/public_html/assets/fonts/DejaVuSans' . ($bold ? '-Bold' : '') . '.ttf';
}

function day_card_available(): bool
{
    return function_exists('imagecreatetruecolor') && function_exists('imagettftext')
        && is_file(day_card_font()) && is_file(day_card_font(true));
}

/**
 * $d: nickname, avatar (веб-путь или null), day_title, day_date (строка «14 июня»),
 *     games (int), wins (int), roles (['Мирный'=>2, ...]), net (float), elo (float),
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
    $bg  = imagecolorallocate($im, 14, 14, 17);    // --bg
    $sf  = imagecolorallocate($im, 22, 22, 27);    // карточка-подложка
    $bd  = imagecolorallocate($im, 43, 43, 51);    // --bd
    $tx  = imagecolorallocate($im, 241, 241, 243); // --tx
    $tx2 = imagecolorallocate($im, 156, 156, 166); // --tx2
    $tx3 = imagecolorallocate($im, 105, 105, 115);
    $ac  = imagecolorallocate($im, 232, 51, 42);   // --ac
    $ok  = imagecolorallocate($im, 63, 190, 110);  // ярче --ok для читаемости
    $gold = imagecolorallocate($im, 232, 184, 48);
    $F  = day_card_font();
    $FB = day_card_font(true);

    imagefilledrectangle($im, 0, 0, $W, $H, $bg);
    imagefilledrectangle($im, 24, 24, $W - 24, $H - 24, $sf);
    imagerectangle($im, 24, 24, $W - 24, $H - 24, $bd);
    imagefilledrectangle($im, 24, 24, 32, $H - 24, $ac); // акцентная полоса слева

    // Шапка
    imagettftext($im, 15, 0, 64, 76, $tx2, $FB, 'ТРИАДА МЕНДЕЛЕЕВА');
    imagettftext($im, 13, 0, 64, 104, $tx3, $F, 'спортивная мафия · итоги вечера');
    imagettftext($im, 15, 0, 64, 148, $tx2, $F, $d['day_title'] . ' · ' . $d['day_date']);

    // Аватар справа (круглый)
    $avSize = 148;
    $avX = $W - 64 - $avSize;
    $avY = 56;
    $avFile = !empty($d['avatar']) ? ROOT . '/public_html' . $d['avatar'] : '';
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
        }
    }

    // Ник крупно (обрезаем, чтобы не налез на аватар)
    $nick = (string)$d['nickname'];
    $maxNickW = $avX - 64 - 24;
    $sizeNick = 44;
    while ($sizeNick > 20) {
        $box = imagettfbbox($sizeNick, 0, $FB, $nick);
        if (($box[2] - $box[0]) <= $maxNickW) {
            break;
        }
        $sizeNick -= 2;
    }
    imagettftext($im, $sizeNick, 0, 62, 230, $tx, $FB, $nick);

    // ELO-дельта — главный акцент
    $net = (float)$d['net'];
    $col = $net > 0 ? $ok : ($net < 0 ? $ac : $tx2);
    $sign = $net > 0 ? '+' : ($net < 0 ? '−' : '±');
    $arrow = $net > 0 ? '▲' : ($net < 0 ? '▼' : '');
    imagettftext($im, 66, 0, 62, 348, $col, $FB, $sign . round(abs($net)) . ($arrow !== '' ? ' ' . $arrow : ''));
    imagettftext($im, 17, 0, 64, 388, $tx2, $F, 'ELO за вечер · сейчас ' . round((float)$d['elo']));

    // Игры и роли
    imagettftext($im, 21, 0, 64, 442, $tx, $FB, 'Игр: ' . (int)$d['games'] . ' · Побед: ' . (int)$d['wins']);
    if (!empty($d['roles'])) {
        $parts = [];
        foreach ($d['roles'] as $rn => $rc) {
            $parts[] = $rn . ($rc > 1 ? ' ×' . $rc : '');
        }
        imagettftext($im, 15, 0, 64, 476, $tx2, $F, 'Роли: ' . implode(' · ', $parts));
    }

    // Бейджи (справа снизу, столбиком)
    $by = 380;
    if (!empty($d['record'])) {
        imagettftext($im, 17, 0, $W - 420, $by, $gold, $FB, '★ НОВЫЙ РЕКОРД ELO');
        $by += 40;
    }
    if (!empty($d['top'])) {
        imagettftext($im, 17, 0, $W - 420, $by, $gold, $FB, '♛ ЛУЧШИЙ ELO ВЕЧЕРА');
    }

    // Футер
    imagettftext($im, 13, 0, 64, $H - 46, $tx3, $F, 'triada-mendeleeva.ru');

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
