<?php
// ARCHIVO: mis_multas_sirve_para_pagar_en_linea.php
// CAPA: BACKEND (API del servidor)
// SIRVE PARA: mostrar al estudiante sus multas pendientes para pagarlas en línea.
// QUÉ HACE: Solo la sesión del servidor identifica al usuario. Responde sus multas con estado Pendiente
//   y el total a pagar. No recibe ni guarda datos de tarjeta.
// CONECTA CON: principal/index.html (aviso de multas) y principal/pagar_multa.html (pasarela).
require_once __DIR__ . '/usuario_actual_sirve_para_identificar_quien_pide.php';
require_once __DIR__ . '/conexion_sirve_para_conectar_base_datos.php';
semsad_iniciar_sesion();

$yo = semsad_usuario_actual($conexion);
if (!$yo) {
    semsad_responder(['status' => 'error', 'codigo' => 'sin_sesion', 'mensaje' => 'Tu sesión expiró. Inicia sesión nuevamente.'], 401);
}

try {
    $q = $conexion->prepare(
        "SELECT m.id_multa, m.monto, m.estado_pago, p.fecha_limite, p.fecha_salida, p.id_prestamo,
                mb.titulo
           FROM multa m
           INNER JOIN prestamo p ON p.id_prestamo = m.id_prestamo
           LEFT JOIN detalle_prestamo dp ON dp.id_prestamo = p.id_prestamo
           LEFT JOIN ejemplar e ON e.id_ejemplar = dp.id_ejemplar
           LEFT JOIN materialbibliografico mb ON mb.id_material = e.id_material
          WHERE p.id_usuario = :u AND m.estado_pago = 'Pendiente'
          GROUP BY m.id_multa
          ORDER BY m.id_multa DESC"
    );
    $q->execute([':u' => (int)$yo['id_usuario']]);
    $multas = [];
    $total = 0.0;
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $m) {
        $monto = (float)$m['monto'];
        $total += $monto;
        $multas[] = [
            'id_multa' => (int)$m['id_multa'],
            'titulo'   => $m['titulo'] ?: 'Préstamo #' . (int)$m['id_prestamo'],
            'monto'    => round($monto, 2),
            'vencio'   => $m['fecha_limite'] ? date('d/m/Y', strtotime($m['fecha_limite'])) : '',
        ];
    }
    semsad_responder([
        'status'  => 'success',
        'nombre'  => trim(($yo['nombres'] ?? '') . ' ' . ($yo['apellidos'] ?? '')),
        'multas'  => $multas,
        'total'   => round($total, 2),
        'demo'    => !empty($cfg['pagos_demo']),
    ]);
} catch (PDOException $e) {
    error_log('[SEMSAD] mis_multas: ' . $e->getMessage());
    semsad_responder(['status' => 'error', 'mensaje' => 'No se pudieron cargar tus multas.' . semsad_detalle_error($e)], 500);
}
