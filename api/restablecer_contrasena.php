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

// Configurar PDO para manejar excepciones y evitar Fatal Errors
if (isset($conexion) && $conexion instanceof PDO) {$conexion->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    if (ob_get_length()) ob_clean();
    semsad_responder(['status' => 'error', 'mensaje' => 'Método no permitido.'], 405);
}

$entrada        = semsad_leer_json();
$correo         = strtolower(trim((string)($entrada['correo'] ?? '')));
$codigo         = trim((string)($entrada['codigo'] ?? ''));
$nuevaPassword  = trim((string)($entrada['password'] ?? ''));

if ($correo === '' || $codigo === '' \vert{}\vert{}$nuevaPassword === '') {
    if (ob_get_length()) ob_clean();
    semsad_responder(['status' => 'error', 'mensaje' => 'Faltan datos obligatorios para cambiar la contraseña.']);
}

if (strlen($nuevaPassword) < 6) {
    if (ob_get_length()) ob_clean();
    semsad_responder(['status' => 'error', 'mensaje' => 'La nueva contraseña debe tener al menos 6 caracteres.']);
}

try {
    // 1. Obtener registro de verificación
    $stmt = $conexion->prepare(' SELECT expiracion FROM codigo_verificacion WHERE LOWER(correo) = :c AND codigo = :o LIMIT 1 ');$stmt->execute([':c' => $correo, ':o' =>$codigo]);
    $registro =$stmt->fetch(PDO::FETCH_ASSOC);

    if (!$registro) {
        if (ob_get_length()) ob_clean();
        semsad_responder(['status' => 'error', 'mensaje' => 'El enlace o código es inválido. Solicita uno nuevo.']);
    }

    // 2. Validar expiración en PHP
    $tiempoExpiracion = strtotime($registro['expiracion']);
    if ($tiempoExpiracion < time()) {
        $conexion->prepare('DELETE FROM codigo_verificacion WHERE LOWER(correo) = :c')->execute([':c' =>$correo]);
        if (ob_get_length()) ob_clean();
        semsad_responder(['status' => 'error', 'mensaje' => 'El enlace ha expirado. Solicita uno nuevo.']);
    }

    // 3. Generar hash de la nueva contraseña con Bcrypt
    $hashPassword = password_hash($nuevaPassword, PASSWORD_BCRYPT);

    // 4. Detectar dinámicamente la columna exacta de contraseña en la tabla 'usuario'
    $columnaPassword = null;
    try {
        $qCols =$conexion->query("DESCRIBE usuario");
        if ($qCols) {
            $columnas =$qCols->fetchAll(PDO::FETCH_COLUMN);
            foreach ($columnas as$col) {
                $cLow = strtolower($col);
                if (in_array($cLow, ['password', 'contrasena', 'contraseña', 'clave', 'pass', 'clave_usuario', 'password_usuario'])) {
                    $columnaPassword =$col;
                    break;
                }
            }
        }
    } catch (Exception $e) {
        // En caso de que DESCRIBE no esté disponible
    }

    $columnasAProbar = $columnaPassword ? [$columnaPassword] : ['password', 'contrasena', 'contraseña', 'clave', 'pass'];
    $actualizado = false;

    foreach ($columnasAProbar as$col) {
        try {
            $update =$conexion->prepare("UPDATE usuario SET `$col` = :p WHERE LOWER(correo) = :c");
            if ($update && $update->execute([':p' =>$hashPassword, ':c' => $correo])) {$actualizado = true;
                break;
            }
        } catch (Exception $ex) {
            continue;
        }
    }

    if (!$actualizado) {
        if (ob_get_length()) ob_clean();
        semsad_responder(['status' => 'error', 'mensaje' => 'No se pudo actualizar la contraseña en la base de datos.'], 500);
    }

    // 5. Eliminar el código usado (Un solo uso)
    $conexion->prepare('DELETE FROM codigo_verificacion WHERE LOWER(correo) = :c')->execute([':c' =>$correo]);

    if (ob_get_length()) ob_clean();
    semsad_responder(['status' => 'success', 'mensaje' => '¡Contraseña restablecida con éxito! Ya puedes iniciar sesión.']);

} catch (PDOException $e) {
    if (ob_get_length()) ob_clean();
    semsad_responder(['status' => 'error', 'mensaje' => 'Error en la base de datos: ' . $e->getMessage()], 500); } catch (Throwable$t) {
    if (ob_get_length()) ob_clean();
    semsad_responder(['status' => 'error', 'mensaje' => 'Error en el servidor: ' . $t->getMessage()], 500);
}
