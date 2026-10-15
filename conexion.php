<?php
/**
 * Conexión central a la base de datos (PDO) + cabeceras CORS + utilidades de sesión.
 *
 * IMPORTANTE: aquí NO van contraseñas. Crea el archivo api/config.php en el servidor
 * (AlwaysData) copiando api/config.ejemplo.php. Ese archivo está en .gitignore y no se sube a GitHub.
 * Alternativa: definir las variables de entorno DB_HOST, DB_PORT, DB_NAME, DB_USER y DB_PASS.
 */
date_default_timezone_set('America/La_Paz');

$cfg = [
    'host'            => getenv('DB_HOST') ?: 'mysql-bibliosemsad.alwaysdata.net',
    'port'            => getenv('DB_PORT') ?: '3306',
    'name'            => getenv('DB_NAME') ?: 'bibliosemsad_db',
    'user'            => getenv('DB_USER') ?: 'bibliosemsad',
    'pass'            => getenv('DB_PASS') ?: '',
    'dsn'             => null,   // opcional: DSN completo (solo para pruebas)
    'debug'           => false,  // true = muestra el error real de la BD en la respuesta
    'otp_demo'        => false,  // true = el código de verificación se muestra en pantalla (solo pruebas, si el correo no llega)
    'allowed_origins' => [],     // dominios extra permitidos para CORS, ej. 'https://mi-dominio.com'
];

if (is_file(__DIR__ . '/config.php')) {
    $extra = require __DIR__ . '/config.php';
    if (is_array($extra)) {
        $cfg = array_merge($cfg, $extra);
    }
}

/* ── CORS ───────────────────────────────────────────────────────────────────
 * Si el frontend llama a través de Vercel (/backend/...) no hace falta CORS,
 * pero se deja listo por si se llama directo a AlwaysData o desde localhost.   */
function semsad_cors(array $cfg): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '') {
        $permitido = preg_match('#^https://([a-z0-9-]+\.)*vercel\.app$#i', $origin)
            || preg_match('#^https?://(localhost|127\.0\.0\.1)(:\d+)?$#i', $origin)
            || in_array($origin, (array)$cfg['allowed_origins'], true);

        if ($permitido) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Access-Control-Allow-Credentials: true');
            header('Vary: Origin');
        }
    }
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Accept, X-Requested-With');
    header('Access-Control-Max-Age: 600');
    header('Content-Type: application/json; charset=utf-8');
}

semsad_cors($cfg);

// Las peticiones de "preflight" del navegador terminan aquí, sin tocar la base de datos.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/* ── Utilidades ───────────────────────────────────────────────────────────── */

function semsad_responder(array $datos, int $codigoHttp = 200): void
{
    http_response_code($codigoHttp);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

function semsad_leer_json(): array
{
    $entrada = json_decode(file_get_contents('php://input') ?: '', true);
    return is_array($entrada) ? $entrada : $_POST;
}

function semsad_iniciar_sesion(): void
{
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => $https ? 'None' : 'Lax',
    ]);
    session_start();
}

/** Corta la petición con 401/403 si no hay sesión o el rol no está permitido. */
function semsad_requerir_rol(array $rolesPermitidos): void
{
    semsad_iniciar_sesion();
    if (empty($_SESSION['id_usuario'])) {
        semsad_responder(['status' => 'error', 'mensaje' => 'Debes iniciar sesión.'], 401);
    }
    if (!in_array($_SESSION['rol'] ?? '', $rolesPermitidos, true)) {
        semsad_responder(['status' => 'error', 'mensaje' => 'No tienes permiso para ver esto.'], 403);
    }
}

/* ── Conexión ─────────────────────────────────────────────────────────────── */
try {
    $dsn = $cfg['dsn'] ?: "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['name']};charset=utf8mb4";
    $conexion = new PDO($dsn, $cfg['user'], $cfg['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT            => 8,
    ]);
} catch (PDOException $e) {
    error_log('[SEMSAD] Error de conexión a la BD: ' . $e->getMessage());
    semsad_responder([
        'status'  => 'error',
        'mensaje' => $cfg['debug']
            ? 'Error de conexión a la base de datos: ' . $e->getMessage()
            : 'No se pudo conectar a la base de datos.',
    ], 500);
}
