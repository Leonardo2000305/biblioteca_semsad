<?php
// ARCHIVO: conexion_sirve_para_conectar_base_datos.php
// CAPA: BACKEND (API del servidor)
// SIRVE PARA: Es el archivo base de la API: abre la conexión a la base de datos y reúne funciones que usan todos los demás archivos.
// QUÉ HACE: Lee la configuración (api/config.php o variables de entorno), pone las cabeceras CORS y crea la variable $conexion (PDO).
// También define funciones para responder en JSON, manejar la sesión, revisar roles, validar datos y limitar intentos fallidos.
// CONECTA CON: todos los archivos de api/ (los incluyen con require_once); api/config.php (configuración local, no se sube a GitHub); api/plantilla_config_sirve_para_copiar_y_poner_claves.php (plantilla para crear config.php).
/**
 * Conexión central a la base de datos (PDO) + cabeceras CORS + utilidades de sesión.
 *
 * IMPORTANTE: aquí NO van contraseñas. Crea el archivo api/config.php en el servidor
 * (AlwaysData) copiando api/plantilla_config_sirve_para_copiar_y_poner_claves.php. Ese archivo está en .gitignore y no se sube a GitHub.
 * Alternativa: definir las variables de entorno DB_HOST, DB_PORT, DB_NAME, DB_USER y DB_PASS.
 */
date_default_timezone_set('America/La_Paz');

// Valores por defecto. Si existe api/config.php, sus valores reemplazan a estos.
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

// Si existe config.php (con las claves reales), se lee y se mezcla con la configuración por defecto.
if (is_file(__DIR__ . '/config.php')) {
    $extra = require __DIR__ . '/config.php';
    if (is_array($extra)) {
        $cfg = array_merge($cfg, $extra);
    }
}

/* ── CORS ───────────────────────────────────────────────────────────────────
 * Si el frontend llama a través de Vercel (/backend/...) no hace falta CORS,
 * pero se deja listo por si se llama directo a AlwaysData o desde localhost.   */
// Pone las cabeceras CORS: solo la web oficial (y los orígenes permitidos) puede leer las respuestas desde el navegador.
function semsad_cors(array $cfg): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '') {
        // Solo la web oficial (o localhost y orígenes listados). Antes cualquier *.vercel.app podía leer respuestas autenticadas.
        $permitido = $origin === rtrim((string)$cfg['frontend_url'], '/')
            || (!empty($cfg['debug']) && preg_match('#^https?://(localhost|127\.0\.0\.1)(:\d+)?$#i', $origin))
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

// Aplica las cabeceras CORS a la petición actual.
semsad_cors($cfg);

// Las peticiones de "preflight" del navegador terminan aquí, sin tocar la base de datos.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/* ── Utilidades ───────────────────────────────────────────────────────────── */

// Envía la respuesta en JSON con el código HTTP indicado (200 por defecto) y termina la ejecución.
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

/* ── Disponibilidad: fuente de verdad = préstamos activos. El texto de estado del ejemplar puede quedar desfasado. ── */
function semsad_sql_ejemplar_libre(string $a = 'e'): string
{
    // Condición SQL: el ejemplar está libre si no está en mantenimiento ni dado de baja, y si no tiene préstamos activos, vencidos o con mora.
    return "$a.estado_ejemplar NOT IN ('En Mantenimiento', 'Baja') AND NOT EXISTS (
                SELECT 1 FROM detalle_prestamo dpx
                INNER JOIN prestamo px ON px.id_prestamo = dpx.id_prestamo
                WHERE dpx.id_ejemplar = $a.id_ejemplar AND px.estado IN ('Activo', 'Vencido', 'Con Mora'))";
}

