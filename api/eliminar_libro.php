<?php
require_once 'conexion.php';
// Solo personal de la biblioteca (Administrador / Bibliotecario)
semsad_requerir_rol(['Administrador', 'Bibliotecario']);
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
    $id_material = (int)($input['id_material'] ?? $_POST['id_material'] ?? 0);

    if ($id_material <= 0) {
        semsad_responder(['status' => 'error', 'mensaje' => 'ID de libro no válido.']);
    }

    try {
        $stmtActivos = $conexion->prepare("
            SELECT COUNT(*) 
            FROM prestamo p
            INNER JOIN detalle_prestamo dp ON p.id_prestamo = dp.id_prestamo
            INNER JOIN ejemplar e ON dp.id_ejemplar = e.id_ejemplar
            WHERE e.id_material = :id AND p.estado IN ('Activo', 'Vencido', 'Con Mora')
        ");
        $stmtActivos->execute([':id' => $id_material]);
        if ((int)$stmtActivos->fetchColumn() > 0) {
            semsad_responder([
                'status'  => 'error',
                'mensaje' => 'No se puede eliminar este libro porque tiene préstamos activos pendientes de devolución.'
            ]);
        }

        $conexion->beginTransaction();

        $conexion->exec("
            DELETE FROM detalle_prestamo 
            WHERE id_ejemplar IN (SELECT id_ejemplar FROM ejemplar WHERE id_material = $id_material)
        ");

        $stmtE = $conexion->prepare("DELETE FROM ejemplar WHERE id_material = :id");
        $stmtE->execute([':id' => $id_material]);

        $stmtM = $conexion->prepare("DELETE FROM materialbibliografico WHERE id_material = :id");
        $stmtM->execute([':id' => $id_material]);

        $conexion->commit();

        semsad_responder(['status' => 'success', 'mensaje' => 'El libro y sus ejemplares han sido eliminados correctamente del inventario.']);

    } catch (Exception $e) {
        if ($conexion->inTransaction()) $conexion->rollBack();
        semsad_responder(['status' => 'error', 'mensaje' => 'Error al eliminar libro: ' . $e->getMessage()], 500);
    }
} else {
    semsad_responder(['status' => 'error', 'mensaje' => 'Método no permitido.'], 405);
}
