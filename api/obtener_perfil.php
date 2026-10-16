<?php
require_once __DIR__ . '/conexion.php';
semsad_iniciar_sesion();

$id_usuario = (int)($_SESSION['id_usuario'] ?? 0);
if ($id_usuario <= 0) semsad_responder(['status' => 'error', 'mensaje' => 'Inicia sesión nuevamente.'], 401);

try {
    $columns = $conexion->query("SHOW COLUMNS FROM Usuario")->fetchAll(PDO::FETCH_COLUMN, 0);
    $hasCarrera = in_array('carrera', $columns, true);
    $hasFoto = in_array('fotografia', $columns, true);
    $hasUltimoAcceso = in_array('ultimo_acceso', $columns, true);

    $extra = '';
    if ($hasCarrera) $extra .= ', u.carrera';
    if ($hasFoto) $extra .= ', u.fotografia';
    if ($hasUltimoAcceso) $extra .= ', u.ultimo_acceso';

    $sql = "
        SELECT u.id_usuario, u.nombres, u.apellidos, u.correo, u.codigo_empleado,
               u.telefono, u.estado, r.nombre AS rol
               $extra
        FROM Usuario u
        LEFT JOIN Rol r ON r.id_rol = u.id_rol
        WHERE u.id_usuario = :id
        LIMIT 1
    ";
    $stmt = $conexion->prepare($sql);
    $stmt->execute([':id' => $id_usuario]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$u) semsad_responder(['status' => 'error', 'mensaje' => 'No se encontró el perfil.'], 404);

    $nombre = trim(($u['nombres'] ?? '') . ' ' . ($u['apellidos'] ?? ''));
    semsad_responder([
        'status' => 'success',
        'perfil' => [
            'id_usuario' => (int)$u['id_usuario'],
            'nombres' => (string)$u['nombres'],
            'apellidos' => (string)$u['apellidos'],
            'nombre_completo' => $nombre,
            'correo' => (string)$u['correo'],
            'codigo_empleado' => (string)($u['codigo_empleado'] ?? ''),
            'telefono' => (string)($u['telefono'] ?? ''),
            'estado' => (string)($u['estado'] ?? ''),
            'rol' => (string)($u['rol'] ?? ''),
            'carrera' => $hasCarrera ? (string)($u['carrera'] ?? '') : '',
            'fotografia' => $hasFoto ? (string)($u['fotografia'] ?? '') : '',
            'ultimo_acceso' => $hasUltimoAcceso && !empty($u['ultimo_acceso']) ? date('d/m/Y H:i', strtotime($u['ultimo_acceso'])) : '',
            'campos_perfil_avanzado_disponibles' => $hasCarrera && $hasFoto
        ]
    ]);
} catch (Throwable $e) {
    error_log('[SEMSAD] obtener_perfil: ' . $e->getMessage());
    semsad_responder(['status' => 'error', 'mensaje' => 'No se pudo consultar tu perfil.'], 500);
}
?>