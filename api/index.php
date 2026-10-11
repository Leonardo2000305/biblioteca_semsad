<?php
// Cabecera estricta para evitar que el navegador descargue la página
header('Content-Type: text/html; charset=utf-8');

// Mostrar errores PHP en pantalla si ocurre un fallo de ruta o sintaxis
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$rootDir = dirname(__DIR__);
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($uri === '/' || $uri === '' || $uri === '/index.php') {
    require $rootDir . '/index.php';
    exit;
}

$file = $rootDir . $uri;

if (is_dir($file)) {
    $file = rtrim($file, '/') . '/index.php';
}

if (file_exists($file) && pathinfo($file, PATHINFO_EXTENSION) === 'php') {
    require $file;
    exit;
}

if (file_exists($rootDir . '/index.php')) {
    require $rootDir . '/index.php';
} else {
    echo "<h2>404 - Página no encontrada</h2>";
}
