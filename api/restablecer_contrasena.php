<?php
// 1. Encabezados CORS universales ANTES de cualquier include o conexión
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

// 2. Responder a preflight OPTIONS de inmediato
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// 3. Ocultar errores HTML y preparar buffer
ini_set('display_errors', '0');
error_reporting(E_ALL);
ob_start();

// Función de respuesta JSON garantizada
function responder_json($data,$code = 200) {
    if (ob_get_length()) ob_clean();
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header("Access-Control-Allow-Origin: *");
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit();
}

// 4. Conexión segura a la base de datos
try {
    require_once __DIR__ . '/conexion.php';
} catch (Throwable $t) {
    responder_json(['status' => 'error', 'mensaje' => 'Error de conexión con la base de datos MySQL.'], 500);
}

// 5. Leer datos de entrada (JSON o POST)
$rawInput = file_get_contents('php://input');
$entrada = json_decode($rawInput, true);
if (!is_array($entrada) \vert{}\vert{} empty($entrada)) {
    $entrada =$_POST ?? [];
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    responder_json(['status' => 'error', 'mensaje' => 'Método no permitido.'], 405);
}

$correo         = strtolower(trim((string)($entrada['correo'] ?? '')));
$codigo         = trim((string)($entrada['codigo'] ?? ''));
$nuevaPassword  = trim((string)($entrada['password'] ?? ''));

if ($correo === '' || $codigo === '' \vert{}\vert{}$nuevaPassword === '') {
    responder_json(['status' => 'error', 'mensaje' => 'Faltan datos obligatorios para cambiar la contraseña.']);
}

if (strlen($nuevaPassword) < 6) {
    responder_json(['status' => 'error', 'mensaje' => 'La nueva contraseña debe tener al menos 6 caracteres.']);
}

try {
    if (!isset($conexion) \vert{}\vert{} !($conexion instanceof PDO)) {
        responder_json(['status' => 'error', 'mensaje' => 'Error de conexión con la base de datos MySQL.'], 500);
    }

    $conexion->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Obtener registro de verificación
    $stmt = $conexion->prepare(' SELECT expiracion FROM codigo_verificacion WHERE LOWER(correo) = :c AND codigo = :o LIMIT 1 ');$stmt->execute([':c' => $correo, ':o' =>$codigo]);
    $registro =$stmt->fetch(PDO::FETCH_ASSOC);

    if (!$registro) {
        responder_json(['status' => 'error', 'mensaje' => 'El enlace o código es inválido. Solicita uno nuevo.']);
    }

    // Validar expiración (independiente de zonas horarias)
    $tiempoExpiracion = strtotime($registro['expiracion']);
    if ($tiempoExpiracion < time()) {
        $conexion->prepare('DELETE FROM codigo_verificacion WHERE LOWER(correo) = :c')->execute([':c' =>$correo]);
        responder_json(['status' => 'error', 'mensaje' => 'El enlace ha expirado. Solicita uno nuevo.']);
    }

    // Generar hash Bcrypt
    $hashPassword = password_hash($nuevaPassword, PASSWORD_BCRYPT);

    // Actualizar contraseña probando columnas probables
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
            try {
                $update =$conexion->prepare("UPDATE usuario SET `$col` = :p WHERE LOWER(email) = :c");
                $res =$update->execute([':p' => $hashPassword, ':c' =>$correo]);
                if ($res) {$actualizado = true;
                    break;
                }
            } catch (Exception $ex2) {
                continue;
            }
        }
    }

    if (!$actualizado) {
        responder_json(['status' => 'error', 'mensaje' => 'No se pudo actualizar la contraseña en la base de datos.'], 500);
    }

    // Eliminar el código usado
    $conexion->prepare('DELETE FROM codigo_verificacion WHERE LOWER(correo) = :c')->execute([':c' =>$correo]);

    responder_json(['status' => 'success', 'mensaje' => '¡Contraseña restablecida con éxito! Ya puedes iniciar sesión.']);

} catch (PDOException $e) {
    responder_json(['status' => 'error', 'mensaje' => 'Error BD: ' . $e->getMessage()], 500); } catch (Throwable$t) {
    responder_json(['status' => 'error', 'mensaje' => 'Error servidor: ' . $t->getMessage()], 500);
}
