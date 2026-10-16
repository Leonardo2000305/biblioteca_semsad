<?php
require_once 'conexion.php';

try {
    // 1. Total de Títulos de Libros en el Catálogo
    $stmt1 = $conexion->query("SELECT COUNT(*) FROM MaterialBibliografico");
    $total_libros = (int)$stmt1->fetchColumn();

    // 2. Libros (Títulos Únicos) con al menos un Ejemplar Disponible
    $stmt2 = $conexion->query("SELECT COUNT(DISTINCT e.id_material) FROM Ejemplar e INNER JOIN MaterialBibliografico m ON m.id_material = e.id_material WHERE e.estado_ejemplar = 'Disponible'");
    $disponibles = (int)$stmt2->fetchColumn();

    // 3. Préstamos y Reservas Activas en Ventanilla
    $stmt3 = $conexion->query("SELECT COUNT(*) FROM Prestamo WHERE estado = 'Activo'");
    $prestamos_activos = (int)$stmt3->fetchColumn();

    semsad_responder([
        'status'            => 'success',
        'total_libros'      => $total_libros,
        'disponibles'       => $disponibles,
        'prestamos_activos' => $prestamos_activos
    ]);

} catch (PDOException $e) {
    semsad_responder([
        'status'  => 'error',
        'mensaje' => 'Error al consultar dashboard: ' . $e->getMessage()
    ], 500);
}
?>
