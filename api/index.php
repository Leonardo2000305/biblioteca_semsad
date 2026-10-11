<?php
// Evitar la descarga de archivos en el navegador
header('Content-Type: text/html; charset=utf-8');

// Engañar a PHP para que reconozca la conexión HTTPS de Vercel y rompa el bucle
$_SERVER['HTTPS'] = 'on';
$_SERVER['SERVER_PORT'] = 443;

// Activar reporte de errores
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$rootDir = dirname(__DIR__);
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Si la ruta es la raíz
if ($uri === '/' || $uri === '' || $uri === '/index.php') {
    require $rootDir . '/index.php';
    exit;
}

$file = $rootDir . $uri;

// Si se pide una carpeta, buscar su index.php interno
if (is_dir($file)) {
    $file = rtrim($file, '/') . '/index.php';
}

// Cargar el archivo PHP correspondiente
if (file_exists($file) && pathinfo($file, PATHINFO_EXTENSION) === 'php') {
    require $file;
    exit;
}

// Fallback al index principal
if (file_exists($rootDir . '/index.php')) {
    require $rootDir . '/index.php';
} else {
    echo "<h2>404 - Página no encontrada</h2>";
}
