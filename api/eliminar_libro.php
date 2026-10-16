<?php
require_once __DIR__ . '/conexion.php';
semsad_requerir_rol(['Administrador', 'Bibliotecario']);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    semsad_responder(['status' => 'error', 'mensaje' => 'Método no permitido.'], 405);
}
$input = semsad_leer_json();
$id_material = (int)($input['id_material'] ?? 0);
if ($id_material <= 0) {
    semsad_responder(['status' => 'error', 'mensaje' => 'ID de libro no válido.'], 400);
}

try {
    $stmtExists = $conexion->prepare("SELECT titulo FROM MaterialBibliografico WHERE id_material = :id LIMIT 1");
    $stmtExists->execute([':id' => $id_material]);
    $titulo = $stmtExists->fetchColumn();
    if (!$titulo) semsad_responder(['status' => 'error', 'mensaje' => 'El libro no existe.'], 404);

    $stmtPrestamos = $conexion->prepare("
        SELECT COUNT(*)
        FROM Detalle_Prestamo dp
        INNER JOIN Ejemplar e ON e.id_ejemplar = dp.id_ejemplar
        WHERE e.id_material = :id
    ");
    $stmtPrestamos->execute([':id' => $id_material]);
    if ((int)$stmtPrestamos->fetchColumn() > 0) {
        semsad_responder([
            'status' => 'error',
            'mensaje' => 'No se elimina este libro porque tiene historial de préstamos. Para conservar la auditoría, marca sus ejemplares como Baja en el inventario.'
        ], 409);
    }

    $stmtReservas = $conexion->prepare("SELECT COUNT(*) FROM Reserva WHERE id_material = :id");
    $stmtReservas->execute([':id' => $id_material]);
    if ((int)$stmtReservas->fetchColumn() > 0) {
        semsad_responder([
            'status' => 'error',
            'mensaje' => 'No se elimina este libro porque tiene reservas registradas. Conserva los datos históricos.'
        ], 409);
    }

    $conexion->beginTransaction();
    $conexion->prepare("DELETE FROM Material_Autor WHERE id_material = :id")->execute([':id' => $id_material]);
    $conexion->prepare("DELETE FROM Ejemplar WHERE id_material = :id")->execute([':id' => $id_material]);
    $conexion->prepare("DELETE FROM MaterialBibliografico WHERE id_material = :id")->execute([':id' => $id_material]);
    $conexion->commit();

    semsad_responder(['status' => 'success', 'mensaje' => "El libro \"$titulo\" se eliminó del inventario."]);
} catch (Throwable $e) {
    if ($conexion->inTransaction()) $conexion->rollBack();
    error_log('[SEMSAD] eliminar_libro: ' . $e->getMessage());
    semsad_responder(['status' => 'error', 'mensaje' => 'No se pudo eliminar el libro. No se alteró el historial de la biblioteca.'], 500);
}
?>