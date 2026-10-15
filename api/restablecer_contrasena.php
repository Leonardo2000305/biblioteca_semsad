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

if ($correo === '' || $codigo === '' || $nuevaPassword === '') {
    if (ob_get_length()) ob_clean();
    semsad_responder(['status' => 'error', 'mensaje' => 'Faltan datos obligatorios para cambiar la contraseña.']);
}

if (strlen($nuevaPassword) < 6) {
    if (ob_get_length()) ob_clean();
    semsad_responder(['status' => 'error', 'mensaje' => 'La nueva contraseña debe tener al menos 6 caracteres.']);
}

try {
    // 1. Obtener el registro del código de verificación
    $stmt = $conexion->prepare('
        SELECT id, expiracion 
        FROM codigo_verificacion 
        WHERE LOWER(correo) = :c AND codigo = :o 
        LIMIT 1
    ');
    $stmt->execute([':c' => $correo, ':o' => $codigo]);
    $registro = $stmt->fetch(PDO::FETCH_ASSOC);

    // 2. Validar existencia del token
    if (!$registro) {
        if (ob_get_length()) ob_clean();
        semsad_responder(['status' => 'error', 'mensaje' => 'El enlace o código es inválido. Solicita uno nuevo.']);
    }

    // 3. Validar expiración en PHP (previene errores de zona horaria de MySQL)
    $tiempoExpiracion = strtotime($registro['expiracion']);
    if ($tiempoExpiracion < time()) {
        $conexion->prepare('DELETE FROM codigo_verificacion WHERE LOWER(correo) = :c')->execute([':c' => $correo]);
        if (ob_get_length()) ob_clean();
        semsad_responder(['status' => 'error', 'mensaje' => 'El enlace ha expirado (más de 15 min). Solicita uno nuevo.']);
    }

    // 4. Encriptar la nueva contraseña con Bcrypt
    $hashPassword = password_hash($nuevaPassword, PASSWORD_BCRYPT);

    // 5. Actualizar la contraseña en la tabla usuario
    $update = $conexion->prepare('UPDATE usuario SET password = :p WHERE LOWER(correo) = :c');
    $update->execute([':p' => $hashPassword, ':c' => $correo]);

    // 6. Eliminar el código usado (Un solo uso)
    $conexion->prepare('DELETE FROM codigo_verificacion WHERE LOWER(correo) = :c')->execute([':c' => $correo]);

    if (ob_get_length()) ob_clean();
    semsad_responder(['status' => 'success', 'mensaje' => '¡Contraseña restablecida con éxito! Ya puedes iniciar sesión.']);

} catch (PDOException $e) {
    if (ob_get_length()) ob_clean();
    semsad_responder(['status' => 'error', 'mensaje' => 'Error al guardar la nueva contraseña.'], 500);
}
