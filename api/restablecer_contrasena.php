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
    // 1. Validar que el código sea correcto y no haya expirado
    $stmt = $conexion->prepare('
        SELECT id FROM codigo_verificacion 
        WHERE LOWER(correo) = :c AND codigo = :o AND expiracion > NOW() 
        LIMIT 1
    ');
    $stmt->execute([':c' => $correo, ':o' => $codigo]);

    if (!$stmt->fetch()) {
        if (ob_get_length()) ob_clean();
        semsad_responder(['status' => 'error', 'mensaje' => 'El enlace o código es inválido o ya expiró. Solicita uno nuevo.']);
    }

    // 2. Encriptar la nueva contraseña con Bcrypt (mismo formato del sistema)
    $hashPassword = password_hash($nuevaPassword, PASSWORD_BCRYPT);

    // 3. Actualizar en la tabla usuario
    $update = $conexion->prepare('UPDATE usuario SET password = :p WHERE LOWER(correo) = :c');
    $update->execute([':p' => $hashPassword, ':c' => $correo]);

    // 4. Eliminar el código para que no se use dos veces
    $conexion->prepare('DELETE FROM codigo_verificacion WHERE LOWER(correo) = :c')->execute([':c' => $correo]);

    if (ob_get_length()) ob_clean();
    semsad_responder(['status' => 'success', 'mensaje' => '¡Contraseña restablecida con éxito! Ya puedes iniciar sesión.']);

} catch (PDOException $e) {
    if (ob_get_length()) ob_clean();
    semsad_responder(['status' => 'error', 'mensaje' => 'Error al guardar la nueva contraseña.'], 500);
}
