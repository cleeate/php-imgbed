<?php
// Local test router for `php -S` (mimics the .htaccess rewrite).
// Usage: php -S 127.0.0.1:8765 tests/router.php  (run from the repo root)
$path = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (is_file(__DIR__ . '/../' . ltrim($path, '/'))) {
    return false;
}
if (preg_match('#^/([^/]+)/(.+)$#', $path, $m)) {
    $_GET['bucket'] = $m[1];
    $_GET['key'] = $m[2];
    require __DIR__ . '/../s3.php';
    exit;
}
if (preg_match('#^/([^/]+)$#', $path, $m)) {
    $_GET['bucket'] = $m[1];
    $_GET['key'] = '';
    require __DIR__ . '/../s3.php';
    exit;
}
return false;
