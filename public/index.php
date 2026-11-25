<?php

declare(strict_types=1);

/*
 * Router for `php -S 127.0.0.1:8090 -t public public/index.php`.
 * Security rules: see the Security section of README.md.
 */

require __DIR__ . '/../src/autoload.php';
require __DIR__ . '/../src/Web/functions.php';

$port = (string) ($_SERVER['SERVER_PORT'] ?? '');
$host = (string) ($_SERVER['HTTP_HOST'] ?? '');
$remoteAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
if (!Viz\Web\Loopback::isLoopback($remoteAddress) || !in_array($host, ['127.0.0.1:' . $port, 'localhost:' . $port], true)) {
    http_response_code(403);
    echo '403';
    return true;
}

$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
if (str_starts_with($path, '/assets/') && !str_contains($path, '..') && is_file(__DIR__ . $path)) {
    return false;
}
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
// writes (session export, session evaluation launching Claude Code) only via POST from our own page — a foreign page cannot trigger it with a form or <img>
// browsers send Sec-Fetch-Site; without it the Origin of our own host is required (curl without headers → 403)
$fetchSite = (string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '');
$origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
$sameOrigin = $fetchSite !== ''
    ? $fetchSite === 'same-origin'
    : in_array($origin, ['http://127.0.0.1:' . $port, 'http://localhost:' . $port], true);
if ($method !== 'GET' && !($method === 'POST' && in_array($path, ['/export', '/judge'], true) && $sameOrigin)) {
    http_response_code($method === 'POST' ? 403 : 405);
    echo $method === 'POST' ? '403' : '405';
    return true;
}

header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'");
header('Referrer-Policy: same-origin');

(new Viz\Web\App())->handle($path, $_GET, $method);

return true;
