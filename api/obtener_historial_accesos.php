<?php
require_once __DIR__ . '/conexion.php';
semsad_requerir_rol(['Administrador', 'Bibliotecario']);

try {
    $q = $conexion->prepare("
        SELECT COUNT(*) FROM information_schema.tables
        WHERE table_schema = DATABASE() AND table_name = 'historial_acceso'
    ");
    $q->execute();
    if ((int)$q->fetchColumn() === 0) {
        semsad_responder([
            'status' => 'success',
            'disponible' => false,
            'registros' => [],
            'mensaje' => 'El historial de accesos requiere la migración opcional de la base de datos.'
        ]);
    }

    $stmt = $conexion->query("
        SELECT h.id_acceso, h.fecha_hora, h.ip_origen, h.agente_usuario,
               u.id_usuario, u.nombres, u.apellidos, u.correo, u.codigo_empleado
        FROM historial_acceso h
        INNER JOIN Usuario u ON u.id_usuario = h.id_usuario
        ORDER BY h.fecha_hora DESC, h.id_acceso DESC
        LIMIT 200
    ");
    semsad_responder(['status' => 'success', 'disponible' => true, 'registros' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
} catch (Throwable $e) {
    error_log('[SEMSAD] obtener_historial_accesos: ' . $e->getMessage());
    semsad_responder(['status' => 'error', 'mensaje' => 'No se pudo consultar el historial de accesos.'], 500);
}
?>