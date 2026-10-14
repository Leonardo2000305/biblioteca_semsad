<?php
require_once __DIR__ . '/conexion.php';

// Solo el personal de la biblioteca puede ver el padrón de usuarios.
semsad_requerir_rol(['Administrador', 'Bibliotecario']);

try {
    $stmt = $conexion->query(
        "SELECT u.id_usuario AS id, u.nombres, u.apellidos, u.correo, u.telefono,
                u.codigo_empleado, u.estado, r.nombre AS rol
           FROM usuario u
      LEFT JOIN rol r ON r.id_rol = u.id_rol
       ORDER BY u.id_usuario DESC"
    );
    semsad_responder(['status' => 'success', 'data' => $stmt->fetchAll()]);
} catch (PDOException $e) {
    error_log('[SEMSAD] obtener_usuarios: ' . $e->getMessage());
    semsad_responder(['status' => 'error', 'mensaje' => 'No se pudo obtener la lista de usuarios.'], 500);
}
