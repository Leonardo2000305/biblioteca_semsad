<?php
require_once __DIR__ . '/conexion.php';

// La auditoría de seguridad es solo para el personal de la biblioteca.
semsad_requerir_rol(['Administrador', 'Bibliotecario']);

try {
    // Une cada intento con los datos del usuario (si la cuenta existe) para que el administrador sepa quién fue.
    $stmt = $conexion->query(
        'SELECT a.correo_ingresado, a.fecha_hora, a.estado, a.ip_origen,
                u.nombres, u.apellidos, u.codigo_empleado, u.telefono
           FROM auditoria_recuperacion a
      LEFT JOIN usuario u ON u.id_usuario = a.id_usuario
       ORDER BY a.fecha_hora DESC
          LIMIT 200'
    );
    semsad_responder(['status' => 'success', 'data' => $stmt->fetchAll()]);
} catch (PDOException $e) {
    error_log('[SEMSAD] obtener_auditoria: ' . $e->getMessage());
    semsad_responder(['status' => 'error', 'mensaje' => 'No se pudo obtener la auditoría.'], 500);
}
