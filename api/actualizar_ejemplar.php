<?php
require_once __DIR__ . '/conexion.php';
semsad_requerir_rol(['Administrador', 'Bibliotecario']);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') semsad_responder(['status' => 'error', 'mensaje' => 'Método no permitido.'], 405);

$input = semsad_leer_json();
$id_ejemplar = (int)($input['id_ejemplar'] ?? 0);
$estado = trim((string)($input['estado_ejemplar'] ?? ''));
$condicion = trim((string)($input['condicion_fisica'] ?? 'Bueno'));

if ($id_ejemplar <= 0) semsad_responder(['status' => 'error', 'mensaje' => 'ID de ejemplar no válido.'], 400);
if (!in_array($estado, ['Disponible', 'En Mantenimiento', 'Baja'], true)) {
    semsad_responder(['status' => 'error', 'mensaje' => 'El estado solicitado no es válido. Los estados Prestado y Reservado se gestionan automáticamente.'], 400);
}
if ($condicion === '' || mb_strlen($condicion) > 100) {
    semsad_responder(['status' => 'error', 'mensaje' => 'La condición física es obligatoria y admite hasta 100 caracteres.'], 400);
}

try {
    $conexion->beginTransaction();
    $stmt = $conexion->prepare("
        SELECT e.id_ejemplar, e.id_material, e.estado_ejemplar
        FROM Ejemplar e
        WHERE e.id_ejemplar = :e
        FOR UPDATE
    ");
    $stmt->execute([':e' => $id_ejemplar]);
    $ejemplar = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$ejemplar) {
        $conexion->rollBack();
        semsad_responder(['status' => 'error', 'mensaje' => 'No se encontró el ejemplar.'], 404);
    }

    $active = $conexion->prepare("
        SELECT COUNT(*) FROM Detalle_Prestamo dp
        INNER JOIN Prestamo p ON p.id_prestamo = dp.id_prestamo
        WHERE dp.id_ejemplar = :e AND p.estado IN ('Activo', 'Con Mora')
    ");
    $active->execute([':e' => $id_ejemplar]);
    if ((int)$active->fetchColumn() > 0 || in_array($ejemplar['estado_ejemplar'], ['Prestado', 'Reservado'], true)) {
        $conexion->rollBack();
        semsad_responder(['status' => 'error', 'mensaje' => 'No se puede modificar esta copia mientras esté prestada o reservada. Procesa primero la devolución o la reserva.'], 409);
    }

    $queue = $conexion->prepare("SELECT COUNT(*) FROM Reserva WHERE id_material = :m AND estado = 'Pendiente'");
    $queue->execute([':m' => (int)$ejemplar['id_material']]);
    if ((int)$queue->fetchColumn() > 0 && $estado !== 'En Mantenimiento' && $estado !== 'Baja') {
        $conexion->rollBack();
        semsad_responder(['status' => 'error', 'mensaje' => 'Hay reservas pendientes para este título. Atiende primero la lista de espera para no saltar turnos.'], 409);
    }

    $update = $conexion->prepare("
        UPDATE Ejemplar
        SET estado_ejemplar = :estado, condicion_fisica = :condicion
        WHERE id_ejemplar = :id
    ");
    $update->execute([':estado' => $estado, ':condicion' => $condicion, ':id' => $id_ejemplar]);
    $conexion->commit();
    semsad_responder(['status' => 'success', 'mensaje' => 'Estado y condición del ejemplar actualizados correctamente.']);
} catch (Throwable $e) {
    if ($conexion->inTransaction()) $conexion->rollBack();
    error_log('[SEMSAD] actualizar_ejemplar: ' . $e->getMessage());
    semsad_responder(['status' => 'error', 'mensaje' => 'No se pudo actualizar el ejemplar. No se confirmó ningún cambio.'], 500);
}
?>