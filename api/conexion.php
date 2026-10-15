<?php
/**
 * Conexión central a la base de datos (PDO) + cabeceras CORS + utilidades de sesión.
 */
date_default_timezone_set('America/La_Paz');

$cfg = [
    'host'            => getenv('DB_HOST') ?: 'mysql-bibliosemsad.alwaysdata.net',
    'port'            => getenv('DB_PORT') ?: '3306',
    'name'            => getenv('DB_NAME') ?: 'bibliosemsad_db',
    'user'            => getenv('DB_USER') ?: 'bibliosemsad',
    'pass'            => getenv('DB_PASS') ?: '',
    'dsn'             => null,   // opcional: DSN completo
    'debug'           => true,   // ACTIVADO: Muestra el error real de MySQL si falla la conexión
    'otp_demo'        => false,
    'allowed_origins' => [],
];

if (is_file(__DIR__ . '/config.php')) {
    $extra = require __DIR__ . '/config.php';
    if (is_array($extra)) {
        $cfg = array_merge($cfg, $extra);
    }
}

/* ── CORS ─────────────────────────────────────────────────────────────────── */
function semsad_cors(array $cfg): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '') {
        $permitido = preg_match('#^https://([a-z0-9_-]+\.)*vercel\.app$#i', $origin)
            || preg_match('#^https?://(localhost|127\.0\.0\.1)(:\d+)?$#i', $origin)
            || in_array($origin, (array)($cfg['allowed_origins'] ?? []), true);

        if ($permitido) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Access-Control-Allow-Credentials: true');
            header('Vary: Origin');
        } else {
            header('Access-Control-Allow-Origin: *');
        }
    } else {
        header('Access-Control-Allow-Origin: *');
    }
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Accept, X-Requested-With, Authorization');
    header('Access-Control-Max-Age: 600');
    header('Content-Type: application/json; charset=utf-8');
}

/* ── Utilidades ───────────────────────────────────────────────────────────── */

if (!function_exists('semsad_responder')) {
    function semsad_responder(array $datos, int $codigoHttp = 200): void
    {
        http_response_code($codigoHttp);
        echo json_encode($datos, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if (!function_exists('semsad_leer_json')) {
    function semsad_leer_json(): array
    {
        $raw = file_get_contents('php://input');
        $entrada = json_decode($raw ?: '', true);
        if (is_array($entrada) && !empty($entrada)) {
            return $entrada;
        }
        return $_POST ?? [];
    }
}

if (!function_exists('semsad_iniciar_sesion')) {
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
}

if (!function_exists('semsad_requerir_rol')) {
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
}

semsad_cors($cfg);

// Responder inmediatamente a peticiones OPTIONS
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/* ── Conexión a la BD ─────────────────────────────────────────────────────── */
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
