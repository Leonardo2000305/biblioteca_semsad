<?php
require_once __DIR__ . '/conexion.php';
semsad_iniciar_sesion();

$id_usuario = (int)($_SESSION['id_usuario'] ?? 0);
if ($id_usuario <= 0) {
    semsad_responder([
        'status' => 'error',
        'sesion_activa' => false,
        'mensaje' => 'No hay una sesión activa.'
    ]);
}

try {
    $stmt = $conexion->prepare("
        SELECT u.id_usuario, u.nombres, u.apellidos, u.correo,
               u.codigo_empleado, u.estado, r.nombre AS rol
        FROM Usuario u
        LEFT JOIN Rol r ON r.id_rol = u.id_rol
        WHERE u.id_usuario = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $id_usuario]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$usuario || ($usuario['estado'] ?? '') !== 'Activo') {
        semsad_responder([
            'status' => 'error',
            'sesion_activa' => false,
            'mensaje' => 'La cuenta asociada a la sesión no está activa. Inicia sesión nuevamente.'
        ]);
    }

    $nombreCompleto = trim(($usuario['nombres'] ?? '') . ' ' . ($usuario['apellidos'] ?? ''));
    if ($nombreCompleto === '') $nombreCompleto = (string)$usuario['correo'];

    $_SESSION['nombre'] = $nombreCompleto;
    $_SESSION['correo'] = (string)$usuario['correo'];
    $_SESSION['rol'] = (string)($usuario['rol'] ?? '');
    $_SESSION['ci'] = (string)($usuario['codigo_empleado'] ?? '');

    semsad_responder([
        'status' => 'success',
        'sesion_activa' => true,
        'usuario' => [
            'id_usuario' => (int)$usuario['id_usuario'],
            'nombre' => $nombreCompleto,
            'nombreCompleto' => $nombreCompleto,
            'nombres' => (string)($usuario['nombres'] ?? ''),
            'apellidos' => (string)($usuario['apellidos'] ?? ''),
            'correo' => (string)($usuario['correo'] ?? ''),
            'rol' => (string)($usuario['rol'] ?? ''),
            'ci' => (string)($usuario['codigo_empleado'] ?? ''),
            'estado' => (string)($usuario['estado'] ?? '')
        ]
    ]);
} catch (Throwable $e) {
    error_log('[SEMSAD] verificar_sesion: ' . $e->getMessage());
    semsad_responder(['status' => 'error', 'sesion_activa' => false, 'mensaje' => 'No se pudo verificar la sesión en este momento.'], 500);
}
?>