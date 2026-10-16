<?php
require_once __DIR__ . '/conexion.php';
semsad_requerir_rol(['Administrador', 'Bibliotecario']);

function semsad_fecha_reporte(?string $valor, string $fallback): string {
    if (!$valor) return $fallback;
    $dt = DateTime::createFromFormat('!Y-m-d', $valor);
    return $dt && $dt->format('Y-m-d') === $valor ? $valor : $fallback;
}

$hoy = date('Y-m-d');
$desde = semsad_fecha_reporte(isset($_GET['desde']) ? (string)$_GET['desde'] : null, $hoy);
$hasta = semsad_fecha_reporte(isset($_GET['hasta']) ? (string)$_GET['hasta'] : null, $hoy);
if ($desde > $hasta) {
    semsad_responder(['status' => 'error', 'mensaje' => 'La fecha inicial no puede ser posterior a la fecha final.'], 400);
}

try {
    $colsReserva = $conexion->query("SHOW COLUMNS FROM Reserva")->fetchAll(PDO::FETCH_COLUMN, 0);
    $colsMulta = $conexion->query("SHOW COLUMNS FROM Multa")->fetchAll(PDO::FETCH_COLUMN, 0);

    $q = $conexion->prepare("SELECT COUNT(*) FROM Prestamo WHERE DATE(fecha_salida) BETWEEN :d AND :h");
    $q->execute([':d' => $desde, ':h' => $hasta]);
    $prestamosPeriodo = (int)$q->fetchColumn();

    $q = $conexion->prepare("SELECT COUNT(*) FROM Prestamo WHERE fecha_devolucion_real IS NOT NULL AND DATE(fecha_devolucion_real) BETWEEN :d AND :h");
    $q->execute([':d' => $desde, ':h' => $hasta]);
    $devolucionesPeriodo = (int)$q->fetchColumn();

    $reservasPendientes = (int)$conexion->query("SELECT COUNT(*) FROM Reserva WHERE estado = 'Pendiente'")->fetchColumn();
    $reservasPeriodo = null;
    if (in_array('fecha_solicitud', $colsReserva, true)) {
        $q = $conexion->prepare("SELECT COUNT(*) FROM Reserva WHERE DATE(fecha_solicitud) BETWEEN :d AND :h");
        $q->execute([':d' => $desde, ':h' => $hasta]);
        $reservasPeriodo = (int)$q->fetchColumn();
    }

    $multasPendientes = (int)$conexion->query("SELECT COUNT(*) FROM Multa WHERE estado_pago = 'Pendiente'")->fetchColumn();
    $montoPendiente = (float)$conexion->query("SELECT COALESCE(SUM(monto), 0) FROM Multa WHERE estado_pago = 'Pendiente'")->fetchColumn();
    $multasPagadasPeriodo = null;
    $montoPagadoPeriodo = null;
    if (in_array('fecha_pago', $colsMulta, true)) {
        $q = $conexion->prepare("SELECT COUNT(*), COALESCE(SUM(monto), 0) FROM Multa WHERE estado_pago = 'Pagado' AND DATE(fecha_pago) BETWEEN :d AND :h");
        $q->execute([':d' => $desde, ':h' => $hasta]);
        $row = $q->fetch(PDO::FETCH_NUM);
        $multasPagadasPeriodo = (int)$row[0];
        $montoPagadoPeriodo = round((float)$row[1], 2);
    } else {
        $multasPagadasPeriodo = (int)$conexion->query("SELECT COUNT(*) FROM Multa WHERE estado_pago = 'Pagado'")->fetchColumn();
        $montoPagadoPeriodo = round((float)$conexion->query("SELECT COALESCE(SUM(monto),0) FROM Multa WHERE estado_pago = 'Pagado'")->fetchColumn(), 2);
    }

    $stockCritico = $conexion->query("
        SELECT mb.id_material, mb.titulo,
               (SELECT COUNT(*) FROM Ejemplar e WHERE e.id_material = mb.id_material AND e.estado_ejemplar = 'Disponible') AS disponibles,
               (SELECT COUNT(*) FROM Ejemplar e WHERE e.id_material = mb.id_material AND e.estado_ejemplar <> 'Baja') AS total,
               (SELECT COUNT(*) FROM Ejemplar e WHERE e.id_material = mb.id_material AND e.estado_ejemplar = 'En Mantenimiento') AS mantenimiento,
               (SELECT COUNT(*) FROM Ejemplar e WHERE e.id_material = mb.id_material AND e.estado_ejemplar = 'Baja') AS bajas
        FROM MaterialBibliografico mb
        HAVING disponibles <= 1
        ORDER BY disponibles ASC, mb.titulo ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $morosos = $conexion->query("
        SELECT u.id_usuario, u.nombres, u.apellidos, u.correo, u.codigo_empleado, u.estado,
               COUNT(DISTINCT p.id_prestamo) AS prestamos_mora,
               COALESCE(f.monto_pendiente, 0) AS monto_pendiente
        FROM Usuario u
        INNER JOIN Prestamo p ON p.id_usuario = u.id_usuario AND p.estado = 'Con Mora'
        LEFT JOIN (
            SELECT p2.id_usuario, SUM(m2.monto) AS monto_pendiente
            FROM Prestamo p2
            INNER JOIN Multa m2 ON m2.id_prestamo = p2.id_prestamo
            WHERE m2.estado_pago = 'Pendiente'
            GROUP BY p2.id_usuario
        ) f ON f.id_usuario = u.id_usuario
        GROUP BY u.id_usuario, u.nombres, u.apellidos, u.correo, u.codigo_empleado, u.estado, f.monto_pendiente
        ORDER BY monto_pendiente DESC, u.apellidos ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $topLibros = $conexion->query("
        SELECT mb.titulo, COUNT(DISTINCT p.id_prestamo) AS solicitudes
        FROM Prestamo p
        INNER JOIN Detalle_Prestamo dp ON dp.id_prestamo = p.id_prestamo
        INNER JOIN Ejemplar e ON e.id_ejemplar = dp.id_ejemplar
        INNER JOIN MaterialBibliografico mb ON mb.id_material = e.id_material
        GROUP BY mb.id_material, mb.titulo
        ORDER BY solicitudes DESC, mb.titulo ASC
        LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);

    $categoria = $conexion->query("
        SELECT COALESCE(c.nombre, 'Sin categoría') AS categoria,
               COUNT(DISTINCT p.id_prestamo) AS prestamos
        FROM Prestamo p
        INNER JOIN Detalle_Prestamo dp ON dp.id_prestamo = p.id_prestamo
        INNER JOIN Ejemplar e ON e.id_ejemplar = dp.id_ejemplar
        INNER JOIN MaterialBibliografico mb ON mb.id_material = e.id_material
        LEFT JOIN Categoria c ON c.id_categoria = mb.id_categoria
        GROUP BY c.id_categoria, c.nombre
        ORDER BY prestamos DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    semsad_responder([
        'status' => 'success',
        'periodo' => ['desde' => $desde, 'hasta' => $hasta],
        'resumen' => [
            'prestamos_periodo' => $prestamosPeriodo,
            'devoluciones_periodo' => $devolucionesPeriodo,
            'reservas_pendientes' => $reservasPendientes,
            'reservas_periodo' => $reservasPeriodo,
            'multas_pendientes' => $multasPendientes,
            'monto_pendiente' => round($montoPendiente, 2),
            'multas_pagadas' => $multasPagadasPeriodo,
            'monto_pagado' => $montoPagadoPeriodo,
            'puede_filtrar_pagos_por_fecha' => in_array('fecha_pago', $colsMulta, true),
            'puede_filtrar_reservas_por_fecha' => in_array('fecha_solicitud', $colsReserva, true)
        ],
        'stock_critico' => $stockCritico,
        'morosos' => $morosos,
        'libros_mas_solicitados' => $topLibros,
        'prestamos_por_categoria' => $categoria
    ]);
} catch (Throwable $e) {
    error_log('[SEMSAD] obtener_reportes: ' . $e->getMessage());
    semsad_responder(['status' => 'error', 'mensaje' => 'No se pudieron generar los reportes.'], 500);
}
?>