<?php
require_once __DIR__ . '/conexion.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    semsad_responder(['status' => 'error', 'mensaje' => 'Método no permitido.'], 405);
}

$entrada         = semsad_leer_json();
$nombres         = trim((string)($entrada['nombres'] ?? ''));
$apellidos       = trim((string)($entrada['apellidos'] ?? ''));
$correo          = strtolower(trim((string)($entrada['correo'] ?? '')));
$codigoEmpleado  = trim((string)($entrada['codigo_empleado'] ?? ''));
$telefono        = trim((string)($entrada['telefono'] ?? ''));
$clave           = (string)($entrada['clave'] ?? '');

if ($nombres === '' || $correo === '' || $clave === '') {
    semsad_responder(['status' => 'error', 'mensaje' => 'Por favor completa todos los campos requeridos.']);
}
if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
    semsad_responder(['status' => 'error', 'mensaje' => 'El correo no tiene un formato válido.']);
}
if (strlen($clave) < 6) {
    semsad_responder(['status' => 'error', 'mensaje' => 'La contraseña debe tener al menos 6 caracteres.']);
}

try {
    // ¿Ya existe el correo o el código (CI)?
    $check = $conexion->prepare(
        "SELECT id_usuario FROM usuario
          WHERE LOWER(correo) = :correo
             OR (:codigo1 <> '' AND codigo_empleado = :codigo2)
          LIMIT 1"
    );
    $check->execute([':correo' => $correo, ':codigo1' => $codigoEmpleado, ':codigo2' => $codigoEmpleado]);
    if ($check->fetch()) {
        semsad_responder(['status' => 'error', 'mensaje' => 'El correo o CI ya está registrado.']);
    }

    // Todo registro público entra como Estudiante (nunca como administrador).
    $insert = $conexion->prepare(
        "INSERT INTO usuario (nombres, apellidos, correo, clave, id_rol, codigo_empleado, telefono, nivel_permiso, estado)
         VALUES (:nombres, :apellidos, :correo, :clave,
                 (SELECT id_rol FROM rol WHERE nombre = 'Estudiante' LIMIT 1),
                 :codigo, :telefono, 1, 'Activo')"
    );
    $insert->execute([
        ':nombres'   => $nombres,
        ':apellidos' => $apellidos,
        ':correo'    => $correo,
        ':clave'     => password_hash($clave, PASSWORD_BCRYPT),
        ':codigo'    => $codigoEmpleado !== '' ? $codigoEmpleado : null,
        ':telefono'  => $telefono !== '' ? $telefono : null,
    ]);

    semsad_responder(['status' => 'success', 'mensaje' => 'Registro completado con éxito.']);
} catch (PDOException $e) {
    error_log('[SEMSAD] registrar_usuario: ' . $e->getMessage());
    semsad_responder([
        'status'  => 'error',
        'mensaje' => !empty($cfg['debug']) ? 'Error de base de datos: ' . $e->getMessage() : 'Error al registrar en la base de datos.',
    ], 500);
}
