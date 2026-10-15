<?php
ob_start();
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
    // 1. Obtener el registro del código de verificación (solo consultamos la columna expiracion)
    $stmt = $conexion->prepare(' SELECT expiracion FROM codigo_verificacion WHERE LOWER(correo) = :c AND codigo = :o LIMIT 1 ');$stmt->execute([':c' => $correo, ':o' =>$codigo]);
    $registro =$stmt->fetch(PDO::FETCH_ASSOC);

    // 2. Validar existencia del token
    if (!$registro) {
        if (ob_get_length()) ob_clean();
        semsad_responder(['status' => 'error', 'mensaje' => 'El enlace o código es inválido. Solicita uno nuevo.']);
    }

    // 3. Validar expiración en PHP (independiente de zonas horarias de la BD)
    $tiempoExpiracion = strtotime($registro['expiracion']);
    if ($tiempoExpiracion < time()) {
        $conexion->prepare('DELETE FROM codigo_verificacion WHERE LOWER(correo) = :c')->execute([':c' =>$correo]);
        if (ob_get_length()) ob_clean();
        semsad_responder(['status' => 'error', 'mensaje' => 'El enlace ha expirado. Solicita uno nuevo.']);
    }

    // 4. Encriptar la nueva contraseña con Bcrypt
    $hashPassword = password_hash($nuevaPassword, PASSWORD_BCRYPT);

    // 5. Detección automática del nombre de la columna en la tabla 'usuario' (password, contrasena, etc.)
    $columnaPassword = 'password';
    try {
        $colQuery =$conexion->query("SHOW COLUMNS FROM usuario");
        if ($colQuery) {
            $columnas =$colQuery->fetchAll(PDO::FETCH_COLUMN);
            foreach ($columnas as$col) {
                if (in_array(strtolower($col), ['password', 'contrasena', 'contraseña', 'clave', 'pass'])) {
                    $columnaPassword =$col;
                    break;
                }
            }
        }
    } catch (Exception $ex) {
        // Mantiene 'password' si no hay permisos de lectura de esquema
    }

    // 6. Actualizar la contraseña en la tabla usuario usando la columna detectada
    $update =$conexion->prepare("UPDATE usuario SET `$columnaPassword` = :p WHERE LOWER(correo) = :c");
    $update->execute([':p' => $hashPassword, ':c' =>$correo]);

    // 7. Eliminar el código de verificación usado (Un solo uso)
    $conexion->prepare('DELETE FROM codigo_verificacion WHERE LOWER(correo) = :c')->execute([':c' =>$correo]);

    if (ob_get_length()) ob_clean();
    semsad_responder(['status' => 'success', 'mensaje' => '¡Contraseña restablecida con éxito! Ya puedes iniciar sesión.']);

} catch (PDOException $e) {
    if (ob_get_length()) ob_clean();
    semsad_responder(['status' => 'error', 'mensaje' => 'Error al guardar la nueva contraseña.'], 500);
}
