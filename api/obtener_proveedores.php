<?php
require_once __DIR__ . '/conexion.php';
semsad_requerir_rol(['Administrador', 'Bibliotecario']);

try {
    $stmt = $conexion->query("
        SELECT p.id_proveedor, p.nombre, p.tipo, p.contacto,
               COUNT(a.id_adquisicion) AS adquisiciones_registradas
        FROM Proveedor p
        LEFT JOIN Adquisicion a ON a.id_proveedor = p.id_proveedor
        GROUP BY p.id_proveedor, p.nombre, p.tipo, p.contacto
        ORDER BY p.nombre ASC
    ");
    $proveedores = $stmt->fetchAll(PDO::FETCH_ASSOC);
    semsad_responder(['status' => 'success', 'proveedores' => $proveedores, 'data' => $proveedores]);
} catch (Throwable $e) {
    error_log('[SEMSAD] obtener_proveedores: ' . $e->getMessage());
    semsad_responder(['status' => 'error', 'mensaje' => 'No se pudo cargar la lista de proveedores.'], 500);
}
?>