/* ── Esquema: la base puede tener o no ciertas columnas; el código se adapta en vez de fallar ── */
function semsad_tiene_columna(PDO $c, string $tabla, string $columna): bool
{
    static $cache = [];
    $clave = strtolower("$tabla.$columna");
    if (!isset($cache[$clave])) {
        try {
            // Pregunta a la base si la columna existe (information_schema). El resultado se guarda en caché para no repetir la consulta.
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
            // Igual que la función anterior, pero para comprobar si existe una tabla.
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
    if (semsad_tiene_columna($c, 'materialbibliografico', 'autores')) {
        return "$alias.autores";
    }
    if (semsad_tiene_tabla($c, 'material_autor') && semsad_tiene_tabla($c, 'autor')) {
        return "(SELECT GROUP_CONCAT(CONCAT(a.nombres, ' ', a.apellidos) ORDER BY ma.orden_autor SEPARATOR ', ')
                   FROM material_autor ma INNER JOIN autor a ON a.id_autor = ma.id_autor
                  WHERE ma.id_material = $alias.id_material)";
    }
    return 'NULL';
}

/** Expresión SQL con el ISBN / código del libro, sea cual sea la columna que exista. */
function semsad_expr_isbn(PDO $c, string $alias = 'm'): string
{
    if (semsad_tiene_columna($c, 'materialbibliografico', 'isbn')) {
        return "$alias.isbn";
    }
    if (semsad_tiene_columna($c, 'materialbibliografico', 'codigo_isbn_issn')) {
        return "$alias.codigo_isbn_issn";
    }
    return 'NULL';
}

// Lee los datos enviados: primero intenta leerlos como JSON; si no lo son, usa los datos del formulario ($_POST).
function semsad_leer_json(): array
{
    $entrada = json_decode(file_get_contents('php://input') ?: '', true);
    return is_array($entrada) ? $entrada : $_POST;
}

function semsad_iniciar_sesion(): void
{
    // Si la sesión ya está iniciada, no se vuelve a iniciar.
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }

    // Configurar tiempo de vida de la sesión en el servidor a 24 horas (86,400 segundos)
    ini_set('session.gc_maxlifetime', 86400);
    // Seguridad: solo se aceptan identificadores de sesión creados por el servidor (evita fijar sesiones ajenas)
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');

    // Detecta si la web se usa con HTTPS, directo o a través del proxy de Vercel.
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    // Configura la cookie de sesión: httponly impide leerla desde JavaScript; secure y SameSite dependen de si hay HTTPS.
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
    // Ruta del archivo donde se guarda la clave secreta cuando no está definida en config.php.
    $archivo = __DIR__ . '/.secreto';
    if (is_file($archivo)) {
        $s = trim((string)file_get_contents($archivo));
        if (strlen($s) >= 32) {
            return $s;
        }
    }
    // Crea una clave aleatoria de 64 caracteres y la guarda en el archivo.
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
    // Parte 1 del token: el correo y la hora de caducidad, codificados en base64.
    $payload = semsad_b64u(json_encode(['c' => $correo, 'e' => time() + $vigenciaSeg]));
    // Parte 2 del token: la firma HMAC-SHA256. Sin la clave secreta no se puede fabricar un token válido.
    return $payload . '.' . hash_hmac('sha256', $payload . '|' . $hashActual, semsad_secreto($cfg));
}

/** Valida firma, caducidad y que el correo coincida. $hashActual = contraseña guardada HOY del usuario. */
function semsad_validar_token(array $cfg, string $token, string $correo, string $hashActual): bool
{
    $partes = explode('.', $token);
    // Un token válido tiene exactamente dos partes separadas por un punto.
    if (count($partes) !== 2) {
        return false;
    }
    [$payload, $firma] = $partes;
    // Calcula la firma correcta con la misma clave y la compara con la que trae el enlace.
    $esperada = hash_hmac('sha256', $payload . '|' . $hashActual, semsad_secreto($cfg));
    // hash_equals compara sin revelar en qué parte fallan las firmas. Si no coinciden, el token no es válido.
    if (!hash_equals($esperada, $firma)) {
        return false;
    }
    // Lee los datos del token y revisa que el correo coincida y que el enlace no haya caducado.
    $datos = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);
    return is_array($datos) && ($datos['c'] ?? '') === $correo && (int)($datos['e'] ?? 0) >= time();
}

