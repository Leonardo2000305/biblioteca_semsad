<?php
require_once __DIR__ . '/conexion.php';
semsad_iniciar_sesion();

$id_usuario = (int)($_SESSION['id_usuario'] ?? 0);
$rol = (string)($_SESSION['rol'] ?? '');
if ($id_usuario <= 0) semsad_responder(['status' => 'error', 'mensaje' => 'Inicia sesión para reservar un libro.'], 401);
if (!in_array($rol, ['Estudiante', 'Docente'], true)) semsad_responder(['status' => 'error', 'mensaje' => 'Solo estudiantes y docentes pueden reservar material.'], 403);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') semsad_responder(['status' => 'error', 'mensaje' => 'Método no permitido.'], 405);

$input = semsad_leer_json();
$id_material = (int)($input['id_material'] ?? 0);
if ($id_material <= 0) semsad_responder(['status' => 'error', 'mensaje' => 'Libro no válido.'], 400);

try {
    $stmtUser = $conexion->prepare("SELECT estado FROM Usuario WHERE id_usuario = :u LIMIT 1");
    $stmtUser->execute([':u' => $id_usuario]);
    if ($stmtUser->fetchColumn() !== 'Activo') semsad_responder(['status' => 'error', 'mensaje' => 'Tu cuenta no está activa y no puede realizar reservas.'], 403);

    $stmtMaterial = $conexion->prepare("SELECT titulo FROM MaterialBibliografico WHERE id_material = :m LIMIT 1");
    $stmtMaterial->execute([':m' => $id_material]);
    $titulo = $stmtMaterial->fetchColumn();
    if (!$titulo) semsad_responder(['status' => 'error', 'mensaje' => 'El libro no existe en el catálogo.'], 404);

    $stmtFine = $conexion->prepare("
        SELECT COUNT(*) FROM Multa m
        INNER JOIN Prestamo p ON p.id_prestamo = m.id_prestamo
        WHERE p.id_usuario = :u AND m.estado_pago = 'Pendiente'
    ");
    $stmtFine->execute([':u' => $id_usuario]);
    if ((int)$stmtFine->fetchColumn() > 0) semsad_responder(['status' => 'error', 'mensaje' => 'Tienes multas pendientes. Debes regularizar tu cuenta antes de reservar.'], 403);

    $stmtActive = $conexion->prepare("
        SELECT COUNT(*)
        FROM Prestamo p
        INNER JOIN Detalle_Prestamo dp ON dp.id_prestamo = p.id_prestamo
        INNER JOIN Ejemplar e ON e.id_ejemplar = dp.id_ejemplar
        WHERE p.id_usuario = :u AND e.id_material = :m AND p.estado IN ('Activo', 'Con Mora')
    ");
    $stmtActive->execute([':u' => $id_usuario, ':m' => $id_material]);
    if ((int)$stmtActive->fetchColumn() > 0) semsad_responder(['status' => 'error', 'mensaje' => 'Ya tienes este libro en préstamo; no necesitas crear una reserva adicional.'], 409);

    $stmtDupe = $conexion->prepare("
        SELECT id_reserva, prioridad
        FROM Reserva
        WHERE id_usuario = :u AND id_material = :m AND estado = 'Pendiente'
        LIMIT 1
    ");
    $stmtDupe->execute([':u' => $id_usuario, ':m' => $id_material]);
    $existing = $stmtDupe->fetch(PDO::FETCH_ASSOC);
    if ($existing) semsad_responder(['status' => 'success', 'mensaje' => 'Ya estás en la lista de espera de este libro.', 'id_reserva' => (int)$existing['id_reserva'], 'prioridad' => (int)$existing['prioridad']]);

    $stmtStock = $conexion->prepare("SELECT COUNT(*) FROM Ejemplar WHERE id_material = :m AND estado_ejemplar = 'Disponible'");
    $stmtStock->execute([':m' => $id_material]);
    $stock = (int)$stmtStock->fetchColumn();

    $stmtQueue = $conexion->prepare("SELECT COUNT(*) FROM Reserva WHERE id_material = :m AND estado = 'Pendiente'");
    $stmtQueue->execute([':m' => $id_material]);
    $queueSize = (int)$stmtQueue->fetchColumn();
    if ($stock > 0 && $queueSize === 0) semsad_responder(['status' => 'error', 'mensaje' => 'Este libro tiene copias disponibles. Solicita el préstamo directamente desde el catálogo.'], 409);

    $stmtCount = $conexion->prepare("
        SELECT COUNT(*) FROM (
            SELECT p.id_prestamo AS id
            FROM Prestamo p
            INNER JOIN Detalle_Prestamo dp ON dp.id_prestamo = p.id_prestamo
            WHERE p.id_usuario = :u1 AND p.estado IN ('Activo', 'Con Mora')
            UNION
            SELECT r.id_reserva AS id
            FROM Reserva r
            WHERE r.id_usuario = :u2 AND r.estado = 'Pendiente'
        ) AS solicitudes_activas
    ");
    $stmtCount->execute([':u1' => $id_usuario, ':u2' => $id_usuario]);
    if ((int)$stmtCount->fetchColumn() >= 5) semsad_responder(['status' => 'error', 'mensaje' => 'Has alcanzado el límite de 5 préstamos y reservas activas.'], 409);

    $conexion->beginTransaction();
    $stmtPriority = $conexion->prepare("SELECT COALESCE(MAX(prioridad), 0) + 1 FROM Reserva WHERE id_material = :m AND estado = 'Pendiente'");
    $stmtPriority->execute([':m' => $id_material]);
    $prioridad = (int)$stmtPriority->fetchColumn();

    // Compatible con el esquema base. Si se aplica la migración opcional, conserva fechas y ejemplar asignado.
    $columns = $conexion->query("SHOW COLUMNS FROM Reserva")->fetchAll(PDO::FETCH_COLUMN, 0);
    $hasFechaSolicitud = in_array('fecha_solicitud', $columns, true);
    $hasFechaCaducidad = in_array('fecha_caducidad', $columns, true);
    $hasIdEjemplar = in_array('id_ejemplar', $columns, true);
    if ($hasFechaSolicitud && $hasFechaCaducidad && $hasIdEjemplar) {
        $stmtInsert = $conexion->prepare("INSERT INTO Reserva (id_usuario, id_material, estado, prioridad, fecha_solicitud, fecha_caducidad, id_ejemplar) VALUES (:u, :m, 'Pendiente', :p, NOW(), NULL, NULL)");
    } elseif ($hasFechaSolicitud && $hasFechaCaducidad) {
        $stmtInsert = $conexion->prepare("INSERT INTO Reserva (id_usuario, id_material, estado, prioridad, fecha_solicitud, fecha_caducidad) VALUES (:u, :m, 'Pendiente', :p, NOW(), NULL)");
    } else {
        $stmtInsert = $conexion->prepare("INSERT INTO Reserva (id_usuario, id_material, estado, prioridad) VALUES (:u, :m, 'Pendiente', :p)");
    }
    $stmtInsert->execute([':u' => $id_usuario, ':m' => $id_material, ':p' => $prioridad]);
    $idReserva = (int)$conexion->lastInsertId();
    $conexion->commit();

    semsad_responder(['status' => 'success', 'mensaje' => "Te añadimos a la lista de espera de \"$titulo\". Tu posición es $prioridad.", 'id_reserva' => $idReserva, 'prioridad' => $prioridad]);
} catch (Throwable $e) {
    if ($conexion->inTransaction()) $conexion->rollBack();
    error_log('[SEMSAD] reservar_material: ' . $e->getMessage());
    semsad_responder(['status' => 'error', 'mensaje' => 'No se pudo registrar la reserva. Actualiza el catálogo e inténtalo nuevamente.'], 500);
}
?>