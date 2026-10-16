<?php
require_once __DIR__ . '/conexion.php';
semsad_requerir_rol(['Administrador', 'Bibliotecario']);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') semsad_responder(['status' => 'error', 'mensaje' => 'Método no permitido.'], 405);
$input = semsad_leer_json();
$id_reserva = (int)($input['id_reserva'] ?? 0);
$id_bibliotecario = (int)($_SESSION['id_usuario'] ?? 0);
if ($id_reserva <= 0) semsad_responder(['status' => 'error', 'mensaje' => 'ID de reserva no válido.'], 400);

try {
    $columns = $conexion->query("SHOW COLUMNS FROM Reserva")->fetchAll(PDO::FETCH_COLUMN, 0);
    $conexion->beginTransaction();

    $stmt = $conexion->prepare("
        SELECT r.id_reserva, r.id_usuario, r.id_material, r.estado, u.estado AS estado_usuario
        FROM Reserva r
        INNER JOIN Usuario u ON u.id_usuario = r.id_usuario
        WHERE r.id_reserva = :r
        FOR UPDATE
    ");
    $stmt->execute([':r' => $id_reserva]);
    $reserva = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$reserva || $reserva['estado'] !== 'Pendiente') {
        $conexion->rollBack();
        semsad_responder(['status' => 'error', 'mensaje' => 'La reserva no existe o ya fue procesada.'], 404);
    }
    if ($reserva['estado_usuario'] !== 'Activo') {
        $conexion->rollBack();
        semsad_responder(['status' => 'error', 'mensaje' => 'La cuenta del usuario no está activa.'], 403);
    }

    $stmtFine = $conexion->prepare("
        SELECT COUNT(*) FROM Multa m
        INNER JOIN Prestamo p ON p.id_prestamo = m.id_prestamo
        WHERE p.id_usuario = :u AND m.estado_pago = 'Pendiente'
    ");
    $stmtFine->execute([':u' => (int)$reserva['id_usuario']]);
    if ((int)$stmtFine->fetchColumn() > 0) {
        $conexion->rollBack();
        semsad_responder(['status' => 'error', 'mensaje' => 'El usuario tiene multas pendientes y no puede recibir la reserva.'], 403);
    }

    $idEjemplar = 0;
    if (in_array('id_ejemplar', $columns, true)) {
        $q = $conexion->prepare("SELECT id_ejemplar FROM Reserva WHERE id_reserva = :r");
        $q->execute([':r' => $id_reserva]);
        $idEjemplar = (int)$q->fetchColumn();
    }
    if ($idEjemplar > 0) {
        $q = $conexion->prepare("
            SELECT id_ejemplar FROM Ejemplar
            WHERE id_ejemplar = :e AND estado_ejemplar = 'Reservado'
            FOR UPDATE
        ");
        $q->execute([':e' => $idEjemplar]);
        $idEjemplar = (int)$q->fetchColumn();
    } else {
        $q = $conexion->prepare("
            SELECT e.id_ejemplar
            FROM Ejemplar e
            WHERE e.id_material = :m AND e.estado_ejemplar = 'Disponible'
            ORDER BY e.id_ejemplar
            LIMIT 1
            FOR UPDATE
        ");
        $q->execute([':m' => (int)$reserva['id_material']]);
        $idEjemplar = (int)$q->fetchColumn();
    }

    if ($idEjemplar <= 0) {
        $conexion->rollBack();
        semsad_responder(['status' => 'error', 'mensaje' => 'Todavía no hay una copia disponible para atender esta reserva.'], 409);
    }

    $stmtYaTiene = $conexion->prepare("
        SELECT COUNT(*)
        FROM Prestamo p
        INNER JOIN Detalle_Prestamo dp ON dp.id_prestamo = p.id_prestamo
        INNER JOIN Ejemplar e ON e.id_ejemplar = dp.id_ejemplar
        WHERE p.id_usuario = :u AND e.id_material = :m AND p.estado IN ('Activo', 'Con Mora')
    ");
    $stmtYaTiene->execute([':u' => (int)$reserva['id_usuario'], ':m' => (int)$reserva['id_material']]);
    if ((int)$stmtYaTiene->fetchColumn() > 0) {
        $conexion->rollBack();
        semsad_responder(['status' => 'error', 'mensaje' => 'El usuario ya tiene un préstamo activo de este libro.'], 409);
    }

    $qCount = $conexion->prepare("
        SELECT COUNT(DISTINCT p.id_prestamo)
        FROM Prestamo p
        INNER JOIN Detalle_Prestamo dp ON dp.id_prestamo = p.id_prestamo
        WHERE p.id_usuario = :u AND p.estado IN ('Activo', 'Con Mora')
    ");
    $qCount->execute([':u' => (int)$reserva['id_usuario']]);
    if ((int)$qCount->fetchColumn() >= 5) {
        $conexion->rollBack();
        semsad_responder(['status' => 'error', 'mensaje' => 'El usuario ya tiene el máximo de 5 préstamos activos.'], 409);
    }

    $fechaSalida = date('Y-m-d H:i:s');
    $fechaLimite = date('Y-m-d H:i:s', strtotime('+7 days'));
    $stmtP = $conexion->prepare("
        INSERT INTO Prestamo (id_usuario, id_bibliotecario_entrega, fecha_salida, fecha_limite, estado)
        VALUES (:u, :b, :salida, :limite, 'Activo')
    ");
    $stmtP->execute([
        ':u' => (int)$reserva['id_usuario'],
        ':b' => $id_bibliotecario,
        ':salida' => $fechaSalida,
        ':limite' => $fechaLimite
    ]);
    $idPrestamo = (int)$conexion->lastInsertId();

    $conexion->prepare("INSERT INTO Detalle_Prestamo (id_prestamo, id_ejemplar) VALUES (:p, :e)")
        ->execute([':p' => $idPrestamo, ':e' => $idEjemplar]);
    $conexion->prepare("UPDATE Ejemplar SET estado_ejemplar = 'Prestado' WHERE id_ejemplar = :e")
        ->execute([':e' => $idEjemplar]);

    if (in_array('fecha_atencion', $columns, true)) {
        $conexion->prepare("UPDATE Reserva SET estado = 'Atendida', fecha_atencion = NOW() WHERE id_reserva = :r")
            ->execute([':r' => $id_reserva]);
    } else {
        $conexion->prepare("UPDATE Reserva SET estado = 'Atendida' WHERE id_reserva = :r")
            ->execute([':r' => $id_reserva]);
    }

    $conexion->commit();
    semsad_responder(['status' => 'success', 'mensaje' => 'Reserva atendida. Se registró el préstamo correctamente.', 'id_prestamo' => $idPrestamo]);
} catch (Throwable $e) {
    if ($conexion->inTransaction()) $conexion->rollBack();
    error_log('[SEMSAD] atender_reserva: ' . $e->getMessage());
    semsad_responder(['status' => 'error', 'mensaje' => 'No se pudo atender la reserva.'], 500);
}
?>