<?php
ob_start();

// Encabezados CORS para permitir peticiones desde Vercel
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/conexion.php';

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
    // 1. Obtener el registro del código de verificación (solo la columna expiracion)
    $stmt = $conexion->prepare(' SELECT expiracion FROM codigo_verificacion WHERE LOWER(correo) = :c AND codigo = :o LIMIT 1 ');$stmt->execute([':c' => $correo, ':o' =>$codigo]);
    $registro =$stmt->fetch(PDO::FETCH_ASSOC);

    // 2. Validar existencia del token
    if (!$registro) {
        if (ob_get_length()) ob_clean();
        semsad_responder(['status' => 'error', 'mensaje' => 'El enlace o código es inválido. Solicita uno nuevo.']);
    }

    // 3. Validar expiración en PHP (independiente de zonas horarias)
    $tiempoExpiracion = strtotime($registro['expiracion']);
    if ($tiempoExpiracion < time()) {
        $conexion->prepare('DELETE FROM codigo_verificacion WHERE LOWER(correo) = :c')->execute([':c' =>$correo]);
        if (ob_get_length()) ob_clean();
        semsad_responder(['status' => 'error', 'mensaje' => 'El enlace ha expirado. Solicita uno nuevo.']);
    }

    // 4. Encriptar la nueva contraseña con Bcrypt
    $hashPassword = password_hash($nuevaPassword, PASSWORD_BCRYPT);

    // 5. Intentar actualizar en la tabla 'usuario' probando los nombres de columna comunes
    $columnasPosibles = ['password', 'contrasena', 'contraseña', 'clave'];
    $actualizado = false;

    foreach ($columnasPosibles as$col) {
        try {
            $update =$conexion->prepare("UPDATE usuario SET `$col` = :p WHERE LOWER(correo) = :c");
            $res =$update->execute([':p' => $hashPassword, ':c' =>$correo]);
            if ($res) {$actualizado = true;
                break;
            }
        } catch (PDOException $ex) {
            // Si la columna no existe en MySQL, probar con la siguiente
            continue;
        }
    }

    if (!$actualizado) {
        if (ob_get_length()) ob_clean();
        semsad_responder(['status' => 'error', 'mensaje' => 'No se pudo actualizar la contraseña en la base de datos.'], 500);
    }

    // 6. Eliminar el código de verificación usado (Un solo uso)
    $conexion->prepare('DELETE FROM codigo_verificacion WHERE LOWER(correo) = :c')->execute([':c' =>$correo]);

    if (ob_get_length()) ob_clean();
    semsad_responder(['status' => 'success', 'mensaje' => '¡Contraseña restablecida con éxito! Ya puedes iniciar sesión.']);

} catch (PDOException $e) {
    if (ob_get_length()) ob_clean();
    semsad_responder(['status' => 'error', 'mensaje' => 'Error al guardar la nueva contraseña.'], 500);
}
