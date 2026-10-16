<?php
require_once __DIR__ . '/conexion.php';
semsad_requerir_rol(['Administrador', 'Bibliotecario']);

try {
    $columns = $conexion->query("SHOW COLUMNS FROM Multa")->fetchAll(PDO::FETCH_COLUMN, 0);
    $hasPaymentDate = in_array('fecha_pago', $columns, true);
    $hasPaymentMethod = in_array('metodo_pago', $columns, true);

    $extra = '';
    if ($hasPaymentDate) $extra .= ', m.fecha_pago';
    if ($hasPaymentMethod) $extra .= ', m.metodo_pago';

    $sql = "
        SELECT
            m.id_multa, m.id_prestamo, m.id_detalle, m.monto, m.estado_pago, m.tipo_multa,
            p.id_usuario, p.fecha_limite, p.estado AS estado_prestamo,
            u.nombres, u.apellidos, u.correo, u.codigo_empleado, u.telefono, u.estado AS estado_usuario,
            COALESCE(
                (SELECT mb.titulo
                 FROM Detalle_Prestamo dp1
                 INNER JOIN Ejemplar e1 ON e1.id_ejemplar = dp1.id_ejemplar
                 INNER JOIN MaterialBibliografico mb ON mb.id_material = e1.id_material
                 WHERE dp1.id_detalle = m.id_detalle
                 LIMIT 1),
                (SELECT mb2.titulo
                 FROM Detalle_Prestamo dp2
                 INNER JOIN Ejemplar e2 ON e2.id_ejemplar = dp2.id_ejemplar
                 INNER JOIN MaterialBibliografico mb2 ON mb2.id_material = e2.id_material
                 WHERE dp2.id_prestamo = m.id_prestamo
                 ORDER BY dp2.id_detalle
                 LIMIT 1),
                'Libro'
            ) AS titulo_libro
            $extra
        FROM Multa m
        INNER JOIN Prestamo p ON p.id_prestamo = m.id_prestamo
        INNER JOIN Usuario u ON u.id_usuario = p.id_usuario
        ORDER BY CASE WHEN m.estado_pago = 'Pendiente' THEN 0 ELSE 1 END,
                 p.fecha_limite DESC, m.id_multa DESC
    ";
    $rows = $conexion->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    $multas = [];
    $pendientes = 0;
    $montoPendiente = 0.0;
    $pagadas = 0;
    $montoPagado = 0.0;

    foreach ($rows as $r) {
        $pendiente = (string)$r['estado_pago'] === 'Pendiente';
        if ($pendiente) {
            $pendientes++;
            $montoPendiente += (float)$r['monto'];
        } elseif ((string)$r['estado_pago'] === 'Pagado') {
            $pagadas++;
            $montoPagado += (float)$r['monto'];
        }

        $multas[] = [
            'id_multa' => (int)$r['id_multa'],
            'id_prestamo' => (int)$r['id_prestamo'],
            'id_usuario' => (int)$r['id_usuario'],
            'usuario' => trim(($r['nombres'] ?? '') . ' ' . ($r['apellidos'] ?? '')),
            'correo' => (string)($r['correo'] ?? ''),
            'codigo_empleado' => (string)($r['codigo_empleado'] ?? ''),
            'telefono' => (string)($r['telefono'] ?? ''),
            'titulo_libro' => (string)($r['titulo_libro'] ?? 'Libro'),
            'fecha_limite' => !empty($r['fecha_limite']) ? date('d/m/Y', strtotime($r['fecha_limite'])) : 'S/F',
            'dias_mora' => !empty($r['fecha_limite']) && strtotime($r['fecha_limite']) < strtotime(date('Y-m-d'))
                ? max(1, (int)floor((strtotime(date('Y-m-d')) - strtotime(date('Y-m-d', strtotime($r['fecha_limite'])))) / 86400))
                : 0,
            'monto' => round((float)$r['monto'], 2),
            'estado_pago' => (string)$r['estado_pago'],
            'tipo_multa' => (string)($r['tipo_multa'] ?? 'Por Mora'),
            'estado_usuario' => (string)($r['estado_usuario'] ?? ''),
            'fecha_pago' => $hasPaymentDate && !empty($r['fecha_pago']) ? date('d/m/Y H:i', strtotime($r['fecha_pago'])) : '',
            'metodo_pago' => $hasPaymentMethod ? (string)($r['metodo_pago'] ?? '') : ''
        ];
    }

    semsad_responder([
        'status' => 'success',
        'multas' => $multas,
        'resumen' => [
            'total_registros' => count($multas),
            'pendientes' => $pendientes,
            'monto_pendiente' => round($montoPendiente, 2),
            'pagadas' => $pagadas,
            'monto_pagado' => round($montoPagado, 2)
        ],
        'requiere_migracion_pago' => !$hasPaymentDate || !$hasPaymentMethod
    ]);
} catch (Throwable $e) {
    error_log('[SEMSAD] obtener_multas: ' . $e->getMessage());
    semsad_responder(['status' => 'error', 'mensaje' => 'No se pudo consultar el estado de las multas.'], 500);
}
?>