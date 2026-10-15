<?php
ob_start();
require_once __DIR__ . '/conexion.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    if (ob_get_length()) ob_clean();
    semsad_responder(['status' => 'error', 'mensaje' => 'Método no permitido.'], 405);
}

$entrada    = semsad_leer_json();
$correo     = strtolower(trim((string)($entrada['correo'] ?? '')));
$codigoOtp  = trim((string)($entrada['codigo_otp'] ?? '')));
$nuevaClave = (string)($entrada['nueva_clave'] ?? '');

if ($correo === '' || $codigoOtp === '' || $nuevaClave === '') {
    if (ob_get_length()) ob_clean();
    semsad_responder(['status' => 'error', 'mensaje' => 'Todos los campos son obligatorios.']);
}

if (strlen($nuevaClave) < 6) {
    if (ob_get_length()) ob_clean();
    semsad_responder(['status' => 'error', 'mensaje' => 'La nueva contraseña debe tener al menos 6 caracteres.']);
}

try {
    // 1. Validar el código OTP
    $q = $conexion->prepare('SELECT codigo, expiracion FROM codigo_verificacion WHERE LOWER(correo) = :c');
    $q->execute([':c' => $correo]);
    $otpValido = false;

    foreach ($q->fetchAll() as $fila) {
        if (hash_equals((string)$fila['codigo'], $codigoOtp) && strtotime($fila['expiracion']) >= time()) {
            $otpValido = true;
            break;
        }
    }

    if (!$otpValido) {
        if (ob_get_length()) ob_clean();
        semsad_responder(['status' => 'error', 'mensaje' => 'El código de verificación es incorrecto o ha expirado.']);
    }

    // 2. Actualizar la contraseña encriptada con Bcrypt
    $hashClave = password_hash($nuevaClave, PASSWORD_BCRYPT);
    $upd = $conexion->prepare('UPDATE usuario SET contrasena = :p WHERE LOWER(correo) = :c');
    $upd->execute([':p' => $hashClave, ':c' => $correo]);

    // 3. Eliminar el código OTP usado
    $conexion->prepare('DELETE FROM codigo_verificacion WHERE LOWER(correo) = :c')->execute([':c' => $correo]);

    if (ob_get_length()) ob_clean();
    semsad_responder(['status' => 'success', 'mensaje' => '¡Contraseña actualizada con éxito! Ya puedes iniciar sesión.']);
} catch (PDOException $e) {
    if (ob_get_length()) ob_clean();
    semsad_responder(['status' => 'error', 'mensaje' => 'Error al actualizar la contraseña.'], 500);
}
