<?php
/**
 * Эндпоинт версии мобильного приложения.
 * Приложение (UpdateChecker) периодически дёргает его и сравнивает versionCode
 * с установленным. Источник правды — GitHub Releases с тегом вида `app-v1.0.1`.
 *
 * Ответ: {"versionCode":10001,"versionName":"1.0.1","url":"…apk","notes":"…"}
 * Если релизов нет / GitHub недоступен — {"versionCode":0} (обновлений нет).
 *
 * Ручной оверрайд: положите рядом version.override.json с таким же полями —
 * он вернётся как есть (нужно, если хостинг блокирует исходящие HTTPS-запросы).
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

const REPO = 'BahtukOnce/triada-mendeleeva';
const TAG_PREFIX = 'app-v';
const CACHE_OK_TTL = 600;   // кэш успешного ответа, сек
const CACHE_ERR_TTL = 120;  // кэш ошибки, сек (чтобы не долбить API)

// 1) ручной оверрайд имеет приоритет
$override = __DIR__ . '/version.override.json';
if (is_file($override)) {
    header('Cache-Control: public, max-age=120');
    echo file_get_contents($override);
    exit;
}

// 2) кэш
$cacheFile = sys_get_temp_dir() . '/triada_app_version.json';
if (is_file($cacheFile)) {
    $age = time() - filemtime($cacheFile);
    $cached = json_decode(file_get_contents($cacheFile), true);
    $ttl = (isset($cached['versionCode']) && $cached['versionCode'] > 0) ? CACHE_OK_TTL : CACHE_ERR_TTL;
    if ($age < $ttl) {
        header('Cache-Control: public, max-age=120');
        echo json_encode($cached, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// 3) запрос к GitHub
$out = ['versionCode' => 0];
$release = latest_app_release();
if ($release) {
    $name = preg_replace('/^' . preg_quote(TAG_PREFIX, '/') . '/', '', $release['tag_name'] ?? '');
    $vc = version_code($name);
    $apk = '';
    foreach (($release['assets'] ?? []) as $a) {
        if (!empty($a['name']) && strtolower(substr($a['name'], -4)) === '.apk') {
            $apk = $a['browser_download_url'] ?? '';
            break;
        }
    }
    if ($vc > 0 && $apk !== '') {
        $notes = trim((string)($release['body'] ?? ''));
        if (mb_strlen($notes) > 600) $notes = mb_substr($notes, 0, 600) . '…';
        $out = [
            'versionCode' => $vc,
            'versionName' => $name,
            'url'         => $apk,
            'notes'       => $notes,
        ];
    }
}

$json = json_encode($out, JSON_UNESCAPED_UNICODE);
@file_put_contents($cacheFile, $json);
header('Cache-Control: public, max-age=120');
echo $json;

/** versionCode по формуле major*10000 + minor*100 + patch (как в build.gradle). */
function version_code($name) {
    if (!preg_match('/(\d+)\.(\d+)(?:\.(\d+))?/', $name, $m)) return 0;
    return (int)$m[1] * 10000 + (int)$m[2] * 100 + (int)($m[3] ?? 0);
}

/** Последний релиз с тегом app-v*. */
function latest_app_release() {
    $body = http_get('https://api.github.com/repos/' . REPO . '/releases?per_page=15');
    if ($body === null) return null;
    $list = json_decode($body, true);
    if (!is_array($list)) return null;
    foreach ($list as $r) {
        if (!empty($r['draft']) || !empty($r['prerelease'])) continue;
        if (isset($r['tag_name']) && strpos($r['tag_name'], TAG_PREFIX) === 0) return $r;
    }
    return null;
}

function http_get($url) {
    // curl предпочтительнее
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_USERAGENT      => 'triada-mendeleeva-app',
            CURLOPT_HTTPHEADER     => ['Accept: application/vnd.github+json'],
        ]);
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($res !== false && $code === 200) ? $res : null;
    }
    // фоллбек
    $ctx = stream_context_create(['http' => [
        'method'  => 'GET',
        'timeout' => 8,
        'header'  => "User-Agent: triada-mendeleeva-app\r\nAccept: application/vnd.github+json\r\n",
    ]]);
    $res = @file_get_contents($url, false, $ctx);
    return $res !== false ? $res : null;
}
