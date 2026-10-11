<?php
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($uri === '/' || $uri === '') {
    require __DIR__ . '/../index.php';
    exit;
}

$file = __DIR__ . '/..' . $uri;

if (is_dir($file)) {
    $file = rtrim($file, '/') . '/index.php';
}

if (file_exists($file) && pathinfo($file, PATHINFO_EXTENSION) === 'php') {
    require $file;
    exit;
}

if (file_exists(__DIR__ . '/../index.php')) {
    require __DIR__ . '/../index.php';
}
