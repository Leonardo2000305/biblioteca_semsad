<?php
ob_start();
require_once __DIR__ . '/conexion.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    if (ob_get_length()) ob_clean();
    semsad_responder(['status' => 'error', 'mensaje' => 'Método no permitido.'], 405);
}

$entrada        = semsad_leer_json();
$nombres        = trim((string)($entrada['nombres'] ?? ''));
$apellidos      = trim((string)($entrada['apellidos'] ?? ''));
$correo         = strtolower(trim((string)($entrada['correo'] ?? '')));
$codigoEmpleado = trim((string)($entrada['codigo_empleado'] ?? ''));
$telefono       = trim((string)($entrada['telefono'] ?? ''));
$clave          = (string)($entrada['clave'] ?? '');
$codigoOtp      = trim((string)($entrada['codigo_otp'] ?? ''));

if ($nombres === '' || $apellidos === '' || $correo === '' || $codigoEmpleado === '' || $clave === '' || $codigoOtp === '') {
    if (ob_get_length()) ob_clean();
    semsad_responder(['status' => 'error', 'mensaje' => 'Todos los datos requeridos deben ser completados.']);
}

if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
    if (ob_get_length()) ob_clean();
    semsad_responder(['status' => 'error', 'mensaje' => 'El correo electrónico no tiene un formato válido.']);
}

if (strlen($clave) < 6) {
    if (ob_get_length()) ob_clean();
    semsad_responder(['status' => 'error', 'mensaje' => 'La contraseña debe tener al menos 6 caracteres.']);
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

    // 2. Comprobar duplicados en la base de datos
    $dup = $conexion->prepare(
        'SELECT 1 FROM usuario WHERE LOWER(correo) = :c OR codigo_empleado = :ci OR (:t1 <> \'\' AND telefono = :t2) LIMIT 1'
    );
    $dup->execute([':c' => $correo, ':ci' => $codigoEmpleado, ':t1' => $telefono, ':t2' => $telefono]);
    if ($dup->fetch()) {
        if (ob_get_length()) ob_clean();
        semsad_responder(['status' => 'error', 'mensaje' => 'El correo, la CI o el teléfono ya están registrados.']);
    }

    // 3. Crear el usuario
    $conexion->prepare(
        "INSERT INTO usuario (nombres, apellidos, correo, contrasena, id_rol, codigo_empleado, telefono, nivel_permiso, estado)
         VALUES (:n, :a, :c, :p, (SELECT id_rol FROM rol WHERE nombre = 'Estudiante' LIMIT 1), :ci, :t, 1, 'Activo')"
    )->execute([
        ':n'  => $nombres,
        ':a'  => $apellidos,
        ':c'  => $correo,
        ':p'  => password_hash($clave, PASSWORD_BCRYPT),
        ':ci' => $codigoEmpleado,
        ':t'  => $telefono !== '' ? $telefono : null,
    ]);

    // 4. Limpiar el código usado
    $conexion->prepare('DELETE FROM codigo_verificacion WHERE LOWER(correo) = :c')->execute([':c' => $correo]);

    if (ob_get_length()) ob_clean();
    semsad_responder(['status' => 'success', 'mensaje' => '¡Registro exitoso! Tu cuenta ha sido creada.']);
} catch (PDOException $e) {
    error_log('[SEMSAD] registrar_usuario: ' . $e->getMessage());
    if (ob_get_length()) ob_clean();
    semsad_responder([
        'status'  => 'error',
        'mensaje' => !empty($cfg['debug']) ? 'Error de servidor: ' . $e->getMessage() : 'Error al registrar en la base de datos.',
    ], 500);
}
