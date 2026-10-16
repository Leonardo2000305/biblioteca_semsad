<?php
require_once __DIR__ . '/conexion.php';
semsad_requerir_rol(['Administrador', 'Bibliotecario']);

try {
    $stmt1 = $conexion->query("SELECT COUNT(*) FROM MaterialBibliografico");
    $total_libros = (int)$stmt1->fetchColumn();

    $stmt2 = $conexion->query("
        SELECT COUNT(DISTINCT id_material)
        FROM Ejemplar
        WHERE estado_ejemplar = 'Disponible'
    ");
    $disponibles = (int)$stmt2->fetchColumn();

    // El KPI representa títulos distintos prestados; evita que el mismo título se cuente
    // varias veces por copias o registros duplicados.
    $stmt3 = $conexion->query("
        SELECT COUNT(DISTINCT e.id_material)
        FROM Prestamo p
        INNER JOIN Detalle_Prestamo dp ON dp.id_prestamo = p.id_prestamo
        INNER JOIN Ejemplar e ON e.id_ejemplar = dp.id_ejemplar
        WHERE p.estado IN ('Activo', 'Con Mora')
    ");
    $libros_prestados = (int)$stmt3->fetchColumn();

    $stmt4 = $conexion->query("
        SELECT COUNT(DISTINCT p.id_prestamo)
        FROM Prestamo p
        INNER JOIN Detalle_Prestamo dp ON dp.id_prestamo = p.id_prestamo
        WHERE p.estado IN ('Activo', 'Con Mora')
    ");
    $transacciones_activas = (int)$stmt4->fetchColumn();

    semsad_responder([
        'status' => 'success',
        'total_libros' => $total_libros,
        'disponibles' => $disponibles,
        'prestamos_activos' => $libros_prestados,
        'transacciones_activas' => $transacciones_activas
    ]);
} catch (Throwable $e) {
    error_log('[SEMSAD] obtener_dashboard: ' . $e->getMessage());
    semsad_responder(['status' => 'error', 'mensaje' => 'Error al consultar el dashboard.'], 500);
}
?>