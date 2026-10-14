<?php
require_once __DIR__ . '/conexion.php';

// La auditoría de seguridad es solo para el personal de la biblioteca.
semsad_requerir_rol(['Administrador', 'Bibliotecario']);

try {
    $stmt = $conexion->query(
        'SELECT id, correo_ingresado, fecha_hora, estado, ip_origen
           FROM auditoria_claves
       ORDER BY fecha_hora DESC'
    );
    semsad_responder(['status' => 'success', 'data' => $stmt->fetchAll()]);
} catch (PDOException $e) {
    error_log('[SEMSAD] obtener_auditoria: ' . $e->getMessage());
    semsad_responder(['status' => 'error', 'mensaje' => 'No se pudo obtener la auditoría.'], 500);
}
