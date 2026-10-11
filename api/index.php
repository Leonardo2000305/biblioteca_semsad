<?php
// Engañar a PHP para reconocer la conexión HTTPS de Vercel
$_SERVER['HTTPS'] = 'on';
$_SERVER['SERVER_PORT'] = 443;

$rootDir = dirname(__DIR__);
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Normalizar la ruta eliminando duplicados de barras
$path = urldecode(preg_replace('#/+#', '/', $uri));
$file = $rootDir . $path;

// 1. Si se solicita la raíz '/', cargar el index.php principal
if ($path === '/' || $path === '' || $path === '/index.php') {
    require $rootDir . '/index.php';
    exit;
}

// 2. Si se solicita una carpeta, buscar index.php o index.html dentro de ella
if (is_dir($file)) {
    if (file_exists($file . '/index.php')) {
        $file .= '/index.php';
    } elseif (file_exists($file . '/index.html')) {
        $file .= '/index.html';
    }
}

// 3. Si el archivo existe físicamente en el repositorio
if (file_exists($file) && !is_dir($file)) {
    $ext = pathinfo($file, PATHINFO_EXTENSION);

    // Si es un script PHP, ejecutarlo
    if ($ext === 'php') {
        require $file;
        exit;
    }

    // Si es un archivo estático (.html, .css, .js, etc.), servirlo con su cabecera correcta
    $mimes = [
        'html' => 'text/html; charset=utf-8',
        'htm'  => 'text/html; charset=utf-8',
        'css'  => 'text/css',
        'js'   => 'application/javascript',
        'json' => 'application/json',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
        'svg'  => 'image/svg+xml',
        'ico'  => 'image/x-icon'
    ];

    $contentType = $mimes[$ext] ?? mime_content_type($file) ?: 'application/octet-stream';
    header('Content-Type: ' . $contentType);
    readfile($file);
    exit;
}

// 4. Si la ruta no existe, devolver 404 real en lugar de redirigir a la raíz
http_response_code(404);
header('Content-Type: text/html; charset=utf-8');
echo "<h2>404 - Archivo no encontrado ($path)</h2>";
exit;
