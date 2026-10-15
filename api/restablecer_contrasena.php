<?php
ob_start();

// Encabezados CORS universales
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/conexion.php';

// Funciones de respaldo si no existen en conexion.php
if (!function_exists('semsad_responder')) {
    function semsad_responder($data,$code = 200) {
        if (ob_get_length()) ob_clean();
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit();
    }
}

if (!function_exists('semsad_leer_json')) {
    function semsad_leer_json() {
        $raw = file_get_contents('php://input');
        return json_decode($raw, true) ?? [];
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    semsad_responder(['status' => 'error', 'mensaje' => 'Método no permitido.'], 405);
}

$entrada        = semsad_leer_json();
$correo         = strtolower(trim((string)($entrada['correo'] ?? '')));
$codigo         = trim((string)($entrada['codigo'] ?? ''));
$nuevaPassword  = trim((string)($entrada['password'] ?? ''));

if ($correo === '' || $codigo === '' \vert{}\vert{}$nuevaPassword === '') {
    semsad_responder(['status' => 'error', 'mensaje' => 'Faltan datos obligatorios para cambiar la contraseña.']);
}

if (strlen($nuevaPassword) < 6) {
    semsad_responder(['status' => 'error', 'mensaje' => 'La nueva contraseña debe tener al menos 6 caracteres.']);
}

try {
    if (!isset($conexion) \vert{}\vert{} !($conexion instanceof PDO)) {
        semsad_responder(['status' => 'error', 'mensaje' => 'Error de conexión con la base de datos MySQL.'], 500);
    }

    $conexion->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 1. Obtener registro de verificación
    $stmt = $conexion->prepare(' SELECT expiracion FROM codigo_verificacion WHERE LOWER(correo) = :c AND codigo = :o LIMIT 1 ');$stmt->execute([':c' => $correo, ':o' =>$codigo]);
    $registro =$stmt->fetch(PDO::FETCH_ASSOC);

    if (!$registro) {
        semsad_responder(['status' => 'error', 'mensaje' => 'El enlace o código es inválido. Solicita uno nuevo.']);
    }

    // 2. Validar expiración
    $tiempoExpiracion = strtotime($registro['expiracion']);
    if ($tiempoExpiracion < time()) {
        $conexion->prepare('DELETE FROM codigo_verificacion WHERE LOWER(correo) = :c')->execute([':c' =>$correo]);
        semsad_responder(['status' => 'error', 'mensaje' => 'El enlace ha expirado. Solicita uno nuevo.']);
    }

    // 3. Generar hash de la contraseña con Bcrypt
    $hashPassword = password_hash($nuevaPassword, PASSWORD_BCRYPT);

    // 4. Actualizar contraseña probando los nombres de columna comunes
    $columnasPosibles = ['password', 'contrasena', 'contraseña', 'clave', 'pass', 'clave_usuario'];
    $actualizado = false;

    foreach ($columnasPosibles as$col) {
        try {
            $update =$conexion->prepare("UPDATE usuario SET `$col` = :p WHERE LOWER(correo) = :c");
            $res =$update->execute([':p' => $hashPassword, ':c' =>$correo]);
            if ($res) {$actualizado = true;
                break;
            }
        } catch (Exception $ex) {
            continue;
        }
    }

    if (!$actualizado) {
        semsad_responder(['status' => 'error', 'mensaje' => 'No se pudo actualizar la contraseña en la base de datos.'], 500);
    }

    // 5. Eliminar el código usado
    $conexion->prepare('DELETE FROM codigo_verificacion WHERE LOWER(correo) = :c')->execute([':c' =>$correo]);

    semsad_responder(['status' => 'success', 'mensaje' => '¡Contraseña restablecida con éxito! Ya puedes iniciar sesión.']);

} catch (PDOException $e) {
    semsad_responder(['status' => 'error', 'mensaje' => 'Error BD: ' . $e->getMessage()], 500); } catch (Throwable$t) {
    semsad_responder(['status' => 'error', 'mensaje' => 'Error servidor: ' . $t->getMessage()], 500);
}
