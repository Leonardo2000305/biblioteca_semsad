<?php
require_once __DIR__ . '/conexion.php';
semsad_requerir_rol(['Administrador', 'Bibliotecario']);

try {
    $sql = "
        SELECT
            p.id_prestamo,
            p.id_usuario,
            u.nombres AS user_nombres,
            u.apellidos AS user_apellidos,
            u.correo AS user_correo,
            u.codigo_empleado AS user_ci,
            u.telefono AS user_telefono,
            m.titulo AS libro_titulo,
            p.fecha_salida,
            p.fecha_limite,
            p.estado
        FROM Prestamo p
        INNER JOIN Usuario u ON p.id_usuario = u.id_usuario
        INNER JOIN Detalle_Prestamo dp ON p.id_prestamo = dp.id_prestamo
        INNER JOIN Ejemplar e ON dp.id_ejemplar = e.id_ejemplar
        INNER JOIN MaterialBibliografico m ON e.id_material = m.id_material
        WHERE p.estado IN ('Activo', 'Con Mora')
        ORDER BY p.id_prestamo DESC
    ";
    $filas = $conexion->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    $hoy = strtotime(date('Y-m-d'));
    $prestamos = [];

    foreach ($filas as $f) {
        $idPrestamo = (int)$f['id_prestamo'];
        $limiteTs = !empty($f['fecha_limite']) ? strtotime($f['fecha_limite']) : false;
        $nombre = trim(($f['user_nombres'] ?? '') . ' ' . ($f['user_apellidos'] ?? ''));
        if ($nombre === '') $nombre = (string)($f['user_correo'] ?? 'Estudiante');

        $vencido = ($f['estado'] === 'Con Mora')
            || ($limiteTs !== false && $limiteTs < $hoy);
        $fechaSalida = !empty($f['fecha_salida']) ? date('Y-m-d', strtotime($f['fecha_salida'])) : '';
        $fechaLimite = $limiteTs !== false ? date('Y-m-d', $limiteTs) : 'S/F';
        $diasRestantes = $limiteTs !== false ? (int)floor(($limiteTs - $hoy) / 86400) : 0;

        $prestamos[] = [
            'id' => $idPrestamo,
            'id_prestamo' => $idPrestamo,
            'id_usuario' => (int)$f['id_usuario'],
            'userName' => $nombre,
            'usuario' => $nombre,
            'userCorreo' => (string)($f['user_correo'] ?? ''),
            'correo' => (string)($f['user_correo'] ?? ''),
            'userCi' => (string)($f['user_ci'] ?? ''),
            'userTelefono' => (string)($f['user_telefono'] ?? ''),
            'bookTitle' => (string)($f['libro_titulo'] ?? 'Libro'),
            'libro' => (string)($f['libro_titulo'] ?? 'Libro'),
            'code' => 'PRE-' . $idPrestamo,
            'issueDate' => $fechaSalida,
            'fecha_salida' => $fechaSalida,
            'dueDate' => $fechaLimite,
            'fecha_limite' => $fechaLimite,
            'daysLeft' => max(0, $diasRestantes),
            'overdueDays' => $vencido && $limiteTs !== false ? max(1, (int)floor(($hoy - $limiteTs) / 86400)) : 0,
            'status' => $vencido ? 'Vencido' : 'Activo',
            'estado' => (string)$f['estado']
        ];
    }

    // Las reservas forman parte de la misma vista de ventanilla, separadas de los préstamos ya entregados.
    $columnasReserva = $conexion->query("SHOW COLUMNS FROM Reserva")->fetchAll(PDO::FETCH_COLUMN, 0);
    $tieneSolicitud = in_array('fecha_solicitud', $columnasReserva, true);
    $tieneCaducidad = in_array('fecha_caducidad', $columnasReserva, true);
    $tieneEjemplar = in_array('id_ejemplar', $columnasReserva, true);

    // Si la base incluye los campos opcionales de caducidad, expirar solo las reservas vencidas.
    if ($tieneCaducidad && $tieneEjemplar) {
        $conexion->beginTransaction();
        $vencidas = $conexion->query("
            SELECT id_reserva, id_ejemplar
            FROM Reserva
            WHERE estado = 'Pendiente' AND fecha_caducidad IS NOT NULL AND fecha_caducidad < NOW()
            FOR UPDATE
        ")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($vencidas as $reservaVencida) {
            $idEjemplarVencido = (int)($reservaVencida['id_ejemplar'] ?? 0);
            if ($idEjemplarVencido > 0) {
                $conexion->prepare("UPDATE Ejemplar SET estado_ejemplar = 'Disponible' WHERE id_ejemplar = :e AND estado_ejemplar = 'Reservado'")
                    ->execute([':e' => $idEjemplarVencido]);
            }
            $conexion->prepare("UPDATE Reserva SET estado = 'Vencida' WHERE id_reserva = :r AND estado = 'Pendiente'")
                ->execute([':r' => (int)$reservaVencida['id_reserva']]);
        }
        $conexion->commit();
    }

    $sqlR = "
        SELECT r.id_reserva, r.id_usuario, r.id_material, r.estado, r.prioridad,
               u.nombres AS user_nombres, u.apellidos AS user_apellidos,
               u.correo AS user_correo, u.codigo_empleado AS user_ci,
               u.telefono AS user_telefono, mb.titulo AS libro_titulo
    ";
    if ($tieneSolicitud) $sqlR .= ", r.fecha_solicitud";
    if ($tieneCaducidad) $sqlR .= ", r.fecha_caducidad";
    $sqlR .= "
        FROM Reserva r
        INNER JOIN Usuario u ON u.id_usuario = r.id_usuario
        INNER JOIN MaterialBibliografico mb ON mb.id_material = r.id_material
        WHERE r.estado = 'Pendiente'
        ORDER BY r.prioridad ASC, r.id_reserva ASC
    ";
    $filasR = $conexion->query($sqlR)->fetchAll(PDO::FETCH_ASSOC);
    $reservas = [];
    foreach ($filasR as $r) {
        $nombre = trim(($r['user_nombres'] ?? '') . ' ' . ($r['user_apellidos'] ?? ''));
        if ($nombre === '') $nombre = (string)($r['user_correo'] ?? 'Estudiante');
        $fechaSolicitud = !empty($r['fecha_solicitud']) ? date('Y-m-d H:i', strtotime($r['fecha_solicitud'])) : 'S/F';
        $fechaCaducidad = !empty($r['fecha_caducidad']) ? date('Y-m-d H:i', strtotime($r['fecha_caducidad'])) : 'En espera';
        $reservas[] = [
            'tipo' => 'reserva',
            'id' => (int)$r['id_reserva'],
            'id_reserva' => (int)$r['id_reserva'],
            'id_usuario' => (int)$r['id_usuario'],
            'userName' => $nombre,
            'userCorreo' => (string)($r['user_correo'] ?? ''),
            'userCi' => (string)($r['user_ci'] ?? ''),
            'userTelefono' => (string)($r['user_telefono'] ?? ''),
            'bookTitle' => (string)($r['libro_titulo'] ?? 'Libro'),
            'issueDate' => $fechaSolicitud,
            'dueDate' => $fechaCaducidad,
            'status' => 'Reserva pendiente',
            'estado' => (string)$r['estado'],
            'prioridad' => (int)$r['prioridad']
        ];
    }

    semsad_responder([
        'status' => 'success',
        'prestamos' => $prestamos,
        'reservas' => $reservas,
        'datos' => $prestamos,
        'data' => $prestamos
    ]);
} catch (Throwable $e) {
    if ($conexion->inTransaction()) $conexion->rollBack();
    error_log('[SEMSAD] listar_prestamos: ' . $e->getMessage());
    semsad_responder(['status' => 'error', 'mensaje' => 'No se pudieron listar préstamos y reservas.'], 500);
}
?>