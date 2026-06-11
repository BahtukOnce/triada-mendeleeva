<?php
// GitHub webhook: проверка подписи → git pull своей ветки → миграции БД.
declare(strict_types=1);

define('ROOT', dirname(__DIR__));

$cfgFile = ROOT . '/config.php';
if (!is_file($cfgFile)) {
    http_response_code(503);
    exit('config.php missing');
}
$GLOBALS['cfg'] = require $cfgFile;
$cfg = $GLOBALS['cfg'];

$raw = (string)file_get_contents('php://input');
$sig = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
$expected = 'sha256=' . hash_hmac('sha256', $raw, (string)$cfg['deploy_secret']);
if ($sig === '' || !hash_equals($expected, $sig)) {
    http_response_code(403);
    exit('bad signature');
}

header('Content-Type: text/plain; charset=utf-8');

$payload = json_decode($raw, true) ?: [];
$event = $_SERVER['HTTP_X_GITHUB_EVENT'] ?? 'push';
$branch = (string)$cfg['deploy_branch'];

if ($event === 'ping') {
    echo "pong\n";
    exit;
}
$ref = (string)($payload['ref'] ?? '');
if ($ref !== '' && $ref !== 'refs/heads/' . $branch) {
    echo "ignored: $ref (deploy branch is $branch)\n";
    exit;
}

$out = [];
$rc = 0;
$gitCfg = '-c safe.directory=' . escapeshellarg(ROOT);
exec(
    'cd ' . escapeshellarg(ROOT) . ' && git ' . $gitCfg . ' fetch origin ' . escapeshellarg($branch) . ' 2>&1'
    . ' && git ' . $gitCfg . ' reset --hard origin/' . escapeshellarg($branch) . ' 2>&1',
    $out,
    $rc
);

$migLog = [];
if ($rc === 0) {
    try {
        require ROOT . '/inc/db.php';
        $migLog = run_migrations();
    } catch (Throwable $e) {
        $migLog[] = 'migration error: ' . $e->getMessage();
    }
}

$logLine = date('Y-m-d H:i:s') . " event=$event ref=$ref rc=$rc\n"
    . implode("\n", $out) . "\n" . implode("\n", $migLog) . "\n\n";
@file_put_contents(ROOT . '/deploy.log', $logLine, FILE_APPEND);

echo "deploy rc=$rc\n" . implode("\n", $out) . "\n" . implode("\n", $migLog) . "\n";
