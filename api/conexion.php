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
    'frontend_url'    => 'https://bibliotecasemsad.vercel.app', // dominio de la web (para el enlace del correo de recuperación)
    'mail_from'       => 'no-reply@semsad.edu.bo',               // remitente de los correos (usa uno de tu dominio verificado)
    'app_secret'      => '',     // opcional: clave para firmar enlaces. Si está vacía se crea sola en api/.secreto
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
        // Solo la web oficial (o localhost y orígenes listados). Antes cualquier *.vercel.app podía leer respuestas autenticadas.
        $permitido = $origin === rtrim((string)$cfg['frontend_url'], '/')
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

/* Protección CSRF: todas las páginas envían JSON (Content-Type application/json). Un formulario de otro sitio no puede hacerlo. */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') === false) {
    semsad_responder(['status' => 'error', 'mensaje' => 'Solicitud no válida.'], 415);
}

/* ── Esquema: la base puede tener o no ciertas columnas; el código se adapta en vez de fallar ── */
function semsad_tiene_columna(PDO $c, string $tabla, string $columna): bool
{
    static $cache = [];
    $clave = strtolower("$tabla.$columna");
    if (!isset($cache[$clave])) {
        try {
            $q = $c->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND LOWER(table_name) = :t AND LOWER(column_name) = :c');
            $q->execute([':t' => strtolower($tabla), ':c' => strtolower($columna)]);
            $cache[$clave] = (int)$q->fetchColumn() > 0;
        } catch (Throwable $e) {
            $cache[$clave] = false;
        }
    }
    return $cache[$clave];
}

function semsad_tiene_tabla(PDO $c, string $tabla): bool
{
    static $cache = [];
    $clave = strtolower($tabla);
    if (!isset($cache[$clave])) {
        try {
            $q = $c->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND LOWER(table_name) = :t');
            $q->execute([':t' => $clave]);
            $cache[$clave] = (int)$q->fetchColumn() > 0;
        } catch (Throwable $e) {
            $cache[$clave] = false;
        }
    }
    return $cache[$clave];
}

/** Expresión SQL con los autores del libro: columna autores, o si no existe, la tabla Material_Autor + Autor. */
function semsad_expr_autores(PDO $c, string $alias = 'm'): string
{
    if (semsad_tiene_columna($c, 'MaterialBibliografico', 'autores')) {
        return "$alias.autores";
    }
    if (semsad_tiene_tabla($c, 'Material_Autor') && semsad_tiene_tabla($c, 'Autor')) {
        return "(SELECT GROUP_CONCAT(CONCAT(a.nombres, ' ', a.apellidos) ORDER BY ma.orden_autor SEPARATOR ', ')
                   FROM Material_Autor ma INNER JOIN Autor a ON a.id_autor = ma.id_autor
                  WHERE ma.id_material = $alias.id_material)";
    }
    return 'NULL';
}

/** Expresión SQL con el ISBN / código del libro, sea cual sea la columna que exista. */
function semsad_expr_isbn(PDO $c, string $alias = 'm'): string
{
    if (semsad_tiene_columna($c, 'MaterialBibliografico', 'isbn')) {
        return "$alias.isbn";
    }
    if (semsad_tiene_columna($c, 'MaterialBibliografico', 'codigo_isbn_issn')) {
        return "$alias.codigo_isbn_issn";
    }
    return 'NULL';
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

    // Configurar tiempo de vida de la sesión en el servidor a 24 horas (86,400 segundos)
    ini_set('session.gc_maxlifetime', 86400);

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    session_set_cookie_params([
        'lifetime' => 86400, // Duración de la cookie de sesión: 24 horas
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

/* ── Seguridad: enlaces de recuperación firmados ─────────────────────────────
 * El enlace del correo lleva un token firmado con HMAC-SHA256 que:
 *   - caduca (15 min),
 *   - no se puede inventar ni adivinar,
 *   - se invalida solo cuando la contraseña cambia (1 solo uso),
 * sin necesidad de guardarlo en la base de datos.                               */
function semsad_secreto(array $cfg): string
{
    if (!empty($cfg['app_secret'])) {
        return (string)$cfg['app_secret'];
    }
    $archivo = __DIR__ . '/.secreto';
    if (is_file($archivo)) {
        $s = trim((string)file_get_contents($archivo));
        if (strlen($s) >= 32) {
            return $s;
        }
    }
    $s = bin2hex(random_bytes(32));
    if (@file_put_contents($archivo, $s, LOCK_EX) === false) {
        throw new RuntimeException('No se pudo crear api/.secreto (revisa los permisos de la carpeta api).');
    }
    @chmod($archivo, 0600);
    return $s;
}

function semsad_b64u(string $d): string { return rtrim(strtr(base64_encode($d), '+/', '-_'), '='); }

function semsad_crear_token(array $cfg, string $correo, string $hashActual, int $vigenciaSeg = 900): string
{
    $payload = semsad_b64u(json_encode(['c' => $correo, 'e' => time() + $vigenciaSeg]));
    return $payload . '.' . hash_hmac('sha256', $payload . '|' . $hashActual, semsad_secreto($cfg));
}

/** Valida firma, caducidad y que el correo coincida. $hashActual = contraseña guardada HOY del usuario. */
function semsad_validar_token(array $cfg, string $token, string $correo, string $hashActual): bool
{
    $partes = explode('.', $token);
    if (count($partes) !== 2) {
        return false;
    }
    [$payload, $firma] = $partes;
    $esperada = hash_hmac('sha256', $payload . '|' . $hashActual, semsad_secreto($cfg));
    if (!hash_equals($esperada, $firma)) {
        return false;
    }
    $datos = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);
    return is_array($datos) && ($datos['c'] ?? '') === $correo && (int)($datos['e'] ?? 0) >= time();
}

/* ── Auditoría de recuperación de contraseña (la ve el administrador) ───────── */
function semsad_ip(): string
{
    $ip = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')[0]);
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : ($_SERVER['REMOTE_ADDR'] ?? 'Desconocida');
}

/** Registra un intento. Nunca interrumpe el flujo si la auditoría falla. */
function semsad_auditar(PDO $conexion, string $correo, ?int $idUsuario, string $estado): void
{
    try {
        $conexion->prepare(
            'INSERT INTO auditoria_recuperacion (correo_ingresado, id_usuario, estado, ip_origen, fecha_hora)
             VALUES (:c, :u, :e, :ip, :f)'
        )->execute([':c' => $correo, ':u' => $idUsuario, ':e' => $estado, ':ip' => semsad_ip(), ':f' => date('Y-m-d H:i:s')]);
    } catch (Throwable $e) {
        error_log('[SEMSAD] No se pudo registrar la auditoría (' . $estado . '): ' . $e->getMessage());
    }
}

/** Segundos que faltan para poder pedir otro enlace (0 = ya puede). */
function semsad_espera_recuperacion(PDO $conexion, string $correo, int $ventana = 60): int
{
    try {
        $q = $conexion->prepare(
            "SELECT MAX(fecha_hora) FROM auditoria_recuperacion WHERE LOWER(correo_ingresado) = :c AND estado = 'Solicitud enviada'"
        );
        $q->execute([':c' => $correo]);
        $ultima = $q->fetchColumn();
        return $ultima ? max(0, $ventana - (time() - strtotime((string)$ultima))) : 0;
    } catch (Throwable $e) {
        return 0;
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
