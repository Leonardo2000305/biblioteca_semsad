<?php
// ARCHIVO: devolver_libro_sirve_para_devolucion_desde_ventanilla.php
// CAPA: BACKEND (API del servidor)
// SIRVE PARA: Registrar la devolución de un libro prestado cuando la persona lo entrega en la ventanilla.
// QUÉ HACE: Solo personal de la biblioteca. Recibe por POST el id del préstamo. En una transacción marca el préstamo como Devuelto,
// pone sus ejemplares como Disponibles y corrige ejemplares que quedaron como Prestados sin un préstamo vigente. Responde un JSON con el resultado.
// CONECTA CON: conexion_sirve_para_conectar_base_datos.php; pantalla de administración de préstamos (revisar qué archivo HTML la llama).
require_once 'conexion_sirve_para_conectar_base_datos.php';
// Solo personal de la biblioteca (Administrador / Bibliotecario)
// Solo personal (Administrador o Bibliotecario). Si no lo es, la petición se corta aquí.
semsad_requerir_rol(['Administrador', 'Bibliotecario']);

header('Content-Type: application/json; charset=utf-8');

// Solo se acepta POST. Cualquier otro método recibe error 405 (al final del archivo).
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Lee el id del préstamo desde JSON. Si no llega así, lo toma del formulario.
    $input = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
    $id_prestamo = (int)($input['id_prestamo'] ?? $_POST['id_prestamo'] ?? 0);

    // Validación: el id del préstamo debe ser un número mayor que cero.
    if ($id_prestamo <= 0) {
        semsad_responder(['status' => 'error', 'mensaje' => 'ID de préstamo no válido.']);
    }

    try {
        // Inicia la transacción: si algo falla a mitad del proceso, todos los cambios se deshacen.
        $conexion->beginTransaction();

        // 1. Obtener ejemplares asociados
        $stmtD = $conexion->prepare("SELECT id_ejemplar FROM detalle_prestamo WHERE id_prestamo = :p");
        $stmtD->execute([':p' => $id_prestamo]);
        $ejemplares = $stmtD->fetchAll(PDO::FETCH_COLUMN);

        // 2. Marcar préstamo como Devuelto
        $stmtP = $conexion->prepare("UPDATE prestamo SET estado = 'Devuelto' WHERE id_prestamo = :p");
        $stmtP->execute([':p' => $id_prestamo]);

        // Fecha real de devolución (la usan los reportes). Si la columna no existe, se ignora.
        // La fecha real de devolución es opcional: si la columna no existe, este paso se omite sin detener la devolución.
        try {
            $conexion->prepare("UPDATE prestamo SET fecha_devolucion_real = NOW() WHERE id_prestamo = :p")->execute([':p' => $id_prestamo]);
        } catch (Exception $ignorar) {}

        // 3. Marcar ejemplares como Disponibles
        if (!empty($ejemplares)) {
            // array_map con intval deja solo números en la lista de ids, así no se puede meter texto dentro de la consulta.
            $inClause = implode(',', array_map('intval', $ejemplares));
            $conexion->exec("UPDATE ejemplar SET estado_ejemplar = 'Disponible' WHERE id_ejemplar IN ($inClause)");
        }

        // 4. Autocorrección de seguridad: Liberar cualquier ejemplar huérfano
        $conexion->exec("
            UPDATE ejemplar 
            SET estado_ejemplar = 'Disponible' 
            WHERE estado_ejemplar = 'Prestado' 
            AND id_ejemplar NOT IN (
                SELECT DISTINCT dp.id_ejemplar 
                FROM detalle_prestamo dp 
                INNER JOIN prestamo p ON dp.id_prestamo = p.id_prestamo 
                WHERE p.estado IN ('Activo', 'Vencido', 'Con Mora')
            )
        ");

        // Confirma todos los cambios de la transacción.
        $conexion->commit();

        semsad_responder([
            'status' => 'success',
            'mensaje' => '¡Libro devuelto con éxito! El ejemplar ya se encuentra disponible en el catálogo.'
        ]);

    } catch (Exception $e) {
        // Si hubo error y la transacción sigue abierta, se deshace para no dejar datos a medias.
        if ($conexion->inTransaction()) {
            $conexion->rollBack();
        }
        semsad_responder(['status' => 'error', 'mensaje' => 'Error al procesar devolución.' . semsad_detalle_error($e)], 500);
    }
} else {
    semsad_responder(['status' => 'error', 'mensaje' => 'Método no permitido.'], 405);
}
