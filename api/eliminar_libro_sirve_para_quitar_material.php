<?php
// ARCHIVO: eliminar_libro_sirve_para_quitar_material.php
// CAPA: BACKEND (API del servidor)
// SIRVE PARA: Quitar un libro del inventario junto con todas sus copias (ejemplares).
// QUÉ HACE: Solo personal. Recibe por POST el id del libro. Si el libro tiene préstamos sin devolver, no borra nada y responde error.
// Si no los tiene, borra en una transacción los préstamos ligados a sus copias, las copias y el libro. Responde un JSON con el resultado.
// CONECTA CON: conexion_sirve_para_conectar_base_datos.php; pantalla de administración de libros (revisar qué archivo HTML la llama).
require_once 'conexion_sirve_para_conectar_base_datos.php';
// Solo personal de la biblioteca (Administrador / Bibliotecario)
// Solo personal de la biblioteca puede eliminar libros.
semsad_requerir_rol(['Administrador', 'Bibliotecario']);
header('Content-Type: application/json; charset=utf-8');

// Solo se acepta POST. Otros métodos reciben error 405.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Lee el id del libro desde JSON o, si no llega así, desde el formulario.
    $input = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
    $id_material = (int)($input['id_material'] ?? $_POST['id_material'] ?? 0);

    // Validación: el id debe ser mayor que cero.
    if ($id_material <= 0) {
        semsad_responder(['status' => 'error', 'mensaje' => 'ID de libro no válido.']);
    }

    try {
        // Regla de negocio: cuenta los préstamos de este libro que aún no se devolvieron (Activo, Vencido o Con Mora). Si hay alguno, no se elimina.
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

        // A partir de aquí, los borrados van dentro de una transacción: todo se hace o nada.
        $conexion->beginTransaction();

        // Primero se borran los registros de préstamo de las copias del libro. El orden importa porque las tablas están relacionadas.
        $conexion->exec("
            DELETE FROM detalle_prestamo 
            WHERE id_ejemplar IN (SELECT id_ejemplar FROM ejemplar WHERE id_material = $id_material)
        ");

        // Borra todas las copias (ejemplares) del libro.
        $stmtE = $conexion->prepare("DELETE FROM ejemplar WHERE id_material = :id");
        $stmtE->execute([':id' => $id_material]);

        // Por último, borra el libro del catálogo.
        $stmtM = $conexion->prepare("DELETE FROM materialbibliografico WHERE id_material = :id");
        $stmtM->execute([':id' => $id_material]);

        $conexion->commit();

        semsad_responder(['status' => 'success', 'mensaje' => 'El libro y sus ejemplares han sido eliminados correctamente del inventario.']);

    } catch (Exception $e) {
        // Si algo falla, se deshacen los borrados que ya se hicieron.
        if ($conexion->inTransaction()) $conexion->rollBack();
        semsad_responder(['status' => 'error', 'mensaje' => 'Error al eliminar libro.' . semsad_detalle_error($e)], 500);
    }
} else {
    semsad_responder(['status' => 'error', 'mensaje' => 'Método no permitido.'], 405);
}