/* ── Auditoría de recuperación de contraseña (la ve el administrador) ───────── */
function semsad_ip(): string
{
    // El primer valor de X-Forwarded-For lo puede inventar cualquiera; el último lo añade el proxy (Vercel).
    $partes = array_map('trim', explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
    $ip = (string)end($partes);
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : ($_SERVER['REMOTE_ADDR'] ?? 'Desconocida');
}

/** Registra un intento. Nunca interrumpe el flujo si la auditoría falla. */
function semsad_auditar(PDO $conexion, string $correo, ?int $idUsuario, string $estado): void
{
    try {
        // Guarda una fila en auditoria_recuperacion con el correo, el usuario, el resultado, la IP y la fecha.
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
        // Busca la última solicitud de enlace de ese correo, para saber cuánto falta para poder pedir otro.
        $q = $conexion->prepare(
            "SELECT MAX(fecha_hora) FROM auditoria_recuperacion WHERE LOWER(correo_ingresado) = :c AND estado = 'Solicitud enviada'"
        );
        $q->execute([':c' => $correo]);
        $ultima = $q->fetchColumn();
        // Si nunca pidió un enlace, la espera es 0. Si no, devuelve los segundos que faltan de la ventana de espera.
        return $ultima ? max(0, $ventana - (time() - strtotime((string)$ultima))) : 0;
    } catch (Throwable $e) {
        return 0;
    }
}

/* ── Seguridad: límites de intentos guardados en la base (tabla intentos_seguridad) ──
 * Son independientes de la sesión: borrar cookies o cambiar de navegador no los salta.
 * Si la tabla no existe todavía (db/seguridad_v5.sql), no bloquea nada y la app sigue funcionando. */
function semsad_limite_espera(PDO $c, string $clave): int
{
    try {
        // Lee si esta clave (cuenta o conexión) tiene un bloqueo vigente. Devuelve los segundos que faltan.
        $q = $c->prepare('SELECT bloqueado_hasta FROM intentos_seguridad WHERE clave = :k LIMIT 1');
        $q->execute([':k' => $clave]);
        $hasta = $q->fetchColumn();
        return $hasta ? max(0, strtotime((string)$hasta) - time()) : 0;
    } catch (Throwable $e) {
        return 0;
    }
}

/** Anota un fallo. Al llegar a $max fallos dentro de $ventanaSeg, bloquea la clave $bloqueoSeg segundos. */
function semsad_limite_fallo(PDO $c, string $clave, int $max, int $ventanaSeg, int $bloqueoSeg): void
{
    try {
        $ahora = time();
        $q = $c->prepare('SELECT fallos, primer_fallo FROM intentos_seguridad WHERE clave = :k LIMIT 1');
        $q->execute([':k' => $clave]);
        $fila    = $q->fetch();
        // ¿El fallo anterior ocurrió dentro de la ventana de tiempo? Si no, el conteo empieza de nuevo.
        $dentro  = $fila && ($ahora - strtotime((string)$fila['primer_fallo'])) <= $ventanaSeg;
        $fallos  = $dentro ? (int)$fila['fallos'] + 1 : 1;
        $primer  = $dentro ? strtotime((string)$fila['primer_fallo']) : $ahora;
        $bloqueo = null;
        // Si se llega al máximo de fallos, la clave queda bloqueada el tiempo indicado y el contador vuelve a cero.
        if ($fallos >= $max) {
            $bloqueo = date('Y-m-d H:i:s', $ahora + $bloqueoSeg);
            $fallos  = 0;
            $primer  = $ahora;
        }
        // Guarda el contador en intentos_seguridad. Si la clave ya existe, la actualiza (ON DUPLICATE KEY UPDATE).
        $c->prepare(
            'INSERT INTO intentos_seguridad (clave, fallos, primer_fallo, bloqueado_hasta) VALUES (:k, :f1, :p1, :b1)
             ON DUPLICATE KEY UPDATE fallos = :f2, primer_fallo = :p2, bloqueado_hasta = :b2'
        )->execute([
            ':k' => $clave, ':f1' => $fallos, ':p1' => date('Y-m-d H:i:s', $primer), ':b1' => $bloqueo,
            ':f2' => $fallos, ':p2' => date('Y-m-d H:i:s', $primer), ':b2' => $bloqueo,
        ]);
    } catch (Throwable $e) {
        error_log('[SEMSAD] límite de intentos: ' . $e->getMessage());
    }
}

function semsad_limite_reset(PDO $c, string $clave): void
{
    try {
        // Borra los fallos de esa clave. Se usa cuando el inicio de sesión o el código son correctos.
        $c->prepare('DELETE FROM intentos_seguridad WHERE clave = :k')->execute([':k' => $clave]);
    } catch (Throwable $e) {
        // sin tabla: nada que limpiar
    }
}

/** Comprueba el código de 6 dígitos del registro. Devuelve 'ok', 'incorrecto' o 'bloqueado'.
 *  Tras 5 fallos el código se invalida: hay que pedir uno nuevo y esperar 15 minutos. */
function semsad_verificar_otp(PDO $c, string $correo, string $codigoIngresado): string
{
    // Clave del contador de intentos para el código de verificación de este correo.
    $clave = 'otp:' . $correo;
    // Antes de revisar el código, se mira si esa clave ya está bloqueada.
    if (semsad_limite_espera($c, $clave) > 0) {
        return 'bloqueado';
    }
    // Busca los códigos guardados para ese correo.
    $q = $c->prepare('SELECT codigo, expiracion FROM codigo_verificacion WHERE LOWER(correo) = :c');
    $q->execute([':c' => $correo]);
    // Compara el código escrito con cada código guardado. hash_equals evita trucos por el tiempo de respuesta. También revisa que no haya caducado.
    foreach ($q->fetchAll() as $fila) {
        if (hash_equals((string)$fila['codigo'], $codigoIngresado) && strtotime((string)$fila['expiracion']) >= time()) {
            semsad_limite_reset($c, $clave);
            return 'ok';
        }
    }
    // Cuenta el fallo: a los 5 fallos, el código queda bloqueado 15 minutos.
    semsad_limite_fallo($c, $clave, 5, 900, 900);
    // Si este fallo provocó el bloqueo, se borran los códigos guardados para que ya no sirvan.
    if (semsad_limite_espera($c, $clave) > 0) {
        $c->prepare('DELETE FROM codigo_verificacion WHERE LOWER(correo) = :c')->execute([':c' => $correo]);
        return 'bloqueado';
    }
    return 'incorrecto';
}

/* ── Validaciones de datos personales (también evitan guardar <script> en nombres) ── */
function semsad_nombre_valido(string $texto): bool
{
    // Nombre: solo letras (con tildes y ñ), espacios, puntos, apóstrofes y guiones. Máximo 60 caracteres.
    return (bool)preg_match('/^[\p{L}\p{M}][\p{L}\p{M} \'.\-]{0,59}$/u', $texto);
}

function semsad_correo_valido(string $correo): bool
{
    // Correo: máximo 120 caracteres, con formato válido y sin caracteres peligrosos como < > comillas o espacios.
    return strlen($correo) <= 120
        && filter_var($correo, FILTER_VALIDATE_EMAIL) !== false
        && !preg_match('/[<>"\'\\\\\s]/', $correo);
}

/** Devuelve el motivo si la contraseña no cumple la norma, o null si está bien. */
function semsad_clave_error(string $clave): ?string
{
    // Regla de contraseña: entre 8 y 72 caracteres (bcrypt no usa más de 72).
    if (strlen($clave) < 8) {
        return 'La contraseña debe tener al menos 8 caracteres.';
    }
    if (strlen($clave) > 72) {   // bcrypt solo usa los primeros 72 bytes
        return 'La contraseña no puede superar los 72 caracteres.';
    }
    return null;
}

/** Detalle técnico del error: solo se muestra cuando 'debug' está activado en config.php (pruebas). */
function semsad_detalle_error(Throwable $e): string
{
    global $cfg;
    return !empty($cfg['debug']) ? ' (' . $e->getMessage() . ')' : '';
}

/* ── Conexión ─────────────────────────────────────────────────────────────── */
try {
    // Arma la cadena de conexión a MySQL (host, puerto, base y charset utf8mb4). Si config tiene un DSN completo, se usa ese.
    $dsn = $cfg['dsn'] ?: "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['name']};charset=utf8mb4";
    // Crea la conexión. Los errores de SQL lanzan excepciones, y el tiempo máximo de espera es de 8 segundos.
    $conexion = new PDO($dsn, $cfg['user'], $cfg['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT            => 8,
    ]);
    // NOW() de MySQL debe usar la misma hora que PHP (La Paz, UTC-4). Si el servidor de la base está en UTC,
    // los préstamos aparecían vencidos hasta 4 horas antes (ver reglas_prestamos_sirve_para_multas_y_plazos.php y obtener_multas_sirve_para_cobrar_y_ver_mora.php).
    $conexion->exec("SET time_zone = '-04:00'");
} catch (PDOException $e) {
    // No se pudo conectar: se registra el error y se responde con 500. El detalle solo aparece en modo depuración.
    error_log('[SEMSAD] Error de conexión a la BD: ' . $e->getMessage());
    semsad_responder([
        'status'  => 'error',
        'mensaje' => $cfg['debug']
            ? 'Error de conexión a la base de datos.' . semsad_detalle_error($e)
            : 'No se pudo conectar a la base de datos.',
    ], 500);
}
