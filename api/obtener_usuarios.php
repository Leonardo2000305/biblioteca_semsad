<?php
require_once __DIR__ . '/conexion.php';
semsad_requerir_rol(['Administrador']);

try {
    $stmt = $conexion->query("
        SELECT
            u.id_usuario,
            u.id_usuario AS id,
            u.nombres,
            u.apellidos,
            u.correo,
            u.telefono,
            u.codigo_empleado,
            u.estado,
            u.id_rol,
            r.nombre AS rol
        FROM Usuario u
        INNER JOIN Rol r ON r.id_rol = u.id_rol
        ORDER BY u.id_usuario DESC
    ");
    $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
    semsad_responder(['status' => 'success', 'data' => $usuarios, 'usuarios' => $usuarios]);
} catch (Throwable $e) {
    error_log('[SEMSAD] obtener_usuarios: ' . $e->getMessage());
    semsad_responder(['status' => 'error', 'mensaje' => 'No se pudo obtener la lista de usuarios.'], 500);
}
?>