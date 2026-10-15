<?php
/**
 * API para restablecer la contraseña del usuario mediante token de verificación.
 */
ob_start();

// 1. Cargar la conexión centralizada (maneja CORS, configuración y PDO)
require_once __DIR__ . '/conexion.php';

// Ocultar despliegue directo de errores HTML para salida JSON pura
ini_set('display_errors', '0');
error_reporting(E_ALL);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    semsad_responder(['status' => 'error', 'mensaje' => 'Método no permitido.'], 405);
}

// 2. Leer datos de entrada (JSON o POST)
$entrada = semsad_leer_json();

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

    // 1. Buscar el token en codigo_verificacion
    $stmt = $conexion->prepare(' SELECT expiracion FROM codigo_verificacion WHERE LOWER(correo) = :c AND codigo = :o LIMIT 1 ');$stmt->execute([':c' => $correo, ':o' =>$codigo]);
    $registro =$stmt->fetch(PDO::FETCH_ASSOC);

    if (!$registro) {
        semsad_responder(['status' => 'error', 'mensaje' => 'El enlace o código es inválido. Solicita uno nuevo.']);
    }

    // 2. Validar expiración del token
    $tiempoExpiracion = strtotime($registro['expiracion']);
    if ($tiempoExpiracion < time()) {
        $conexion->prepare('DELETE FROM codigo_verificacion WHERE LOWER(correo) = :c')->execute([':c' =>$correo]);
        semsad_responder(['status' => 'error', 'mensaje' => 'El enlace ha expirado. Solicita uno nuevo.']);
    }

    // 3. Generar hash de la nueva contraseña con Bcrypt
    $hashPassword = password_hash($nuevaPassword, PASSWORD_BCRYPT);

    // 4. Actualizar contraseña probando columnas posibles en la tabla usuario
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
        semsad_responder(['status' => 'error', 'mensaje' => 'No se pudo actualizar la contraseña en la base de datos.'], 500);
    }

    // 5. Eliminar el código de verificación usado (un solo uso)
    $conexion->prepare('DELETE FROM codigo_verificacion WHERE LOWER(correo) = :c')->execute([':c' =>$correo]);

    semsad_responder(['status' => 'success', 'mensaje' => '¡Contraseña restablecida con éxito! Ya puedes iniciar sesión.']);

} catch (PDOException $e) {
    semsad_responder(['status' => 'error', 'mensaje' => 'Error BD: ' . $e->getMessage()], 500); } catch (Throwable$t) {
    semsad_responder(['status' => 'error', 'mensaje' => 'Error servidor: ' . $t->getMessage()], 500);
}
