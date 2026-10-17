<?php
require_once 'conexion.php';
// Solo personal de la biblioteca (Administrador / Bibliotecario)
semsad_requerir_rol(['Administrador', 'Bibliotecario']);

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
    $id_prestamo = (int)($input['id_prestamo'] ?? $_POST['id_prestamo'] ?? 0);

    if ($id_prestamo <= 0) {
        semsad_responder(['status' => 'error', 'mensaje' => 'ID de préstamo no válido.']);
    }

    try {
        $conexion->beginTransaction();

        // 1. Obtener ejemplares asociados
        $stmtD = $conexion->prepare("SELECT id_ejemplar FROM Detalle_Prestamo WHERE id_prestamo = :p");
        $stmtD->execute([':p' => $id_prestamo]);
        $ejemplares = $stmtD->fetchAll(PDO::FETCH_COLUMN);

        // 2. Marcar préstamo como Devuelto
        $stmtP = $conexion->prepare("UPDATE Prestamo SET estado = 'Devuelto' WHERE id_prestamo = :p");
        $stmtP->execute([':p' => $id_prestamo]);

        // Fecha real de devolución (la usan los reportes). Si la columna no existe, se ignora.
        try {
            $conexion->prepare("UPDATE Prestamo SET fecha_devolucion_real = NOW() WHERE id_prestamo = :p")->execute([':p' => $id_prestamo]);
        } catch (Exception $ignorar) {}

        // 3. Marcar ejemplares como Disponibles
        if (!empty($ejemplares)) {
            $inClause = implode(',', array_map('intval', $ejemplares));
            $conexion->exec("UPDATE Ejemplar SET estado_ejemplar = 'Disponible' WHERE id_ejemplar IN ($inClause)");
        }

        // 4. Autocorrección de seguridad: Liberar cualquier ejemplar huérfano
        $conexion->exec("
            UPDATE Ejemplar 
            SET estado_ejemplar = 'Disponible' 
            WHERE estado_ejemplar = 'Prestado' 
            AND id_ejemplar NOT IN (
                SELECT DISTINCT dp.id_ejemplar 
                FROM Detalle_Prestamo dp 
                INNER JOIN Prestamo p ON dp.id_prestamo = p.id_prestamo 
                WHERE p.estado IN ('Activo', 'Vencido', 'Con Mora')
            )
        ");

        $conexion->commit();

        semsad_responder([
            'status' => 'success',
            'mensaje' => '¡Libro devuelto con éxito! El ejemplar ya se encuentra disponible en el catálogo.'
        ]);

    } catch (Exception $e) {
        if ($conexion->inTransaction()) {
            $conexion->rollBack();
        }
        semsad_responder(['status' => 'error', 'mensaje' => 'Error al procesar devolución: ' . $e->getMessage()], 500);
    }
} else {
    semsad_responder(['status' => 'error', 'mensaje' => 'Método no permitido.'], 405);
}
