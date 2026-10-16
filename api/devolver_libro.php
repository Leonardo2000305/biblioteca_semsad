<?php
require_once __DIR__ . '/conexion.php';
semsad_requerir_rol(['Administrador', 'Bibliotecario']);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    semsad_responder(['status' => 'error', 'mensaje' => 'Método no permitido.'], 405);
}
$input = semsad_leer_json();
$id_prestamo = (int)($input['id_prestamo'] ?? $_POST['id_prestamo'] ?? 0);
$condicion = trim((string)($input['condicion_devolucion'] ?? $_POST['condicion_devolucion'] ?? 'Bueno'));
if ($id_prestamo <= 0) semsad_responder(['status' => 'error', 'mensaje' => 'ID de préstamo no válido.'], 400);
if ($condicion === '') $condicion = 'Bueno';

try {
    $conexion->beginTransaction();

    $stmt = $conexion->prepare("
        SELECT p.id_prestamo, p.id_usuario, p.fecha_limite, p.estado,
               dp.id_detalle, dp.id_ejemplar, e.id_material, e.id_adquisicion,
               mb.titulo
        FROM Prestamo p
        INNER JOIN Detalle_Prestamo dp ON dp.id_prestamo = p.id_prestamo
        INNER JOIN Ejemplar e ON e.id_ejemplar = dp.id_ejemplar
        INNER JOIN MaterialBibliografico mb ON mb.id_material = e.id_material
        WHERE p.id_prestamo = :p AND p.estado IN ('Activo', 'Con Mora')
        FOR UPDATE
    ");
    $stmt->execute([':p' => $id_prestamo]);
    $detalles = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$detalles) {
        $conexion->rollBack();
        semsad_responder(['status' => 'error', 'mensaje' => 'El préstamo no existe, no tiene ejemplares asociados o ya fue devuelto.'], 404);
    }

    $primer = $detalles[0];
    $fechaLimiteTs = strtotime((string)$primer['fecha_limite']);
    $diasMora = max(0, (int)floor((strtotime(date('Y-m-d')) - strtotime(date('Y-m-d', $fechaLimiteTs))) / 86400));

    // Registrar una sola multa por préstamo. Si la adquisición tiene costo conocido,
    // la tarifa diaria es 5% del costo unitario, con tope de Bs 200. Si falta costo,
    // se conserva el importe mínimo de Bs 5/día usado por el trigger del esquema actual.
    if ($diasMora > 0) {
        $stmtMultaExistente = $conexion->prepare("SELECT COUNT(*) FROM Multa WHERE id_prestamo = :p");
        $stmtMultaExistente->execute([':p' => $id_prestamo]);

        if ((int)$stmtMultaExistente->fetchColumn() === 0) {
            $stmtCosto = $conexion->prepare("
                SELECT
                    COALESCE(a.costo_total, 0) AS costo_total,
                    (SELECT COUNT(*)
                     FROM Ejemplar e2
                     WHERE e2.id_adquisicion = e.id_adquisicion
                       AND e2.estado_ejemplar <> 'Baja') AS copias_adquisicion
                FROM Ejemplar e
                LEFT JOIN Adquisicion a ON a.id_adquisicion = e.id_adquisicion
                WHERE e.id_ejemplar = :e
                LIMIT 1
            ");
            $stmtCosto->execute([':e' => (int)$primer['id_ejemplar']]);
            $costo = $stmtCosto->fetch(PDO::FETCH_ASSOC) ?: ['costo_total' => 0, 'copias_adquisicion' => 0];
            $costoTotal = (float)($costo['costo_total'] ?? 0);
            $copias = (int)($costo['copias_adquisicion'] ?? 0);
            $costoUnitario = ($costoTotal > 0 && $copias > 0) ? $costoTotal / $copias : 0;
            $monto = $costoUnitario > 0
                ? min(200, $costoUnitario * 0.05 * $diasMora)
                : min(200, 5 * $diasMora);
            $monto = round(max(0.01, $monto), 2);

            $stmtMulta = $conexion->prepare("
                INSERT INTO Multa (id_prestamo, id_detalle, monto, estado_pago, tipo_multa)
                VALUES (:p, :d, :monto, 'Pendiente', 'Por Mora')
            ");
            $stmtMulta->execute([
                ':p' => $id_prestamo,
                ':d' => (int)$primer['id_detalle'],
                ':monto' => $monto
            ]);
        }
    }

    $updP = $conexion->prepare("
        UPDATE Prestamo
        SET fecha_devolucion_real = :fecha, estado = 'Devuelto'
        WHERE id_prestamo = :p AND estado IN ('Activo', 'Con Mora')
    ");
    $updP->execute([':fecha' => date('Y-m-d H:i:s'), ':p' => $id_prestamo]);
    if ($updP->rowCount() !== 1) {
        throw new RuntimeException('El préstamo ya fue procesado por otra solicitud.');
    }

    $updD = $conexion->prepare("
        UPDATE Detalle_Prestamo
        SET condicion_devolucion = :condicion
        WHERE id_prestamo = :p
    ");
    $updD->execute([':condicion' => $condicion, ':p' => $id_prestamo]);

    $updE = $conexion->prepare("
        UPDATE Ejemplar
        SET estado_ejemplar = 'Disponible'
        WHERE id_ejemplar = :e AND estado_ejemplar = 'Prestado'
    ");
    foreach ($detalles as $detalle) {
        $updE->execute([':e' => (int)$detalle['id_ejemplar']]);
    }

    // Al devolverse una copia, la primera reserva pendiente recibe prioridad de retiro.
    // La caducidad automática se activa cuando están instaladas las columnas opcionales.
    $columnasReserva = $conexion->query("SHOW COLUMNS FROM Reserva")->fetchAll(PDO::FETCH_COLUMN, 0);
    if (in_array('id_ejemplar', $columnasReserva, true)
        && in_array('fecha_caducidad', $columnasReserva, true)) {
        foreach ($detalles as $detalle) {
            $stmtCola = $conexion->prepare("
                SELECT id_reserva
                FROM Reserva
                WHERE id_material = :m AND estado = 'Pendiente'
                ORDER BY prioridad ASC, id_reserva ASC
                LIMIT 1
                FOR UPDATE
            ");
            $stmtCola->execute([':m' => (int)$detalle['id_material']]);
            $idReservaSiguiente = (int)$stmtCola->fetchColumn();
            if ($idReservaSiguiente > 0) {
                $venceRetiro = date('Y-m-d', strtotime('+1 day')) . ' 10:00:00';
                $conexion->prepare("UPDATE Ejemplar SET estado_ejemplar = 'Reservado' WHERE id_ejemplar = :e")
                    ->execute([':e' => (int)$detalle['id_ejemplar']]);
                $conexion->prepare("
                    UPDATE Reserva
                    SET id_ejemplar = :e, fecha_caducidad = :vence
                    WHERE id_reserva = :r AND estado = 'Pendiente'
                ")->execute([
                    ':e' => (int)$detalle['id_ejemplar'],
                    ':vence' => $venceRetiro,
                    ':r' => $idReservaSiguiente
                ]);
                break;
            }
        }
    }

    // La sanción de cuenta se activa a partir de 15 días de mora.
    if ($diasMora >= 15) {
        $conexion->prepare("UPDATE Usuario SET estado = 'Suspendido' WHERE id_usuario = :u")
            ->execute([':u' => (int)$primer['id_usuario']]);
    }

    $conexion->commit();

    $mensaje = 'Devolución registrada. El inventario se actualizó correctamente.';
    if ($diasMora > 0) {
        $mensaje .= ' Se registró una multa pendiente por ' . $diasMora . ' día(s) de mora.';
    }
    if ($diasMora >= 15) {
        $mensaje .= ' La cuenta quedó suspendida por mora; deberá regularizar la multa para reactivarse.';
    }
    semsad_responder([
        'status' => 'success',
        'mensaje' => $mensaje,
        'dias_mora' => $diasMora,
        'multa_generada' => $diasMora > 0
    ]);
} catch (Throwable $e) {
    if ($conexion->inTransaction()) $conexion->rollBack();
    error_log('[SEMSAD] devolver_libro: ' . $e->getMessage());
    semsad_responder(['status' => 'error', 'mensaje' => 'No se pudo procesar la devolución. No se confirmó ningún cambio.'], 500);
}
?>